<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Personalization;

use Magento\Customer\Model\Session as CustomerSession;

/**
 * The single place a shopper's profile turns into OpenSearch scoring clauses.
 *
 * One class, called from every surface, so "personalisation" has exactly one definition and one
 * place to audit. Each surface differs only in its impact dial and in which index it ranks.
 *
 * THE RULE THAT OUTRANKS THE REST: when personalisation is off, or there is no profile, or nothing
 * survives the gates, this returns the query array it was given — the same array, untouched. M2's
 * acceptance test is "off produces byte-identical output to today", and the way to pass a test like
 * that is to make it structurally impossible to fail rather than to compare outputs afterwards.
 *
 * Both gates must pass before a value contributes anything:
 *
 *   demand — is this a real preference?  {@see AttributeAffinity} concentration + confidence
 *   supply — can acting on it reorder?   {@see ValueDiscrimination} per-value IDF
 *
 * and the supply side is asked in the terms of the index being ranked, because the two indices are
 * mirror images. Magento's listing index holds visible parents keyed by EAV option id; FastMagento's
 * serving index holds parents AND variants keyed by label. Size "L" is 52% of the first (worthless)
 * and 13% of the second (meaningful). Asking the wrong one produces a confident, wrong answer.
 *
 * Two tiers contribute, and the difference between them is evidence, not mechanism:
 *
 *   facts       what the shopper STATED — a requirement. Skips the demand gate, because there is
 *               nothing to infer, and ranks several times harder than any preference can.
 *   affinities  what they revealed by buying, searching and filtering. Both gates apply.
 *
 * Everything emitted is a BOOST — facts included. Nothing filters, nothing excludes: personalisation
 * re-orders and never hides. A shopper who sees fewer products because of their profile is a bug,
 * not a feature, and that holds even when the profile is certain: a stated requirement puts the
 * products that satisfy it first, it does not decide the rest are unwanted. Being confidently wrong
 * about what someone needs is a real failure mode, and ranking survives it where filtering does not.
 */
class QueryPersonalizer
{
    /**
     * IDF at which a value counts as fully discriminating. Roughly "on an eighth of the index or
     * less"; beyond it, rarer values do not earn proportionally more boost, they just get noisier.
     */
    private const IDF_SATURATION = 2.0;

    /** Most a single surface may add on top of the baseline, before the impact dial scales it. */
    private const MAX_UPLIFT = 1.0;

    public function __construct(
        private readonly PersonalizationConfig $config,
        private readonly ProfileRepository $repository,
        private readonly ValueDiscrimination $discrimination,
        private readonly CustomerSession $customerSession,
        private readonly RequestScope $requestScope,
        private readonly AbTest $abTest
    ) {
    }

    /**
     * @param array<string, mixed> $query the query clause, e.g. ['bool' => [...]]
     * @param string $surface one of PersonalizationConfig::SURFACE_*
     * @param string $target one of ValueDiscrimination::TARGET_*
     * @return array<string, mixed> the same array when nothing applies, a function_score when it does
     */
    public function decorate(
        array $query,
        string $surface,
        string $target,
        ?int $storeId = null,
        ?int $customerId = null,
        ?int $categoryId = null
    ): array {
        $functions = $this->buildFunctions($surface, $target, $storeId, $customerId, $categoryId);
        if (!$functions) {
            return $query;
        }

        // A baseline is not optional. The functions below ADD to a document's multiplier, so
        // without a match_all floor a document matching no function would score below one that
        // matches a weak one — personalisation would demote everything it has no opinion about.
        array_unshift($functions, ['filter' => ['match_all' => (object) []], 'weight' => 1.0]);

        if (isset($query['function_score'])) {
            // The surface already scores by function (the in-stock weight on instant search).
            // Append rather than nest: personalisation is another voice in the same vote, and
            // nesting would multiply the two instead of letting merchandising stay dominant.
            $decorated = $query;
            $decorated['function_score']['functions'] = array_merge(
                $decorated['function_score']['functions'] ?? [],
                $functions
            );

            return $decorated;
        }

        return [
            'function_score' => [
                'query' => $query,
                'functions' => $functions,
                'boost_mode' => 'multiply',
                'score_mode' => 'sum',
            ],
        ];
    }

