<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Personalization;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Personalisation settings, in one place so the profile builder and every serving surface agree.
 *
 * The two switches are deliberately independent. Profiles are BUILT whether or not they are USED:
 * the aggregation is off the request path and costs the storefront nothing, so building it
 * continuously means the data is already warm when a merchant switches serving on, and an A/B can
 * compare personalised against control without one arm starting cold.
 *
 * Impact is per surface because the right strength genuinely differs. A recommendation row exists
 * to be relevant to this shopper and can lean on it hard; a category listing has a merchant-chosen
 * order that personalisation should nudge rather than overrule.
 */
class PersonalizationConfig
{
    private const PATH = 'fastmagento/personalization/';

    /**
     * The attributes profiled when nobody says otherwise. One definition, shared by the backfill
     * command and the refresh cron, because two defaults that can drift apart mean the cron
     * quietly rebuilds different profiles than the command that seeded them.
     */
    /**
     * @deprecated since 0.3.0 — the profiled attributes come from ProfileAttributes::resolve(),
     *             which reads the catalogue. Kept so third-party code referencing it still loads.
     */
    public const DEFAULT_PROFILE_ATTRIBUTES = ['color', 'size'];

    public const SURFACE_PLP = 'plp';
    public const SURFACE_SEARCH = 'search';
    public const SURFACE_RECOMMENDATIONS = 'recommendations';

    public const PLP_ORDER_POSITION = 'position';
    public const PLP_ORDER_PERSONALISED = 'personalised';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /** Aggregate profiles at all. Independent of whether they are applied. */
    public function isBuildingProfiles(?int $storeId = null): bool
    {
        return $this->flag('build_profiles', $storeId);
    }

    /**
     * Record searches and facet selections.
     *
     * Independent of whether personalisation is APPLIED, for the same reason profiles are built
     * while serving is off: the data has to be warm before a merchant switches it on, or an A/B
     * starts with one arm empty. Independent of BUILD too — a merchant may want the search-term
     * reporting without any per-shopper profiling at all.
     */
    public function isCollectingEvents(?int $storeId = null): bool
    {
        return $this->flag('collect_events', $storeId);
    }

    /** Let profiles change what shoppers see. Ships off. */
    public function isApplied(?int $storeId = null): bool
    {
        return $this->flag('enabled', $storeId);
    }

    /**
     * Strength for one surface as 0.0–1.0, already accounting for the master switch — a caller can
     * treat 0.0 as "do nothing" without checking anything else.
     */
    public function getImpact(string $surface, ?int $storeId = null): float
    {
        if (!$this->isApplied($storeId)) {
            return 0.0;
        }

        $key = match ($surface) {
            self::SURFACE_PLP => 'impact_plp',
            self::SURFACE_SEARCH => 'impact_search',
            self::SURFACE_RECOMMENDATIONS => 'impact_recommendations',
            default => null,
        };

        return $key === null ? 0.0 : $this->percent($key, $storeId);
    }

    /** Concentration an affinity needs before it is used at all. */
    public function getMinStrength(?int $storeId = null): float
    {
        return $this->percent('min_strength', $storeId);
    }

    /** History an affinity needs before it is trusted. */
    public function getMinConfidence(?int $storeId = null): float
    {
        return $this->percent('min_confidence', $storeId);
    }

    /**
     * Preselect the variant a shopper is most likely to want on a configurable product page.
     *
     * Separate from the impact dials because it is a different KIND of act: the others re-order a
     * set the shopper can already see, this one sets a default they may not notice was set. A
     * merchant may reasonably want the re-ordering and not this.
     */
    public function isPreselectingVariants(?int $storeId = null): bool
    {
        return $this->isApplied($storeId) && $this->flag('preselect_variant', $storeId);
    }

    /**
     * How many boosted values one surface may emit, strongest first.
     *
     * This is a CACHE bound before it is a relevance dial. A personalised page needs its own
     * full-page-cache entry, and the number of distinct entries is the number of distinct boost
     * SETS a shopper base can produce — so an uncapped boost list is the per-shopper cache variant
     * that is the wrong answer at any real traffic level. Capping the boosts caps the segments.
     *
     * Two is not arbitrary: the second value in a concentrated affinity already carries a small
     * fraction of the first's weight, so the third rarely changes an order while multiplying the
     * segment count by the size of the value space.
     */
    public function getMaxBoostTerms(?int $storeId = null): int
    {
        $value = (int) $this->value('max_boost_terms', $storeId);

        return $value > 0 ? $value : 2;
    }

