<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use ParkkTech\FastMagento\Helper\WriteLog;
use ParkkTech\FastMagentoPersonalization\Model\Analytics\EventHistoryProvider;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\PersonalizationConfig;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\ProductExposure;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\ProfileBuilder;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\ValueDiscrimination;

/**
 * Keep personalisation's three tables fresh without anyone remembering to.
 *
 * Until this existed, profiles, the exposure table and the discrimination table were rebuilt when
 * somebody ran a CLI command — which is to say, on a live store, never. Events would accumulate,
 * profiles would describe shoppers as they were at the last manual run, and every doctor check
 * would stay green while the system served steadily staler answers. Freshness cannot be an
 * operator's memory. This is the standing rule about silent failure applied to time.
 *
 * INCREMENTAL BY DESIGN. An hourly full backfill would be rude to a store with a million customers,
 * and pointless: a profile can only have changed if its shopper did something. So each run asks two
 * cheap questions — who ordered since the last run (SQL), who generated events since the last run
 * (one aggregation) — and rebuilds exactly those profiles, customer and guest tier alike. A store
 * where nothing happened pays two lookups and goes back to sleep.
 *
 * The full pass still exists (`fastmagento:profile:backfill`) and is still the right tool after an
 * install, an upgrade, or a weighting change — this job keeps a warm system warm, it does not heat
 * a cold one.
 *
 * The other two tables are facts about the whole store, not about a shopper, and are cheap enough
 * to refresh wholesale: exposure is one aggregation and one SQL query; discrimination is one terms
 * aggregation (~17ms measured) and is rebuilt only when the doctor's own staleness rule says so —
 * the catalogue reindexed since it was measured. The rebuilt attribute list always includes the
 * merchant's mapped fact attributes, which closes a hole where mapping a fact shape in admin did
 * nothing until someone also remembered the discrimination CLI.
 */
class RefreshPersonalization
{
    private const FLAG_LAST_RUN = 'fastmagento_personalization_refresh_last_run';

    /** Per run, per tier. An hourly job that bites off more than this is late for its next run. */
    private const MAX_PROFILES_PER_RUN = 2000;

    /** The first run has no "since last run"; a bounded look-back beats an unbounded backfill. */
    private const FIRST_RUN_LOOKBACK = '-1 day';

    public function __construct(
        private readonly PersonalizationConfig $config,
        private readonly ProfileBuilder $builder,
        private readonly EventHistoryProvider $events,
        private readonly ProductExposure $exposure,
        private readonly ValueDiscrimination $discrimination,
        private readonly ResourceConnection $resource,
        private readonly FlagManager $flagManager,
        private readonly WriteLog $writeLog
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isBuildingProfiles()) {
            return;
        }

        $since = (string) ($this->flagManager->getFlagData(self::FLAG_LAST_RUN) ?: '');
        if ($since === '') {
            $since = gmdate('c', (int) strtotime(self::FIRST_RUN_LOOKBACK));
        }
        // Stamped before the work, not after: anything that happens while this run is in flight
        // lands after the new watermark and is picked up next hour, where stamping afterwards
        // would silently drop everything that happened during the run.
        $this->flagManager->saveFlag(self::FLAG_LAST_RUN, gmdate('c'));

        $attributes = PersonalizationConfig::DEFAULT_PROFILE_ATTRIBUTES;

        try {
            $customerIds = array_slice(array_unique(array_merge(
                $this->customersWithOrdersSince($since),
                $this->events->customerIdsWithEventsSince($since, self::MAX_PROFILES_PER_RUN)
            )), 0, self::MAX_PROFILES_PER_RUN);

            foreach ($customerIds as $customerId) {
                try {
                    $this->builder->buildForCustomer((int) $customerId, $attributes);
                } catch (\Throwable $e) {
                    // One unprofilable shopper must not stop the rest of the hour's work.
                }
            }

            foreach ($this->events->activeAnonIds(3, self::MAX_PROFILES_PER_RUN, $since) as $anonId) {
                try {
                    $this->builder->buildForAnonymous($anonId, $attributes);
                } catch (\Throwable $e) {
                    // Same rule for the guest tier.
                }
            }
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('Personalisation profile refresh failed: ' . $e->getMessage());
        }

        try {
            $this->exposure->rebuild();
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('Exposure refresh failed: ' . $e->getMessage());
        }

        try {
            $this->refreshDiscriminationIfStale();
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('Discrimination refresh failed: ' . $e->getMessage());
        }
    }

    /**
     * @return int[]
     */
    private function customersWithOrdersSince(string $since): array
    {
        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from($this->resource->getTableName('sales_order'), ['customer_id'])
            ->where('customer_id IS NOT NULL')
            ->where('created_at >= ?', gmdate('Y-m-d H:i:s', (int) strtotime($since)))
            ->distinct();

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Rebuild the discrimination table only when the catalogue has moved past it — the same rule
     * the doctor warns on, applied instead of reported.
     */
    private function refreshDiscriminationIfStale(): void
    {
        $builtAt = $this->discrimination->getBuiltAt();
        $reindexedAt = $this->lastFulltextReindexAt();

        if ($builtAt !== null && ($reindexedAt === null || strtotime($builtAt) >= strtotime($reindexedAt))) {
            return;
        }

        // Base attributes plus whatever the merchant has mapped facts to. Without the second half,
        // a fact mapping made in admin ranks nothing until somebody also runs the
        // discrimination CLI with the right --attributes — a dependency nobody would guess.
        $attributes = array_values(array_unique(array_merge(
            ['color', 'size', 'category'],
            array_values($this->config->getFactAttributes())
        )));

        $this->discrimination->rebuild($attributes);
    }

    private function lastFulltextReindexAt(): ?string
    {
        try {
            $connection = $this->resource->getConnection();
            $updated = $connection->fetchOne(
                $connection->select()
                    ->from($this->resource->getTableName('indexer_state'), ['updated'])
                    ->where('indexer_id = ?', 'catalogsearch_fulltext')
            );

            return $updated ? gmdate('c', (int) strtotime((string) $updated)) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
