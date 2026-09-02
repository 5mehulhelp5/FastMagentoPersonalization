<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Doctor;

use Magento\Framework\App\ResourceConnection;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagento\Model\Doctor\Check;
use ParkkTech\FastMagento\Model\Doctor\CheckProviderInterface;
use ParkkTech\FastMagento\Model\Doctor\Diagnostics;

/**
 * The personalisation section of `fastmagento:doctor`.
 *
 * Registered into core's Diagnostics `checkProviders` pool from this module's etc/di.xml, so the
 * section exists exactly when this module is installed and core's doctor has no personalisation
 * knowledge of its own. The check bodies are the ones that lived in core's Diagnostics until the
 * package split; only the entry point was renamed to satisfy CheckProviderInterface.
 *
 * `$diagnostics` is injected as a Proxy (see etc/di.xml): Diagnostics constructs this provider,
 * so a direct injection would be a circular dependency. Only two read-only helpers are used from
 * it — resolveClient() and deployedBundleCarries().
 */
class PersonalizationCheckProvider implements CheckProviderInterface
{
    private const G_PERSONALIZATION = 'Personalisation';


    /** Generous headroom over any observed real AROUND-plugin chain length (see IN-01). */
    private const AROUND_CHAIN_MAX_HOPS = 50;

    public function __construct(
        private readonly Diagnostics $diagnostics,
        private readonly ResourceConnection $resource,
        private readonly OpenSearchConfig $openSearchConfig,
        private readonly \Magento\Framework\FlagManager $flagManager,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Personalization\ProfileRepository $profileRepository,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Personalization\PersonalizationConfig $personalizationConfig,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Personalization\ValueDiscrimination $valueDiscrimination,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Personalization\ProductExposure $productExposure,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Analytics\CaptureMode $captureMode,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Analytics\AbReport $abReport,
        private readonly \Magento\Framework\Config\ScopeInterface $configScope,
        // Deliberately the object manager, not a narrower injected singleton. The interception
        // plugin list (and, in checkPersonalizationCacheWiring(), the event config) caches its
        // resolved data PER CONFIG SCOPE, so a fresh instance must be CREATED after the scope is
        // switched — an injected singleton would have already resolved for whichever area
        // happened to be current at construction time and would silently answer for that area
        // no matter which scope this check later switches to.
        private readonly \Magento\Framework\ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * Is the shopper-profile store in a state that could actually serve?
     *
     * Personalisation ships dark and off, so "disabled" is a correct answer here, not a fault. The
     * failures worth catching are the ones that look like success: building switched on but no
     * index behind it, an index created by an older release whose mapping predates fields this
     * version writes, a backfill that covered a fraction of the customer base and stopped, and
     * serving switched on with nothing to serve from.
     *
     * @return Check[]
     */
    public function check(): array
    {
        $out = [];

        $building = $this->personalizationConfig->isBuildingProfiles();
        $applying = $this->personalizationConfig->isApplied();

        if (!$building) {
            // Applying without building is the one combination that is actively broken: the
            // serving path would read profiles nothing is keeping up to date.
            if ($applying) {
                $out[] = Check::fail(
                    self::G_PERSONALIZATION,
                    'Build switch',
                    'Personalisation is APPLIED but profiles are not being BUILT',
                    'Profiles will go stale and new shoppers will never get one. Enable FastMagento > '
                    . 'Personalisation > Build Shopper Profiles, or turn off Enable Personalisation.'
                );

                // Serving is live in this state — the request-layer plugin and the cache-context
                // guard are exercised on the real storefront regardless of the build switch, so
                // wiring must still be checked here rather than skipped along with the rest of
                // this early return (see REVIEW.md CR-01).
                $out = array_merge($out, $this->checkPersonalizationWiring());
                $out = array_merge($out, $this->checkPersonalizationCacheWiring());
                $out = array_merge($out, $this->checkPersonalizationLinkWiring());
                $out = array_merge($out, $this->checkPersonalizationGraphqlWiring());
                $out = array_merge($out, $this->checkExplorationWiring());

                return $out;
            }

            $out[] = Check::skip(
                self::G_PERSONALIZATION,
                'Profile building',
                'Disabled — no profiles are being built (this is the default)'
            );

            return $out;
        }

        // --- index exists -------------------------------------------------------------------
        if (!$this->profileRepository->indexExists()) {
            $out[] = Check::fail(
                self::G_PERSONALIZATION,
                'Profile index',
                'Profile building is on but the index does not exist',
                'Run: bin/magento fastmagento:profile:backfill'
            );

            // Serving may still be live in this state — applying is independent of whether the
            // index has ever been built — so wiring must still be checked here rather than
            // skipped along with the rest of this early return (same reasoning as the
            // !$building && $applying branch above, see REVIEW.md CR-01).
            $out = array_merge($out, $this->checkPersonalizationWiring());
            $out = array_merge($out, $this->checkPersonalizationCacheWiring());
            $out = array_merge($out, $this->checkPersonalizationLinkWiring());
            $out = array_merge($out, $this->checkPersonalizationGraphqlWiring());
            $out = array_merge($out, $this->checkExplorationWiring());

            return $out;
        }

        $indexName = $this->openSearchConfig->getUserProfileIndexName();
        $profiles = $this->profileRepository->count();
        $out[] = Check::ok(
            self::G_PERSONALIZATION,
            'Profile index',
            sprintf('%s (%d profiles)', $indexName, $profiles)
        );

        // --- request-layer wiring: is the Mapper::buildQuery() plugin actually active, per area?
        $out = array_merge($out, $this->checkPersonalizationWiring());

        // --- cache-layer wiring: the FPC fork and the block-cache-key signature ---------------
        $out = array_merge($out, $this->checkPersonalizationCacheWiring());

        // --- link-block wiring: the related/up-sell/cross-sell surface (single-area, frontend) --
        $out = array_merge($out, $this->checkPersonalizationLinkWiring());

        // --- graphql wiring: query-decoration + identity-context, both graphql-scoped ----------
        $out = array_merge($out, $this->checkPersonalizationGraphqlWiring());

        // --- exploration wiring: the response-side slot plugin, both areas ---------------------
        $out = array_merge($out, $this->checkExplorationWiring());

        // --- mapping current ----------------------------------------------------------------
        $live = $this->profileRepository->getLiveMapping();
        if ($live === null) {
            $out[] = Check::warn(
                self::G_PERSONALIZATION,
                'Profile mapping',
                'Index exists but its mapping could not be read',
                'Usually a permissions issue on the cluster. Check the OpenSearch user can read '
                . 'index mappings.'
            );
        } else {
            $expectedFields = array_keys($this->profileRepository->getExpectedMapping()['mappings']['properties'] ?? []);
            $liveFields = array_keys($live['properties'] ?? []);
            $missing = array_values(array_diff($expectedFields, $liveFields));

            if ($missing) {
                // OpenSearch never retrofits fields into an existing mapping, so an index created
                // by an older release keeps its original shape indefinitely. Nothing errors — the
                // new fields are simply stored unindexed or dropped.
                $out[] = Check::warn(
                    self::G_PERSONALIZATION,
                    'Profile mapping',
                    sprintf('Mapping predates this version — missing: %s', implode(', ', $missing)),
                    'The index was created by an older release. Rebuild it: delete the index, then '
                    . 'run bin/magento fastmagento:profile:backfill'
                );
            } else {
                $out[] = Check::ok(
                    self::G_PERSONALIZATION,
                    'Profile mapping',
                    sprintf('current (%d fields)', count($liveFields))
                );
            }
        }

        // --- count sane ---------------------------------------------------------------------
        $candidates = (int) $this->resource->getConnection()->fetchOne(
            $this->resource->getConnection()->select()
                ->from(
                    $this->resource->getTableName('sales_order'),
                    ['n' => new \Zend_Db_Expr('COUNT(DISTINCT customer_id)')]
                )
                ->where('customer_id IS NOT NULL')
        );

        // Coverage compares like with like: the guest tier lives in the same index but answers a
        // different question, so anonymous profiles are excluded here or a store with active
        // guests would "cover" its customers without a single customer profile existing.
        $anonForCoverage = $this->profileRepository->countAnonymous();
        if ($anonForCoverage !== null) {
            $profiles = max(0, $profiles - $anonForCoverage);
        }

        if ($candidates === 0) {
            $out[] = Check::skip(
                self::G_PERSONALIZATION,
                'Profile coverage',
                'No customer has ordered yet — nothing to profile'
            );
        } elseif ($profiles === 0) {
            $out[] = Check::fail(
                self::G_PERSONALIZATION,
                'Profile coverage',
                sprintf('Index is EMPTY but %d customer(s) have order history', $candidates),
                'Run: bin/magento fastmagento:profile:backfill'
            );
        } elseif ($profiles < (int) ceil($candidates * 0.5)) {
            // Not every shopper yields a profile — someone whose orders carry none of the profiled
            // attributes is correctly skipped — so a shortfall is only worth flagging when it is
            // large enough to look like a backfill that stopped rather than one that filtered.
            $out[] = Check::warn(
                self::G_PERSONALIZATION,
                'Profile coverage',
                sprintf('%d profile(s) for %d customer(s) with order history', $profiles, $candidates),
                'Either a backfill was interrupted, or most orders carry none of the profiled '
                . 'attributes. Resume with: bin/magento fastmagento:profile:backfill --resume, and '
                . 'check coverage with bin/magento fastmagento:profile:inspect --customer=<id>'
            );
        } else {
            $out[] = Check::ok(
                self::G_PERSONALIZATION,
                'Profile coverage',
                sprintf('%d profile(s) for %d customer(s) with order history', $profiles, $candidates)
            );
        }

        // --- boosts can MATCH: affinities carry the ids the search index actually holds -------
        $sample = $this->sampleProfileAffinities();
        if ($sample === null) {
            $out[] = Check::skip(
                self::G_PERSONALIZATION,
                'Affinity option ids',
                'No profile to inspect yet'
            );
        } else {
            $missing = [];
            foreach ($sample as $code => $affinity) {
                // `category` resolves against the category tree, the rest against the option table;
                // either way a stored affinity with values but no ids cannot be matched.
                if (!empty($affinity['values']) && empty($affinity['value_ids'])) {
                    $missing[] = $code;
                }
            }

            if ($missing) {
                $out[] = Check::fail(
                    self::G_PERSONALIZATION,
                    'Affinity option ids',
                    sprintf('Affinities carry labels but no ids: %s', implode(', ', $missing)),
                    'The search index stores EAV option ids, not labels, so a boost built from '
                    . 'these would match nothing and fail silently. Rebuild the profiles: '
                    . 'bin/magento fastmagento:profile:backfill --restart'
                );
            } else {
                $out[] = Check::ok(
                    self::G_PERSONALIZATION,
                    'Affinity option ids',
                    sprintf('resolved for %s', implode(', ', array_keys($sample)))
                );
            }
        }

        // --- boosts can MATTER: the catalogue-side discrimination table -----------------------
        if (!$this->valueDiscrimination->isAvailable(
            \ParkkTech\FastMagentoPersonalization\Model\Personalization\ValueDiscrimination::TARGET_NATIVE
        )) {
            $out[] = Check::fail(
                self::G_PERSONALIZATION,
                'Discrimination table',
                'Not measured — boosts cannot be gated against the catalogue',
                'Without it a preference on a value carried by most of the catalogue produces a '
                . 'boost that reorders nothing while reporting success. Run: '
                . 'bin/magento fastmagento:personalization:discrimination'
            );
        } else {
            $builtAt = $this->valueDiscrimination->getBuiltAt();
            $reindexedAt = $this->lastFulltextReindexAt();

            if ($builtAt !== null && $reindexedAt !== null && strtotime($builtAt) < strtotime($reindexedAt)) {
                $out[] = Check::warn(
                    self::G_PERSONALIZATION,
                    'Discrimination table',
                    sprintf('Measured %s, but the catalogue was reindexed %s', $builtAt, $reindexedAt),
                    'The catalogue changed after this was measured, so the shares it gates on are '
                    . 'stale. Re-run: bin/magento fastmagento:personalization:discrimination'
                );
            } else {
                $out[] = Check::ok(
                    self::G_PERSONALIZATION,
                    'Discrimination table',
                    sprintf(
                        '%d listing doc(s) / %d search doc(s), measured %s',
                        $this->valueDiscrimination->getTotalDocs(
                            \ParkkTech\FastMagentoPersonalization\Model\Personalization\ValueDiscrimination::TARGET_NATIVE
                        ),
                        $this->valueDiscrimination->getTotalDocs(
                            \ParkkTech\FastMagentoPersonalization\Model\Personalization\ValueDiscrimination::TARGET_SERVING
                        ),
                        (string) $builtAt
                    )
                );
            }
        }

        // --- stated signals: searches and facet selections ------------------------------------
        if (!$this->personalizationConfig->isCollectingEvents()) {
            $out[] = Check::skip(
                self::G_PERSONALIZATION,
                'Stated signals',
                'Not recording searches or facet selections'
            );
        } else {
            $events = $this->countEvents();
            if ($events === null) {
                $out[] = Check::warn(
                    self::G_PERSONALIZATION,
                    'Stated signals',
                    'Recording is on but no event index exists yet',
                    'Normal on a store where nobody has searched since this was enabled. If '
                    . 'shoppers ARE searching, the writes are failing — check the OpenSearch log '
                    . 'and that the cluster accepts writes.'
                );
            } else {
                $out[] = Check::ok(
                    self::G_PERSONALIZATION,
                    'Stated signals',
                    sprintf('%d search/facet event(s) recorded', $events)
                );
            }
        }

        // --- stated requirements: the fact -> attribute mapping --------------------------------
        // The seam where a shopper telling the store what they need turns into ranking, and it is
        // one a merchant can silently get wrong in two ways: never mapping the shape at all, or
        // mapping it to an attribute nothing has measured. Both look identical from the storefront
        // — a requirement that changes nothing — so the doctor says which.
        $factAttributes = $this->personalizationConfig->getFactAttributes();
        if (!$factAttributes) {
            $out[] = Check::skip(
                self::G_PERSONALIZATION,
                'Stated requirements',
                'No fact attributes mapped — a requirement a shopper states is recorded and shown, but ranks nothing'
            );
        } else {
            $unmeasured = [];
            foreach ($factAttributes as $code) {
                if (!$this->valueDiscrimination->describe(
                    $code,
                    \ParkkTech\FastMagentoPersonalization\Model\Personalization\ValueDiscrimination::TARGET_NATIVE
                )) {
                    $unmeasured[] = $code;
                }
            }

            $mapping = [];
            foreach ($factAttributes as $shape => $code) {
                $mapping[] = $shape . ' → ' . $code;
            }

            if ($unmeasured) {
                $out[] = Check::warn(
                    self::G_PERSONALIZATION,
                    'Stated requirements',
                    sprintf(
                        '%s — but %s never measured',
                        implode(', ', $mapping),
                        implode(', ', $unmeasured)
                    ),
                    'A fact only ranks once its attribute is in the discrimination table, so this '
                    . 'mapping does nothing today. Measure it: bin/magento '
                    . 'fastmagento:personalization:discrimination --attributes=color,size,category,'
                    . implode(',', $unmeasured)
                );
            } else {
                $out[] = Check::ok(
                    self::G_PERSONALIZATION,
                    'Stated requirements',
                    implode(', ', $mapping) . ' — measured, ranks as a boost'
                );
            }
        }

        // --- who reports a facet selection -----------------------------------------------------
        // The failure this catches is total and silent. With a full-page cache in front, PHP never
        // sees a filtered listing, so the browser bundle is the ONLY thing reporting the strongest
        // signal this module collects — and that bundle is a DEPLOYED asset, which this codebase
        // has a documented history of forgetting to redeploy (setup:static-content:deploy -f does
        // not overwrite). Deploy it stale and facet capture stops completely, with every other
        // check still green.
        if (!$this->personalizationConfig->isCollectingEvents()) {
            $out[] = Check::skip(self::G_PERSONALIZATION, 'Facet capture', 'Not recording stated signals');
        } elseif (!$this->captureMode->isBrowserOwned()) {
            $out[] = Check::ok(
                self::G_PERSONALIZATION,
                'Facet capture',
                'server-side — no full-page cache, so PHP sees every listing request'
            );
        } else {
            $deployed = $this->diagnostics->deployedBundleCarries(
                'initFacetTracking',
                'ParkkTech_FastMagentoPersonalization/js/fastmagento-personalization.js'
            );
            if ($deployed === null) {
                $out[] = Check::warn(
                    self::G_PERSONALIZATION,
                    'Facet capture',
                    'browser-side (full-page cache is on) — but the deployed bundle could not be read',
                    'Facet selections are reported by pub/static .../ParkkTech_FastMagentoPersonalization/js/'
                    . 'fastmagento-personalization.js. If it is missing, nothing is recording them.'
                );
            } elseif ($deployed) {
                $out[] = Check::ok(
                    self::G_PERSONALIZATION,
                    'Facet capture',
                    'browser-side (full-page cache is on) — the deployed bundle reports selections'
                );
            } else {
                $out[] = Check::fail(
                    self::G_PERSONALIZATION,
                    'Facet capture',
                    'browser-side is required (full-page cache is on) but the DEPLOYED bundle does '
                    . 'not report facet selections — they are being lost',
                    'The deployed copy is stale. Delete it first, because -f does not overwrite: '
                    . 'rm pub/static/frontend/*/*/*/ParkkTech_FastMagentoPersonalization/js/'
                    . 'fastmagento-personalization.js && '
                    . 'bin/magento setup:static-content:deploy -f -a frontend '
                    . '--exclude-theme Swissup/breeze-blank'
                );
            }
        }

        // --- exposure table: the denominator ---------------------------------------------------
        // Absent is not a fault — it needs impressions, which need the storefront bundle and some
        // traffic. What IS worth saying is which state the store is in, because "no exploration
        // candidates" and "no measurement at all" look identical from the outside.
        if (!$this->productExposure->isAvailable()) {
            $out[] = Check::skip(
                self::G_PERSONALIZATION,
                'Exposure table',
                'Not measured — conversion per impression is unavailable, so nothing can tell a '
                . 'product that never sold from one that was never shown'
            );
        } else {
            $exposure = $this->productExposure->summary() ?? [];
            $unlisted = (int) ($exposure['unlisted_impressions'] ?? 0);
            $message = sprintf(
                '%d product(s), %d judged (>= %d impressions), measured %s',
                (int) ($exposure['products'] ?? 0),
                (int) ($exposure['judged'] ?? 0),
                \ParkkTech\FastMagentoPersonalization\Model\Personalization\ProductExposure::MIN_IMPRESSIONS,
                (string) ($exposure['built_at'] ?? '')
            );

            if ($unlisted > 0) {
                $out[] = Check::warn(
                    self::G_PERSONALIZATION,
                    'Exposure table',
                    $message . sprintf('; %d impression(s) not counted', $unlisted),
                    'The impression aggregation hit its bucket limit, so some products look '
                    . 'never-shown when they were shown. Re-run with a higher limit: bin/magento '
                    . 'fastmagento:personalization:exposure --limit=20000'
                );
            } else {
                $out[] = Check::ok(self::G_PERSONALIZATION, 'Exposure table', $message);
            }
        }

        // --- the refresh heartbeat -------------------------------------------------------------
        // Profiles describe shoppers as of their last rebuild. Before the cron existed that meant
        // "as of whenever somebody last ran the CLI", and every other check stayed green while the
        // answers went stale — so freshness gets its own line, and silence gets a warning.
        $lastRefresh = (string) ($this->flagManager->getFlagData('fastmagento_personalization_refresh_last_run') ?: '');
        if ($lastRefresh === '') {
            $out[] = Check::warn(
                self::G_PERSONALIZATION,
                'Profile freshness',
                'The hourly refresh job has never run',
                'Profiles only change when rebuilt, so events collected since the last manual '
                . 'backfill are shaping nothing. Check that Magento cron is running at all '
                . '(bin/magento cron:run); the job is fastmagento_personalization_refresh.'
            );
        } elseif (strtotime($lastRefresh) < strtotime('-3 hours')) {
            $out[] = Check::warn(
                self::G_PERSONALIZATION,
                'Profile freshness',
                sprintf('Last refreshed %s — more than three hours for an hourly job', $lastRefresh),
                'Magento cron has likely stalled. Check crontab and var/log/cron.log.'
            );
        } else {
            $out[] = Check::ok(
                self::G_PERSONALIZATION,
                'Profile freshness',
                sprintf('refresh ran %s', $lastRefresh)
            );
        }

        // --- the guest tier --------------------------------------------------------------------
        $anonProfiles = $this->profileRepository->countAnonymous();
        if ($anonProfiles !== null) {
            $out[] = Check::ok(
                self::G_PERSONALIZATION,
                'Guest tier',
                $anonProfiles > 0
                    ? sprintf('%d anonymous profile(s) — guests with history are served like shoppers, not strangers', $anonProfiles)
                    : 'no anonymous profiles yet — normal until guests have searched or filtered enough to profile'
            );
        }

        // --- the exploration slot --------------------------------------------------------------
        $explorationPct = $this->personalizationConfig->getExplorationPercent();
        if ($applying && $explorationPct > 0.0) {
            if (!$this->productExposure->isAvailable()) {
                $out[] = Check::warn(
                    self::G_PERSONALIZATION,
                    'Exploration slot',
                    sprintf('dialled to %.0f%% but the exposure table is not measured — the slot is empty', $explorationPct),
                    'The slot chooses candidates by impression count, so without the table it '
                    . 'cannot tell "never shown" from "unknown" and correctly does nothing. It '
                    . 'fills itself once impressions arrive and the hourly refresh has run, or '
                    . 'immediately via: bin/magento fastmagento:personalization:exposure'
                );
            } else {
                $exposureSummary = $this->productExposure->summary() ?? [];
                $below = (int) ($exposureSummary['products'] ?? 0) - (int) ($exposureSummary['judged'] ?? 0);
                $out[] = Check::ok(
                    self::G_PERSONALIZATION,
                    'Exploration slot',
                    sprintf(
                        '%.0f%% of page one; %d shown product(s) still below the exposure floor '
                        . '(never-shown products qualify too)',
                        $explorationPct,
                        max(0, $below)
                    )
                );
            }
        } elseif ($applying) {
            $out[] = Check::skip(self::G_PERSONALIZATION, 'Exploration slot', 'dialled to 0 — new products earn exposure only by ranking');
        }

        // --- the A/B test and the weight dial --------------------------------------------------
        if ($applying) {
            $mode = $this->personalizationConfig->getWeightMode();
            $factor = $this->personalizationConfig->getWeightFactor();

            if ($this->personalizationConfig->isAbTestEnabled()) {
                $abSummary = $this->abReport->summary(7);
                $p = (int) ($abSummary['arms']['personalized']['sessions'] ?? 0);
                $c = (int) ($abSummary['arms']['control']['sessions'] ?? 0);
                $total = $p + $c;

                // A split that drifts far from half is not a smaller experiment, it is a broken
                // randomiser — and every conclusion drawn downstream of one is suspect.
                if ($total >= 100 && ($p < $total * 0.4 || $c < $total * 0.4)) {
                    $out[] = Check::warn(
                        self::G_PERSONALIZATION,
                        'A/B test',
                        sprintf('the split is unbalanced — %d shoppers with personalisation vs %d without (7d)', $p, $c),
                        'Assignment hashes the analytics cookie, so a skew this size means something '
                        . 'is interfering with the cookie (a consent tool, a CDN stripping it) '
                        . 'rather than bad luck.'
                    );
                } else {
                    $out[] = Check::ok(
                        self::G_PERSONALIZATION,
                        'A/B test',
                        sprintf('running — %d shoppers with personalisation vs %d without (7d)', $p, $c)
                    );
                }
            } else {
                $out[] = Check::skip(
                    self::G_PERSONALIZATION,
                    'A/B test',
                    $mode === 'auto'
                        ? 'OFF — but weight is Auto, which needs the control to tune against; the tuner is holding'
                        : 'off — personalisation applies to everyone, and its effect cannot be measured'
                );
            }

            $out[] = Check::ok(
                self::G_PERSONALIZATION,
                'Weight',
                sprintf('%s (%.2f×)%s', ucfirst($mode), $factor, $mode === 'auto' ? ' — tuner-owned, see the dashboard' : '')
            );
        }

        // --- dark-ship reminder --------------------------------------------------------------
        if (!$applying) {
            $out[] = Check::skip(
                self::G_PERSONALIZATION,
                'Personalisation serving',
                'Profiles are being built but not applied — nothing reads them at query time'
            );
        }

        return $out;
    }

    /**
     * Is `Mapper::buildQuery()` actually re-ranking, in each area that ships the plugin?
     *
     * The plugin is registered per AREA (`etc/frontend/di.xml`, `etc/graphql/di.xml`), not once
     * globally, and that split has already cost this project real time: a plugin declared only
     * under `etc/frontend/` is not loaded for a GraphQL request, so the storefront personalised
     * and the API silently did not (ENVIRONMENT.md's "Areas matter" note). Reading the resolved
     * DI configuration is the only way to catch that structurally — a missing di.xml entry, a
     * failed `setup:di:compile`, or a third-party module re-declaring the same plugin name would
     * all read as healthy from source alone.
     *
     * @return Check[]
     */
    private function checkPersonalizationWiring(): array
    {
        if (!$this->personalizationConfig->isApplied()) {
            return [Check::skip(
                self::G_PERSONALIZATION,
                'Serving wiring',
                'Serving is off — the request-layer plugin is not exercised'
            )];
        }

        $pluginName = 'parkktech_fastmagento_personalize_search_request';
        $mapperType = \Magento\OpenSearch\SearchAdapter\Mapper::class;
        $pluginType = \ParkkTech\FastMagentoPersonalization\Plugin\OpenSearch\PersonalizeSearchRequest::class;

        $out = [];
        $saved = $this->configScope->getCurrentScope();
        try {
            foreach (['frontend', 'graphql'] as $area) {
                // A fresh PluginList instance is required AFTER setCurrentScope() — the list
                // caches per scope, so an instance resolved before the switch would answer for
                // the PREVIOUS area.
                $this->configScope->setCurrentScope($area);
                $pluginList = $this->objectManager->create(
                    \Magento\Framework\Interception\PluginListInterface::class
                );
                $next = $pluginList->getNext($mapperType, 'buildQuery');
                $after = $next[\Magento\Framework\Interception\DefinitionInterface::LISTENER_AFTER] ?? [];
                $present = in_array($pluginName, $after, true);

                // Resolve the code to an actual instance rather than trusting the name: a
                // third-party module re-declaring the same plugin name would otherwise read as
                // healthy here.
                $healthy = $present
                    && ($pluginList->getPlugin($mapperType, $pluginName) instanceof $pluginType);

                if ($healthy) {
                    $out[] = Check::ok(
                        self::G_PERSONALIZATION,
                        sprintf('Request plugin (%s)', $area),
                        'PersonalizeSearchRequest is wired into Mapper::buildQuery()'
                    );
                } elseif ($area === 'frontend') {
                    $out[] = Check::fail(
                        self::G_PERSONALIZATION,
                        sprintf('Request plugin (%s)', $area),
                        'PersonalizeSearchRequest is NOT wired into Mapper::buildQuery() in the frontend area',
                        'The storefront will not personalise at all. Confirm the module source was '
                        . 'rsynced into vendor/parkktech/fastmagento/, then run: '
                        . 'bin/magento setup:di:compile'
                    );
                } else {
                    $out[] = Check::warn(
                        self::G_PERSONALIZATION,
                        sprintf('Request plugin (%s)', $area),
                        'PersonalizeSearchRequest is NOT wired into Mapper::buildQuery() in the graphql area',
                        'The storefront will personalise while GraphQL silently will not — a plugin '
                        . 'declared only under etc/frontend/ is not loaded for the graphql area. '
                        . 'Confirm etc/graphql/di.xml still declares the plugin, then run: '
                        . 'bin/magento setup:di:compile'
                    );
                }
            }
        } catch (\Throwable $e) {
            // A diagnostic must never be the thing that breaks the command — same rule checkPlp()
            // follows for an unreadable DI config. Accumulate onto whatever was already gathered
            // (e.g. the frontend-area result) rather than discarding it (see REVIEW.md WR-01).
            $out[] = Check::skip(self::G_PERSONALIZATION, 'Serving wiring', $e->getMessage());
            return $out;
        } finally {
            // Leaving the config scope switched would poison every check that runs after this
            // one — restore it unconditionally, success or exception.
            $this->configScope->setCurrentScope($saved);
        }

        return $out;
    }

    /**
     * Are the two registrations that keep the full-page and block caches from leaking one
     * shopper's personalised page to another still in place?
     *
     * Both are silent breaks with no visible symptom until measured: two different logged-in
     * customers on this store produce an IDENTICAL `X-Magento-Vary`, so without the observer the
     * first personalised page rendered would be served verbatim to every other shopper in that
     * customer group (docs/CONSTRAINTS.md, "Full-page cache route for personalisation"). Without
     * the block plugin the same leak repeats one layer down — measured before it existed: four
     * shoppers with four distinct `X-Magento-Vary` values all received the FIRST one's
     * preselected configurable variant.
     *
     * A live segment-cardinality watch (a third gap 06-RESEARCH.md raised) is deliberately NOT a
     * check here — cardinality is a fact about traffic over time, not a wired-or-broken state, and
     * the personalisation dashboard already reads the events index for exactly that question. See
     * "Criterion 4" in docs/M2-ACCEPTANCE.md for the recorded decision.
     *
     * @return Check[]
     */
    private function checkPersonalizationCacheWiring(): array
    {
        if (!$this->personalizationConfig->isApplied()) {
            return [Check::skip(
                self::G_PERSONALIZATION,
                'Cache wiring',
                'Serving is off — the cache-context observer and block cache-key plugins are not exercised'
            )];
        }

        $out = [];
        $saved = $this->configScope->getCurrentScope();
        try {
            $this->configScope->setCurrentScope('frontend');

            // (a) Observer registration — the page-cache fork.
            $eventConfig = $this->objectManager->create(\Magento\Framework\Event\ConfigInterface::class);
            $observers = $eventConfig->getObservers('controller_action_predispatch');
            $observer = $observers['fastmagento_personalization_cache_context'] ?? null;
            $observerHealthy = is_array($observer)
                && ltrim((string) ($observer['instance'] ?? ''), '\\')
                    === ltrim(\ParkkTech\FastMagentoPersonalization\Observer\PersonalizationCacheContext::class, '\\');

            $out[] = $observerHealthy
                ? Check::ok(
                    self::G_PERSONALIZATION,
                    'Page cache fork',
                    'PersonalizationCacheContext observer is registered'
                )
                : Check::fail(
                    self::G_PERSONALIZATION,
                    'Page cache fork',
                    'PersonalizationCacheContext observer is NOT registered on '
                    . 'controller_action_predispatch — two shoppers in the same customer group would '
                    . 'share one full-page-cache entry, so the first personalised page rendered is '
                    . 'served to everyone else in that group',
                    'Confirm etc/frontend/events.xml still declares '
                    . 'fastmagento_personalization_cache_context, rsync the module source into '
                    . 'vendor/parkktech/fastmagento/, then run: bin/magento cache:flush'
                );

            // (b) Block cache-key plugins — both classes, not just one. Magento applies a plugin
            // to the class it names, not to its subclasses, and the swatch renderer is the class
            // actually instantiated when swatches are in use.
            $pluginName = 'parkktech_fastmagento_personalized_block_cache_key';
            $swatchPluginName = 'parkktech_fastmagento_personalized_block_cache_key_swatches';
            $pluginType = \ParkkTech\FastMagentoPersonalization\Plugin\PersonalizedBlockCacheKeyPlugin::class;

            $blockTargets = [
                'ConfigurableProduct block' => [
                    \Magento\ConfigurableProduct\Block\Product\View\Type\Configurable::class,
                    [$pluginName],
                ],
                'Swatches renderer' => [
                    \Magento\Swatches\Block\Product\Renderer\Configurable::class,
                    [$pluginName, $swatchPluginName],
                ],
            ];

            $pluginList = $this->objectManager->create(
                \Magento\Framework\Interception\PluginListInterface::class
            );

            foreach ($blockTargets as $label => [$blockClass, $acceptableCodes]) {
                $next = $pluginList->getNext($blockClass, 'getCacheKeyInfo');
                $after = $next[\Magento\Framework\Interception\DefinitionInterface::LISTENER_AFTER] ?? [];

                $healthy = false;
                foreach ($acceptableCodes as $code) {
                    if (in_array($code, $after, true)
                        && ($pluginList->getPlugin($blockClass, $code) instanceof $pluginType)
                    ) {
                        $healthy = true;
                        break;
                    }
                }

                $out[] = $healthy
                    ? Check::ok(
                        self::G_PERSONALIZATION,
                        sprintf('Block cache key (%s)', $label),
                        'PersonalizedBlockCacheKeyPlugin is wired into getCacheKeyInfo()'
                    )
                    : Check::fail(
                        self::G_PERSONALIZATION,
                        sprintf('Block cache key (%s)', $label),
                        sprintf(
                            'PersonalizedBlockCacheKeyPlugin is NOT wired into %s::getCacheKeyInfo() — '
                            . 'four shoppers with four distinct X-Magento-Vary values would all receive '
                            . 'the first one\'s preselected variant',
                            $blockClass
                        ),
                        'Confirm etc/frontend/di.xml still declares the plugin on this class, rsync the '
                        . 'module source into vendor/parkktech/fastmagento/, then run: '
                        . 'bin/magento setup:di:compile'
                    );
            }
        } catch (\Throwable $e) {
            // Accumulate onto whatever was already gathered rather than discarding it (see
            // REVIEW.md WR-01).
            $out[] = Check::skip(self::G_PERSONALIZATION, 'Cache wiring', $e->getMessage());
            return $out;
        } finally {
            $this->configScope->setCurrentScope($saved);
        }

        return $out;
    }

    /**
     * Is the related/up-sell/cross-sell link-block surface actually served from OpenSearch?
     *
     * `LinkProductCollectionPlugin::aroundLoad()` is declared once in `etc/di.xml` — area-
     * unguarded in DI, self-gating on `AppState::getAreaCode() !== 'frontend'` at runtime — so
     * unlike the two-area request/graphql wiring above, this is a single-area (frontend) DI
     * presence check, not a loop. A missing or mis-resolved plugin here means every related /
     * up-sell / cross-sell block on the storefront silently falls back to the native EAV load: no
     * error, no visible symptom, just a page costing far more queries than it should. A missing
     * di.xml entry, a failed `setup:di:compile`, or a third-party module re-declaring the same
     * plugin name would all read as healthy from source alone.
     *
     * @return Check[]
     */
    private function checkPersonalizationLinkWiring(): array
    {
        if (!$this->personalizationConfig->isApplied()) {
            return [Check::skip(
                self::G_PERSONALIZATION,
                'Link-block wiring',
                'Serving is off — the link-block (related/up-sell/cross-sell) plugin is not exercised'
            )];
        }

        $pluginName = 'fastmagento_link_product_collection';
        $collectionType = \Magento\Catalog\Model\ResourceModel\Product\Link\Product\Collection::class;
        $pluginType = \ParkkTech\FastMagento\Plugin\LinkProductCollectionPlugin::class;

        $out = [];
        $saved = $this->configScope->getCurrentScope();
        try {
            // A fresh PluginList instance is required AFTER setCurrentScope() — the list caches
            // per scope, matching the constructor's $objectManager note.
            $this->configScope->setCurrentScope('frontend');
            $pluginList = $this->objectManager->create(
                \Magento\Framework\Interception\PluginListInterface::class
            );

            // The plugin method is aroundLoad(), so the introspection key is LISTENER_AROUND —
            // NOT the LISTENER_AFTER the request-plugin analog above uses for its afterBuildQuery.
            // Unlike BEFORE/AFTER (which PluginList::getNext() returns as a flat array of every
            // plugin code at that position), AROUND is a CHAIN: getNext() returns only the single
            // next-in-chain plugin CODE (a string) for whichever $code position you ask about, not
            // a list — confirmed live (in_array() against it throws a TypeError). Walk the chain
            // from '__self' following each AROUND code until the target is found or the chain ends.
            $present = false;
            $code = '__self';
            for ($hop = 0; $hop < self::AROUND_CHAIN_MAX_HOPS; $hop++) {
                $next = $pluginList->getNext($collectionType, 'load', $code);
                if ($next === null) {
                    break;
                }
                $aroundNext = $next[\Magento\Framework\Interception\DefinitionInterface::LISTENER_AROUND] ?? null;
                if ($aroundNext === null) {
                    break;
                }
                if ($aroundNext === $pluginName) {
                    $present = true;
                    break;
                }
                $code = $aroundNext;
            }

            // Resolve to an actual instance rather than trusting the name — a third-party module
            // re-declaring the same plugin name would otherwise read as healthy here.
            $healthy = $present
                && ($pluginList->getPlugin($collectionType, $pluginName) instanceof $pluginType);

            $out[] = $healthy
                ? Check::ok(
                    self::G_PERSONALIZATION,
                    'Link-block wiring',
                    'LinkProductCollectionPlugin is wired into Collection::load()'
                )
                : Check::fail(
                    self::G_PERSONALIZATION,
                    'Link-block wiring',
                    'LinkProductCollectionPlugin is NOT wired into '
                    . 'Product\Link\Product\Collection::load() — related/up-sell/cross-sell blocks '
                    . 'silently fall back to the native EAV load',
                    'Confirm etc/frontend/di.xml still declares fastmagento_link_product_collection, '
                    . 'rsync the module source into vendor/parkktech/fastmagento/, then run: '
                    . 'bin/magento setup:di:compile'
                );
        } catch (\Throwable $e) {
            // A diagnostic must never be the thing that breaks the command — accumulate onto
            // whatever was already gathered rather than discarding it (see REVIEW.md WR-01).
            $out[] = Check::skip(self::G_PERSONALIZATION, 'Link-block wiring', $e->getMessage());
            return $out;
        } finally {
            // Leaving the config scope switched would poison every check that runs after this one.
            $this->configScope->setCurrentScope($saved);
        }

        return $out;
    }

    /**
     * Is GraphQL's own query-decoration AND identity-resolution wiring actually active?
     *
     * GraphQL runs in its own DI area: a plugin declared only under `etc/frontend/` is simply not
     * loaded for a GraphQL request, which is why the module's first attempt personalised the
     * storefront and silently did nothing over the API. Two independent registrations are checked
     * under the graphql scope, both resolved off one freshly-created `PluginListInterface`:
     *
     * 1. The own-area query-decoration plugin
     *    (`parkktech_fastmagento_personalize_search_request` on
     *    `Magento\OpenSearch\SearchAdapter\Mapper::buildQuery()`, declared in
     *    `etc/graphql/di.xml`) — the exact historical bug above.
     * 2. The identity plugin (`parkktech_fastmagento_personalization_graphql_context` on
     *    `Magento\GraphQl\Model\Query\ContextFactory::create()`, declared in the GLOBAL
     *    `etc/di.xml`, not `etc/graphql/di.xml`) — GraphQL authenticates by token, not session, so
     *    the storefront's pre-dispatch capture finds nobody here; this is where GraphQL's identity
     *    bugs live.
     *
     * Both sub-checks FAIL (not warn) when broken. This deliberately differs from
     * `checkPersonalizationWiring()`'s graphql branch, which shares a dispatcher with the frontend
     * check (already failing there) and so only warns for graphql — a DEDICATED per-surface check
     * exists precisely so a failure names the broken surface (SURF-05/D-06).
     *
     * @return Check[]
     */
    private function checkPersonalizationGraphqlWiring(): array
    {
        if (!$this->personalizationConfig->isApplied()) {
            return [Check::skip(
                self::G_PERSONALIZATION,
                'GraphQL wiring',
                'Serving is off — the GraphQL query-decoration and identity plugins are not exercised'
            )];
        }

        $out = [];
        $saved = $this->configScope->getCurrentScope();
        try {
            // A fresh PluginList instance is required AFTER setCurrentScope() — the list caches
            // per scope; both sub-checks below share this one instance.
            $this->configScope->setCurrentScope('graphql');
            $pluginList = $this->objectManager->create(
                \Magento\Framework\Interception\PluginListInterface::class
            );

            // Sub-check 1: query-decoration wiring — the own-area registration whose absence is
            // exactly the bug the module already hit once (a plugin declared only under
            // etc/frontend/ is simply not loaded for a GraphQL request).
            $mapperType = \Magento\OpenSearch\SearchAdapter\Mapper::class;
            $requestPluginName = 'parkktech_fastmagento_personalize_search_request';
            $requestPluginType = \ParkkTech\FastMagentoPersonalization\Plugin\OpenSearch\PersonalizeSearchRequest::class;
            $next = $pluginList->getNext($mapperType, 'buildQuery');
            $after = $next[\Magento\Framework\Interception\DefinitionInterface::LISTENER_AFTER] ?? [];
            $present = in_array($requestPluginName, $after, true);
            $healthy = $present
                && ($pluginList->getPlugin($mapperType, $requestPluginName) instanceof $requestPluginType);

            $out[] = $healthy
                ? Check::ok(
                    self::G_PERSONALIZATION,
                    'GraphQL request plugin',
                    'PersonalizeSearchRequest is wired into Mapper::buildQuery() in the graphql area'
                )
                : Check::fail(
                    self::G_PERSONALIZATION,
                    'GraphQL request plugin',
                    'PersonalizeSearchRequest is NOT wired into Mapper::buildQuery() in the graphql '
                    . 'area — GraphQL will silently serve un-personalised results while the '
                    . 'storefront personalises',
                    'Confirm etc/graphql/di.xml still declares '
                    . 'parkktech_fastmagento_personalize_search_request, rsync the module source '
                    . 'into vendor/parkktech/fastmagento/, then run: bin/magento setup:di:compile'
                );

            // Sub-check 2: identity wiring — the axis where GraphQL's own bugs live, since GraphQL
            // authenticates by token and resolves the customer from the query context rather than
            // from the storefront's pre-dispatch capture.
            $contextFactoryType = \Magento\GraphQl\Model\Query\ContextFactory::class;
            $contextPluginName = 'parkktech_fastmagento_personalization_graphql_context';
            $contextPluginType = \ParkkTech\FastMagentoPersonalization\Plugin\GraphQl\PersonalizationContextPlugin::class;
            $next = $pluginList->getNext($contextFactoryType, 'create');
            $after = $next[\Magento\Framework\Interception\DefinitionInterface::LISTENER_AFTER] ?? [];
            $present = in_array($contextPluginName, $after, true);
            $healthy = $present
                && ($pluginList->getPlugin($contextFactoryType, $contextPluginName) instanceof $contextPluginType);

            $out[] = $healthy
                ? Check::ok(
                    self::G_PERSONALIZATION,
                    'GraphQL identity context',
                    'PersonalizationContextPlugin is wired into ContextFactory::create()'
                )
                : Check::fail(
                    self::G_PERSONALIZATION,
                    'GraphQL identity context',
                    'PersonalizationContextPlugin is NOT wired into ContextFactory::create() — a '
                    . 'token-authenticated GraphQL shopper will silently receive the anonymous '
                    . 'ordering',
                    'Confirm etc/di.xml (the GLOBAL area, not etc/graphql/di.xml) still declares '
                    . 'parkktech_fastmagento_personalization_graphql_context, rsync the module '
                    . 'source into vendor/parkktech/fastmagento/, then run: '
                    . 'bin/magento setup:di:compile'
                );
        } catch (\Throwable $e) {
            // A diagnostic must never be the thing that breaks the command — accumulate onto
            // whatever was already gathered rather than discarding it (see REVIEW.md WR-01).
            $out[] = Check::skip(self::G_PERSONALIZATION, 'GraphQL wiring', $e->getMessage());
            return $out;
        } finally {
            // Leaving the config scope switched would poison every check that runs after this one.
            $this->configScope->setCurrentScope($saved);
        }

        return $out;
    }

    /**
     * Is the response half of the exploration slot (`ExplorationResponsePlugin`) actually wired
     * into `ResponseFactory::beforeCreate()`, in each area it ships?
     *
     * The other "Exploration slot" status line earlier in `checkPersonalization()` reports
     * OPERATIONAL health — the dial percentage and how many shown products still sit below the
     * exposure floor — but that line reads config and the exposure summary, never the resolved DI
     * configuration. It would report a healthy dial even if `ExplorationResponsePlugin` had been
     * silently dropped by a failed `setup:di:compile` or a third-party module re-declaring the
     * same plugin name: the request-side plugin would still widen the fetch, but nothing would
     * ever permute or slice it back down, and the failure mode is invisible from the storefront —
     * pages just render one plugin's silent no-op short of what the dial promises, not an error.
     * This check closes that gap the same way `checkPersonalizationWiring()` closes it for the
     * request-side plugin: resolve a fresh `PluginListInterface` AFTER switching scope (the list
     * caches per scope) and confirm the plugin CODE resolves to the real CLASS, not just that a
     * DI entry with that name exists.
     *
     * Gated on the exploration dial itself (`getExplorationPercent()`), not just the personalisation
     * master switch — `getExplorationPercent()` already folds in `isApplied()` (returns 0.0 when
     * serving is off), so a single read of the real config accessor correctly SKIPs whether serving
     * is off, or serving is on but the exploration dial is at 0 ("new products earn exposure only
     * by ranking").
     *
     * @return Check[]
     */
    private function checkExplorationWiring(): array
    {
        if ($this->personalizationConfig->getExplorationPercent() <= 0.0) {
            return [Check::skip(
                self::G_PERSONALIZATION,
                'Exploration wiring',
                'Exploration is off (master switch off, or the dial is at 0) — the response-side '
                . 'slot plugin is not exercised'
            )];
        }

        $pluginName = 'parkktech_fastmagento_exploration_response';
        $responseFactoryType = \Magento\Elasticsearch\SearchAdapter\ResponseFactory::class;
        $pluginType = \ParkkTech\FastMagentoPersonalization\Plugin\OpenSearch\ExplorationResponsePlugin::class;

        $out = [];
        $saved = $this->configScope->getCurrentScope();
        try {
            foreach (['frontend', 'graphql'] as $area) {
                // A fresh PluginList instance is required AFTER setCurrentScope() — the list
                // caches per scope, same reasoning as checkPersonalizationWiring().
                $this->configScope->setCurrentScope($area);
                $pluginList = $this->objectManager->create(
                    \Magento\Framework\Interception\PluginListInterface::class
                );

                // beforeCreate() intercepts create(), so the introspection key is LISTENER_BEFORE
                // — not the LISTENER_AFTER checkPersonalizationWiring() uses for afterBuildQuery.
                // Like AFTER (and unlike AROUND), BEFORE is a flat array of every plugin code at
                // that position, not a chain — no hop-walk needed here.
                $next = $pluginList->getNext($responseFactoryType, 'create');
                $before = $next[\Magento\Framework\Interception\DefinitionInterface::LISTENER_BEFORE] ?? [];
                $present = in_array($pluginName, $before, true);

                // Resolve the code to an actual instance rather than trusting the name — a
                // third-party module re-declaring the same plugin name would otherwise read as
                // healthy here.
                $healthy = $present
                    && ($pluginList->getPlugin($responseFactoryType, $pluginName) instanceof $pluginType);

                if ($healthy) {
                    $out[] = Check::ok(
                        self::G_PERSONALIZATION,
                        sprintf('Exploration response plugin (%s)', $area),
                        'ExplorationResponsePlugin is wired into ResponseFactory::beforeCreate()'
                    );
                } elseif ($area === 'frontend') {
                    $out[] = Check::fail(
                        self::G_PERSONALIZATION,
                        sprintf('Exploration response plugin (%s)', $area),
                        'ExplorationResponsePlugin is NOT wired into ResponseFactory::beforeCreate() '
                        . 'in the frontend area',
                        'The dial promises a slot but nothing fills it — the request-side plugin '
                        . 'still widens the fetch for no benefit. Confirm the module source was '
                        . 'rsynced into vendor/parkktech/fastmagento/, then run: '
                        . 'bin/magento setup:di:compile'
                    );
                } else {
                    $out[] = Check::warn(
                        self::G_PERSONALIZATION,
                        sprintf('Exploration response plugin (%s)', $area),
                        'ExplorationResponsePlugin is NOT wired into ResponseFactory::beforeCreate() '
                        . 'in the graphql area',
                        'GraphQL search will silently skip exploration while the storefront fills '
                        . 'the slot — a plugin declared only under etc/frontend/ is not loaded for '
                        . 'the graphql area. Confirm etc/graphql/di.xml still declares the plugin, '
                        . 'then run: bin/magento setup:di:compile'
                    );
                }
            }
        } catch (\Throwable $e) {
            // A diagnostic must never be the thing that breaks the command — accumulate onto
            // whatever was already gathered rather than discarding it (see REVIEW.md WR-01).
            $out[] = Check::skip(self::G_PERSONALIZATION, 'Exploration wiring', $e->getMessage());
            return $out;
        } finally {
            // Leaving the config scope switched would poison every check that runs after this one.
            $this->configScope->setCurrentScope($saved);
        }

        return $out;
    }

    /**
     * The OLDEST stored profile's affinities, or null when there are none to look at.
     *
     * Deliberately the oldest rather than an arbitrary one. Every profile is written by the same
     * builder, so a uniform index answers the same whichever you pick — but the case worth catching
     * is a HALF-migrated index after an upgrade, where new profiles carry ids and old ones do not.
     * Sampling arbitrarily passes that; sampling the least-recently-rebuilt profile catches it with
     * one document.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function sampleProfileAffinities(): ?array
    {
        try {
            $client = $this->diagnostics->resolveClient();
            if (!$client) {
                return null;
            }
            // resolveClient() already returns the OpenSearch client itself.
            $response = $client->search([
                'index' => $this->openSearchConfig->getUserProfileIndexName(),
                'body' => [
                    'size' => 1,
                    'query' => ['match_all' => (object) []],
                    // missing:_first is the point, not a detail — a profile with no updated_at at
                    // all is by definition an old-format one, so it must sort FIRST. The default
                    // (_last) would hide exactly the documents this check exists to find.
                    'sort' => [[
                        'updated_at' => [
                            'order' => 'asc',
                            'missing' => '_first',
                            'unmapped_type' => 'date',
                        ],
                    ]],
                ],
            ]);
            $hit = $response['hits']['hits'][0]['_source'] ?? null;
            $affinities = $hit['affinities'] ?? null;

            return is_array($affinities) && $affinities ? $affinities : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * How many behavioural events have been recorded, or null when the index is absent.
     */
    private function countEvents(): ?int
    {
        try {
            $client = $this->diagnostics->resolveClient();
            if (!$client) {
                return null;
            }
            // The events index holds five types and this check reports on TWO of them. Counting the
            // whole index made the line read "2817 search/facet event(s)" on a store with 46 —
            // impressions are page-rate and swamp everything else, which is the reason they are
            // stored one row per page in the first place.
            $stats = $client->count([
                'index' => $this->openSearchConfig->getEventIndexName(),
                'body' => ['query' => ['terms' => ['type' => ['search', 'facet']]]],
            ]);

            return (int) ($stats['count'] ?? 0);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * When Magento's catalogue search index last finished reindexing, as an ISO-8601 string.
     */
    private function lastFulltextReindexAt(): ?string
    {
        try {
            $connection = $this->resource->getConnection();
            $updated = $connection->fetchOne(
                $connection->select()
                    ->from($this->resource->getTableName('indexer_state'), ['updated'])
                    ->where('indexer_id = ?', 'catalogsearch_fulltext')
            );

            return $updated ? (string) $updated : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