    /**
     * Which catalogue attribute each stated FACT ranks against, as `fact name => attribute code`.
     *
     * A fact arrives named for the SHAPE that was recognised — `year`, `dimension` — because that is
     * all a shape-matcher can honestly claim. Which attribute holds that shape is catalogue
     * knowledge only the merchant has: `year` might map to a model-year attribute on one catalogue
     * and mean nothing on another. So this is the seam between the two, and it ships EMPTY — an
     * unmapped fact is recorded, and ranks nothing.
     *
     * Format: `shape:attribute_code`, comma separated — e.g. `year:model_year,dimension:bed_size`.
     *
     * @return array<string, string>
     */
    /**
     * The merchant's explicit "Attributes To Profile" list. Blank means auto-detect from the
     * catalogue's filterable attributes (see ProfileAttributes); this only returns what was typed.
     *
     * @return string[]
     */
    public function getProfileAttributeCodes(?int $storeId = null): array
    {
        return array_values(array_unique(array_filter(array_map(
            'trim',
            explode(',', (string) $this->value('profile_attributes', $storeId))
        ))));
    }

    public function getFactAttributes(?int $storeId = null): array
    {
        $out = [];
        foreach (explode(',', (string) $this->value('fact_attributes', $storeId)) as $pair) {
            $parts = array_map('trim', explode(':', $pair, 2));
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                $out[$parts[0]] = $parts[1];
            }
        }