    /**
     * Whether this request would be personalised at all — for the storefront affordance and for
     * the doctor, neither of which should have to build a query to find out.
     */
    public function isActive(
        string $surface,
        string $target,
        ?int $storeId = null,
        ?int $customerId = null,
        ?int $categoryId = null
    ): bool {
        return $this->buildFunctions($surface, $target, $storeId, $customerId, $categoryId) !== [];
    }

    /**
     * The scoring clauses this shopper would contribute, for the inspector and the doctor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function explain(
        string $surface,
        string $target,
        ?int $storeId = null,
        ?int $customerId = null,
        ?int $categoryId = null
    ): array {
        return $this->buildFunctions($surface, $target, $storeId, $customerId, $categoryId);
    }

    /**
     * The same clauses, before they are flattened into OpenSearch functions — so a caller can tell a
     * stated requirement from an inferred preference, which the function form deliberately cannot.
     *
     * For the inspector and the doctor only. The serving path reads {@see decorate()}.
     *
     * @return array<int, array<string, mixed>>
     */
    public function explainTerms(
        string $surface,
        string $target,
        ?int $storeId = null,
        ?int $customerId = null,
        ?int $categoryId = null
    ): array {
        return $this->boostTerms($surface, $target, $storeId, $customerId, $categoryId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFunctions(
        string $surface,
        string $target,
        ?int $storeId,
        ?int $customerId = null,
        ?int $categoryId = null
    ): array {
        $functions = [];
        foreach ($this->boostTerms($surface, $target, $storeId, $customerId, $categoryId) as $boost) {
            $functions[] = [
                'filter' => ['term' => [$boost['field'] => $boost['term']]],
                'weight' => round($boost['weight'], 4),
            ];
        }

        return $functions;
    }

    /**
     * The gated, weighted values this shopper would be boosted on — the single definition of
     * "what personalisation means for this request".
     *
     * Both the query decorator and the in-memory re-ranker used for merchant-curated link blocks
     * read from here, so a boost cannot mean one thing in a search and another in an up-sell row.
     * That is the locked one-entry-point rule, and it only holds if the GATES live here rather than
     * at each call site.
     *
     * @return array<int, array{code: string, label: string, term: string, field: string, weight: float}>
     */
    private function boostTerms(
        string $surface,
        string $target,
        ?int $storeId,
        ?int $customerId = null,
        ?int $categoryId = null
    ): array {
        $impact = $this->config->getImpact($surface, $storeId);
        if ($impact <= 0.0) {
            // Master switch off, or this surface dialled to zero. The cheapest exit, taken before
            // any I/O, so an un-personalised store pays nothing for this code existing.
            return [];
        }

        if (!$this->abTest->shouldPersonalize($storeId)) {
            // The control arm. Their profile is still BUILT — collection never stops — but nothing
            // reads it, so they see exactly today's storefront and their cache signature stays '0'.
            // This return is what makes the dashboard's comparison mean something.
            return [];
        }

        // The one weight dial. Applied to the surface impact rather than to each clause, so the
        // relative shape of a shopper's boosts — and the fact/affinity ratio — survives the scaling.
        $impact *= $this->config->getWeightFactor($storeId);

        $profile = $this->loadProfile($customerId);
        if ($profile === null) {
            return [];
        }

        // Stated requirements rank first and are not subject to the cap below — see factBoosts().
        $facts = $this->factBoosts($profile, $target, $storeId, $impact, $categoryId);
        $spokenFor = array_column($facts, 'code');

        // On a category listing, what the shopper buys IN THIS CATEGORY outranks what they buy
        // overall: a shopper who buys black tops and red shoes has no global colour preference,
        // but on the Tops listing "black" is exactly right. Category-scoped affinities (ancestors
        // included at build time) are used when the profile has them for this category, with the
        // configured bonus; otherwise the global set, unchanged.
        $scoped = $this->categoryAffinitiesFor($profile, $surface, $categoryId);
        $affinities = $scoped ?? ($profile['affinities'] ?? []);
        $scopeBonus = $scoped === null ? 1.0 : $this->config->getCategoryAffinityBonus($storeId);
        if (!is_array($affinities) || !$affinities) {
            return $facts;
        }

        $boosts = [];
        foreach ($affinities as $code => $affinity) {
            if (empty($affinity['actionable'])) {
                continue;
            }

            if (in_array((string) $code, $spokenFor, true)) {
                // A fact already speaks for this attribute, and facts beat affinities. Emitting
                // both would let an inferred preference for last year's model quietly compete with
                // the year the shopper actually told us — softening the stated answer with the
                // guessed one, which is the exact inversion the rule exists to prevent.
                continue;
            }

            $field = $this->fieldFor((string) $code, $target);
            if ($field === null) {
                continue;
            }

            $strength = (float) ($affinity['strength'] ?? 0.0);
            $confidence = (float) ($affinity['confidence'] ?? 0.0);
            $valueIds = $affinity['value_ids'] ?? [];

            foreach ($affinity['values'] ?? [] as $label => $share) {
                $label = (string) $label;

                // Ask the index in its own terms: option id for Magento's, label for ours.
                $term = $target === ValueDiscrimination::TARGET_NATIVE
                    ? (isset($valueIds[$label]) ? (string) $valueIds[$label] : null)
                    : $label;
                if ($term === null || $term === '') {
                    continue;
                }

                // Discrimination is always measured against the NATIVE index: that is where the
                // rankable population lives, at the parent granularity these surfaces present.
                $gateTerm = isset($valueIds[$label]) ? (string) $valueIds[$label] : $term;
                // On a category listing the gate is the CATEGORY's population when it is large
                // enough to have been measured on its own: a value that is rare store-wide but
                // universal in this category cannot reorder this page, and must not try.
                $idf = $this->discrimination->getIdf(
                    (string) $code,
                    $gateTerm,
                    ValueDiscrimination::TARGET_NATIVE,
                    $storeId,
                    $categoryId
                );
                if ($idf === null) {
                    // Never measured. An ungated boost is the silent-success bug this whole
                    // mechanism exists to prevent, so emit nothing and let the doctor say so.
                    continue;
                }

                $catalogueShare = $this->discrimination->getShare(
                    (string) $code,
                    $gateTerm,
                    ValueDiscrimination::TARGET_NATIVE,
                    $storeId,
                    $categoryId
                );
                if ($catalogueShare === null
                    || $catalogueShare <= 0.0
                    || $catalogueShare > ValueDiscrimination::NEAR_UNIFORM_SHARE
                ) {
                    // Either nothing carries it, or so much does that boosting cannot reorder
                    // anything. A real preference that cannot change this page.
                    continue;
                }

                $uplift = (float) $share
                    * $strength
                    * $confidence
                    * min(1.0, $idf / self::IDF_SATURATION)
                    * $impact
                    * $scopeBonus
                    * self::MAX_UPLIFT;

                if ($uplift <= 0.0) {
                    continue;
                }

                $boosts[] = [
                    'code' => (string) $code,
                    'label' => $label,
                    'term' => $term,
                    // Carried so an in-memory scorer can match a configurable parent, whose own
                    // attribute label is empty and whose colour lives on its children as ids.
                    'option_id' => isset($valueIds[$label]) ? (string) $valueIds[$label] : null,
                    'field' => $field,
                    'weight' => $uplift,
                    'share' => $catalogueShare,
                    'idf' => $idf,
                    'gated_on' => $categoryId !== null
                        && $this->discrimination->hasCategorySection($categoryId, $storeId) ? 'category' : 'store',
                ];
            }
        }

        return array_merge($facts, $this->capToStrongest($boosts, $this->config->getMaxBoostTerms($storeId)));
    }

    /**
     * The stated requirements this shopper would be ranked on.
     *
     * A fact is a different kind of evidence from an affinity and is gated differently. The DEMAND
     * gate — concentration and confidence — asks "is this inferred preference real?", and there is
     * nothing to infer here: the shopper typed it. What survives is the fact's own confidence, which
     * carries how sure we are they meant it, and the SUPPLY gate, which still applies for the reason
     * it always did — a requirement every product in the catalogue satisfies cannot re-order
     * anything, and emitting it would buy a cache variant for nothing.
     *
     * Facts are exempt from `max_boost_terms`. That cap is a cache bound, and trading away a stated
     * requirement to save a cache entry is the wrong trade — a shopper who told the store what they
     * need should get it back. The bound is not lost: one fact per recognised shape, and shapes only
     * rank once the merchant has mapped them (`fact_attributes`), so the number of fact clauses is
     * bounded by a merchant setting rather than by the shopper base.
     *
     * @param array<string, mixed> $profile
     * @return array<int, array<string, mixed>>
     */
    private function factBoosts(
        array $profile,
        string $target,
        ?int $storeId,
        float $impact,
        ?int $categoryId = null
    ): array
    {
        $facts = $profile['facts'] ?? [];
        if (!is_array($facts) || !$facts) {
            return [];
        }

        $factWeight = $this->config->getFactWeight($storeId);
        $boosts = [];

        foreach ($facts as $name => $fact) {
            if (!is_array($fact)) {
                continue;
            }

            // Resolved at build time. Unmapped, or mapped to a value this catalogue does not carry,
            // means the fact is still shown to the shopper and still ranks nothing — a proposal we
            // cannot act on is not a licence to act on something near it.
            $code = (string) ($fact['attribute'] ?? '');
            $label = (string) ($fact['label'] ?? '');
            $optionId = isset($fact['value_id']) ? (string) $fact['value_id'] : '';
            if ($code === '' || $label === '' || $optionId === '') {
                continue;
            }

            $field = $this->fieldFor($code, $target);
            if ($field === null) {
                continue;
            }

            $term = $target === ValueDiscrimination::TARGET_NATIVE ? $optionId : $label;

            $idf = $this->discrimination->getIdf(
                $code,
                $optionId,
                ValueDiscrimination::TARGET_NATIVE,
                $storeId,
                $categoryId
            );
            $share = $this->discrimination->getShare(
                $code,
                $optionId,
                ValueDiscrimination::TARGET_NATIVE,
                $storeId,
                $categoryId
            );
            if ($idf === null
                || $share === null
                || $share <= 0.0
                || $share > ValueDiscrimination::NEAR_UNIFORM_SHARE
            ) {
                continue;
            }

            $uplift = $factWeight
                * (float) ($fact['confidence'] ?? 0.0)
                * min(1.0, $idf / self::IDF_SATURATION)
                * $impact;

            if ($uplift <= 0.0) {
                continue;
            }

            $boosts[] = [
                'code' => $code,
                'fact' => (string) $name,
                'label' => $label,
                'term' => $term,
                'option_id' => $optionId,
                'field' => $field,
                'weight' => $uplift,
            ];
        }

        // Strongest first, so a surface reading this list top-down sees the requirements in the
        // order they matter and the cap on the affinities below never reorders them.
        usort($boosts, static fn (array $a, array $b) => $b['weight'] <=> $a['weight']);

        return $boosts;
    }

    /**
     * Keep only the strongest N boosts.
     *
     * This is a cache bound as much as a relevance one. A personalised page needs its own full-page
     * cache entry, and the number of entries is the number of distinct boost SETS the shopper base
     * can produce — so an uncapped list of boosts is precisely the per-shopper cache variant that
     * is the wrong answer at any real traffic level. Capping here (rather than only when computing
     * the cache signature) is what keeps the two in step: the signature is derived from exactly
     * these functions, so two shoppers who share a cache entry are guaranteed to be receiving the
     * same boosts, and never merely a similar-looking summary of them.
     *
     * Ordering is by weight so the cap costs the least possible relevance — the values dropped are
     * the ones that were already moving the page least.
     *
     * @param array<int, array<string, mixed>> $boosts
     * @return array<int, array<string, mixed>>
     */
    private function capToStrongest(array $boosts, int $max): array
    {
        if ($max <= 0 || count($boosts) <= $max) {
            return $boosts;
        }

        usort($boosts, static fn (array $a, array $b) => ($b['weight'] ?? 0) <=> ($a['weight'] ?? 0));

        return array_slice($boosts, 0, $max);
    }

    /**
     * Re-order a merchant-curated set — related, up-sell, cross-sell — in memory.
     *
     * These blocks are not ranked by a query; the merchant chose the members and their order, and
     * the documents are already in hand. So personalisation here is a re-sort of a handful of
     * products, costing no query at all.
     *
     * Three rules, and the second is the one that makes this safe. It RE-ORDERS ONLY: nothing is
     * added, because inventing a product into a merchant's curated row is forbidden outright, and
     * nothing is removed, because personalisation re-orders and never hides. The roadmap permits
     * pruning link blocks; that is deliberately not taken up, since dropping a product a merchant
     * explicitly chose is a bigger promise than re-ordering it and is not needed to be useful.
     * Third, the sort is STABLE, so products the profile has no opinion about keep the merchant's
     * exact relative order rather than being shuffled by an implementation detail.
     *
     * @param array<int, array<string, mixed>> $docs product source documents, in merchant order
     * @return array<int, array<string, mixed>> the same documents, re-ordered
     */
    public function reorderDocuments(
        array $docs,
        string $surface,
        ?int $storeId = null,
        ?int $customerId = null
    ): array {
        if (count($docs) < 2) {
            return $docs;
        }

        // Link-block documents come from the serving index, where attributes are labels.
        $boosts = $this->boostTerms($surface, ValueDiscrimination::TARGET_SERVING, $storeId, $customerId);
        if (!$boosts) {
            return $docs;
        }

        $scored = [];
        foreach (array_values($docs) as $position => $doc) {
            $scored[] = [
                'position' => $position,
                'score' => $this->scoreDocument($doc, $boosts),
                'doc' => $doc,
            ];
        }

        // Score descending, original position ascending. The tiebreak on position is what makes it
        // stable, and it is also what keeps the merchant's order intact wherever the profile is
        // indifferent.
        usort(
            $scored,
            static fn (array $a, array $b) => ($b['score'] <=> $a['score']) ?: ($a['position'] <=> $b['position'])
        );

        return array_column($scored, 'doc');
    }

    /**
     * How strongly one document matches this shopper's boosts.
     *
     * @param array<string, mixed> $doc
     * @param array<int, array<string, mixed>> $boosts
     */
    private function scoreDocument(array $doc, array $boosts): float
    {
        $score = 0.0;

        foreach ($boosts as $boost) {
            if ($this->documentCarries($doc, (string) $boost['code'], (string) $boost['label'], $boost['option_id'] ?? null)) {
                $score += (float) $boost['weight'];
            }
        }

        return $score;
    }

    /**
     * Does this product come in that value?
     *
     * Two shapes, and missing the second is a silent no-op rather than an error. A SIMPLE product
     * carries its own label at `attributes.<code>`. A CONFIGURABLE parent — which is what a link
     * block almost always holds — carries `attributes.<code>` as an EMPTY string, because the
     * parent genuinely has no colour of its own; the values live on its children, as option IDS
     * under `child_products[].custom_attributes.<code>`. Scoring only the first shape matches
     * nothing on a row of configurables and quietly leaves the merchant's order untouched, which
     * looks exactly like "this shopper has no preference".
     *
     * Membership, not equality: a parent available in Black counts, whichever of its variants it is.
     *
     * @param array<string, mixed> $doc
     */
    private function documentCarries(array $doc, string $code, string $label, ?string $optionId): bool
    {
        $own = $doc['attributes'][$code] ?? null;
        if ($own !== null) {
            $values = array_filter(array_map('strval', is_array($own) ? $own : [$own]), static fn ($v) => $v !== '');
            if ($values && in_array($label, $values, true)) {
                return true;
            }
        }

        if ($optionId === null || empty($doc['child_products']) || !is_array($doc['child_products'])) {
            return false;
        }

        foreach ($doc['child_products'] as $child) {
            $childValue = $child['custom_attributes'][$code] ?? null;
            if ($childValue !== null && (string) $childValue === $optionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which field carries this attribute in the index being ranked, or null when it carries none.
     */
    /**
     * The category-scoped affinity set for a listing, or NULL when the global set applies
     * (not a listing, no category, or the profile has nothing actionable for this category).
     *
     * @param array<string, mixed> $profile
     * @return array<string, array<string, mixed>>|null
     */
    private function categoryAffinitiesFor(array $profile, string $surface, ?int $categoryId): ?array
    {
        if ($surface !== PersonalizationConfig::SURFACE_PLP || $categoryId === null || $categoryId <= 0) {
            return null;
        }
        $scoped = $profile['category_affinities'][(string) $categoryId] ?? null;
        if (!is_array($scoped) || !$scoped) {
            return null;
        }
        return $scoped;
    }

    /**
     * Whether a listing of $categoryId would use category-scoped affinities for the current
     * shopper — what the explain command and the doctor report.
     */
    public function usesCategoryAffinities(int $categoryId, ?int $customerId = null): bool
    {
        $profile = $this->loadProfile($customerId);
        return $profile !== null
            && $this->categoryAffinitiesFor($profile, PersonalizationConfig::SURFACE_PLP, $categoryId) !== null;
    }

    private function fieldFor(string $attributeCode, string $target): ?string
    {
        if ($target === ValueDiscrimination::TARGET_NATIVE) {
            return $attributeCode === 'category' ? 'category_ids' : $attributeCode;
        }

        // FastMagento's serving index is `dynamic: false` at its root, so category_ids lives in
        // _source but is never indexed and cannot be filtered on. Categories are a listing-side
        // signal here, not a search-side one.
        if ($attributeCode === 'category') {
            return null;
        }

        return 'attributes.' . $attributeCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadProfile(?int $customerId = null): ?array
    {
        // An explicit id is how the CLI inspects a shopper it is not logged in as; production
        // always passes null and resolves the session.
        if ($customerId === null) {
            try {
                $customerId = (int) $this->customerSession->getCustomerId();
            } catch (\Throwable $e) {
                $customerId = 0;
            }

            // On a cacheable page the session is not resolved during rendering, so the adapter sees
            // no customer even when one is logged in. Pre-dispatch captured it while the session
            // was still authoritative; this is where that gets used.
            if ($customerId <= 0) {
                $customerId = (int) ($this->requestScope->getCustomerId() ?? 0);
            }
        }

        if ($customerId > 0) {
            return $this->repository->get(ProfileRepository::idForCustomer($customerId));
        }

        // The guest tier: a profile keyed by the analytics cookie, built from behaviour observed on
        // this browser and nothing else — see ProfileBuilder::buildForAnonymous() for why it never
        // carries account data. A guest with no cookie, or a cookie nothing has profiled, gets
        // exactly today's results, which remains the correct fallback.
        $anonId = $this->requestScope->getAnonId();
        if ($anonId === null || $anonId === '') {
            return null;
        }

        return $this->repository->get(ProfileRepository::idForAnonymous($anonId));
    }
}