        return $out;
    }

    /**
     * How hard a fully-confident fact ranks, as a multiple of the strongest possible affinity.
     *
     * Facts beat affinities and stated beats inferred, and this is where that stops being a slogan
     * and becomes a number. An affinity can contribute at most 1.0 before the impact dial; a fact
     * contributes this, scaled by how sure we are the shopper really stated it. At the default of
     * 3, a corroborated requirement outranks the best preference roughly three to one, and a
     * thin cold-start guess (confidence 0.35) lands just above it — which is the intended
     * ordering, not an accident of tuning.
     *
     * It is still a BOOST. A shopper whose stated requirement excludes most of the catalogue sees
     * the fitting products first and the rest below them; they never see a shorter list.
     */
    public function getFactWeight(?int $storeId = null): float
    {
        $raw = (float) $this->value('fact_weight', $storeId);

        return $raw > 0 ? $raw / 100 : 3.0;
    }

    /**
     * Whether the A/B test is running: half of shoppers personalised, half held out as control.
     */
    public function isAbTestEnabled(?int $storeId = null): bool
    {
        return $this->flag('ab_enabled', $storeId);
    }

    /**
     * The single weight dial a merchant actually touches, resolved to a multiplier.
     *
     * The per-surface impact dials still exist underneath (their RATIOS encode something real — a
     * recommendation row can lean harder than a merchant-ordered category), but asking a merchant
     * to reason about three interacting percentages was the wrong interface. One mode: Less (0.5),
     * Normal (1.0), More (1.75), or Auto — where the tuner owns the number and adjusts it from
     * measured conversion, writing it to `auto_weight_value` so this read stays inside the cached
     * config and costs the request nothing.
     */
    public function getWeightFactor(?int $storeId = null): float
    {
        return match ((string) $this->value('weight_mode', $storeId)) {
            'less' => 0.5,
            'more' => 1.75,
            'auto' => $this->autoWeightValue($storeId),
            default => 1.0,
        };
    }

    public function getWeightMode(?int $storeId = null): string
    {
        $mode = (string) $this->value('weight_mode', $storeId);

        return in_array($mode, ['less', 'more', 'auto'], true) ? $mode : 'normal';
    }

    /** The tuner-owned weight, bounded so a runaway tuner cannot blow past sane territory. */
    public function autoWeightValue(?int $storeId = null): float
    {
        $raw = (float) $this->value('auto_weight_value', $storeId);

        return $raw > 0 ? max(0.25, min(3.0, $raw)) : 1.0;
    }

    /**
     * What fraction of page one the exploration slot may lend to under-exposed products, 0–50.
     *
     * The merchant dial PERSONALIZATION.md §5 promised instead of a pinning rule to maintain by
     * hand. At 10 on a 12-product page, one card is something the shopper would not otherwise have
     * seen; at 0 the mechanism does not exist. Capped at half a page because beyond that the page
     * stops being a ranking with an exploration slot and becomes an exploration with a ranking
     * problem.
     */
    public function getExplorationPercent(?int $storeId = null): float
    {
        if (!$this->isApplied($storeId)) {
            return 0.0;
        }

        $raw = (float) $this->value('exploration_percent', $storeId);

        return max(0.0, min(50.0, $raw));
    }

    /**
     * How much a stated signal counts against a purchase, as a multiplier.
     *
     * Above 1.0 because the profile's own rule is that stated beats inferred. A purchase records
     * what a shopper settled for after stock, price and delivery had their say; a search or a facet
     * click records what they asked for before any of that interfered. Both are real evidence, and
     * the second is the more direct statement of intent.
     */
    public function getEventWeight(?int $storeId = null): float
    {
        $raw = (float) $this->value('event_weight', $storeId);

        return $raw > 0 ? $raw / 100 : 1.5;
    }

    /**
     * How much a product VIEW counts against a purchase, as a multiplier.
     *
     * Far below 1.0, and below the stated signals, because a view is a question rather than an
     * answer — a catalogue produces views by the thousand for every purchase, and weighting them
     * anywhere near intent would let browsing drown out buying. Useful for separating two products
     * a shopper is already interested in; useless as a preference on its own.
     */
    public function getViewWeight(?int $storeId = null): float
    {
        $raw = (float) $this->value('view_weight', $storeId);

        return $raw >= 0 ? $raw / 100 : 0.25;
    }

    /** Days after which a purchase counts half. 0 = no decay. */
    public function getHalfLifeDays(?int $storeId = null): float
    {
        return max(0.0, (float) $this->value('half_life_days', $storeId));
    }

    private function percent(string $key, ?int $storeId): float
    {
        $raw = (float) $this->value($key, $storeId);

        return max(0.0, min(1.0, $raw / 100));
    }

    private function flag(string $key, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH . $key, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function value(string $key, ?int $storeId): ?string
    {
        $value = $this->scopeConfig->getValue(self::PATH . $key, ScopeInterface::SCOPE_STORE, $storeId);

        return $value === null ? null : (string) $value;
    }

    /**
     * How a category listing is ordered for a shopper with an actionable profile:
     * merchant position (personalisation breaks ties only) or position-aware personalised.
     */
    public function getPlpOrderMode(?int $storeId = null): string
    {
        $mode = (string) $this->scopeConfig->getValue(
            'fastmagento/personalization/plp_order_mode',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $mode === self::PLP_ORDER_POSITION ? self::PLP_ORDER_POSITION : self::PLP_ORDER_PERSONALISED;
    }

    /** Positions a maximal boost can move a product on a listing (the width of the merchant prior). */
    public function getPlpBand(?int $storeId = null): int
    {
        $band = (int) $this->scopeConfig->getValue('fastmagento/personalization/plp_band', ScopeInterface::SCOPE_STORE, $storeId);
        return $band > 0 ? min(200, $band) : 12;
    }

    /** How hard the listing re-rank leans on the profile: multiplier applied to the boost lift. */
    public function getPlpStrength(?int $storeId = null): float
    {
        $strength = (float) $this->scopeConfig->getValue('fastmagento/personalization/plp_strength', ScopeInterface::SCOPE_STORE, $storeId);
        return $strength > 0.0 ? min(20.0, $strength) : 6.0;
    }

    /** Extra weight on affinities measured within the category being listed (percent, 100 = none). */
    public function getCategoryAffinityBonus(?int $storeId = null): float
    {
        $bonus = (float) $this->scopeConfig->getValue('fastmagento/personalization/category_affinity_bonus', ScopeInterface::SCOPE_STORE, $storeId);
        return $bonus > 0.0 ? min(5.0, $bonus / 100.0) : 1.5;
    }
}
