<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Plugin\OpenSearch;

use Magento\Framework\Search\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\PersonalizationConfig;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\QueryPersonalizer;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\ValueDiscrimination;

/**
 * Personalise everything that ranks through Magento's own search pipeline — chiefly the category
 * listing.
 *
 * This is the PLP arm, and it lives here rather than in FastMagento's listing code because of a
 * fact that is easy to get wrong: the module's `Fulltext\Collection` override replaces ONLY
 * `_loadEntities` — entity hydration. The id list, the ORDER, pagination and layered-navigation
 * facet counts all come from Magento's pipeline upstream of it. Re-ordering at the collection would
 * reshuffle the twelve products already chosen for the current page, which is wrong the moment
 * there is a second page: the products that should have been promoted onto page one are still on
 * page three, unseen.
 *
 * `Mapper::buildQuery()` is the last point where the whole request is still one array and the
 * ranking has not yet happened, which makes it the only correct seam for this.
 *
 * It fires for every request that reaches the adapter, so the surface is read from the request name
 * rather than assumed. Anything not explicitly mapped is left alone — GraphQL containers rank
 * through here too and would come "free", but they belong to a later milestone and silently
 * personalising them would widen this one past what has been verified.
 */
class PersonalizeSearchRequest
{
    /**
     * Request container name => the surface whose impact dial governs it.
     *
     * Deliberately an allowlist. A new container appearing in a future Magento version should do
     * nothing here until someone decides what it is, rather than inherit a default.
     */
    private const SURFACE_BY_REQUEST = [
        'catalog_view_container' => PersonalizationConfig::SURFACE_PLP,
        'quick_search_container' => PersonalizationConfig::SURFACE_SEARCH,
        'advanced_search_container' => PersonalizationConfig::SURFACE_SEARCH,
        // GraphQL ranks through this same mapper, so it comes free — but only once identity is
        // resolved for it, which it is via the context-factory plugin. A guest query resolves to
        // no user and personalises nothing.
        'graphql_product_search' => PersonalizationConfig::SURFACE_SEARCH,
        'graphql_product_search_with_aggregation' => PersonalizationConfig::SURFACE_SEARCH,
    ];

    public function __construct(
        private readonly QueryPersonalizer $personalizer,
        private readonly StoreManagerInterface $storeManager,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Personalization\ExplorationSlot $exploration,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Personalization\RequestScope $requestScope
    ) {
    }

    /**
     * @param array<string, mixed> $result the full request body Magento is about to send
     * @return array<string, mixed>
     */
    public function afterBuildQuery(
        object $subject,
        array $result,
        RequestInterface $request
    ): array {
        try {
            $surface = self::SURFACE_BY_REQUEST[$request->getName()] ?? null;
            if ($surface === null) {
                return $result;
            }
            $query = $result['body']['query'] ?? null;
            if (!is_array($query) || !$query) {
                return $result;
            }

            $decorated = $this->personalizer->decorate(
                $query,
                $surface,
                // Always the native target: this mapper only ever builds queries against Magento's
                // own catalogue index, where the profiled attributes are EAV option ids.
                ValueDiscrimination::TARGET_NATIVE,
                (int) $this->storeManager->getStore()->getId()
            );

            // Identity when nothing applied — the decorator hands back the same array, so an
            // un-personalised request is not merely equivalent to today's but literally unchanged.
            // Exploration still runs: it is a fact about the STORE's catalogue, not about this
            // shopper, and the guest with no profile is exactly who the under-exposed product
            // needs to be seen by.
            if ($decorated === $query) {
                return $this->expandForExploration($result);
            }

            $result['body']['query'] = $decorated;
            $result['body']['sort'] = $this->withScoreTiebreaker($result['body']['sort'] ?? []);

            return $this->expandForExploration($result);
        } catch (\Throwable $e) {
            // A category page must never fail to render because personalisation had an opinion.
            return $result;
        }
    }

    /**
     * Widen the fetch so the exploration slot has a below-the-fold ranking to choose from.
     *
     * The response-side plugin restores the page — see {@see ExplorationSlot} for why every page
     * must slice one global permutation rather than each page improvising its own. Three refusals:
     *
     *   a shopper-chosen sort   price or name order is theirs, exactly as sorted. Exploration lives
     *                           inside relevance and merchandising order only, so it applies only
     *                           when the primary sort is the merchant's position or the engine's
     *                           score — the two orders this module already re-ranks within.
     *   a deep page             from beyond the window sees the original ranking, which the
     *                           permutation's own construction guarantees is identical there.
     *   an aggregation-only     size 0 means facet counting, and there is no page to slice.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function expandForExploration(array $result): array
    {
        $from = (int) ($result['body']['from'] ?? 0);
        $size = (int) ($result['body']['size'] ?? 0);
        if ($size <= 0 || !$this->exploration->isActive()) {
            return $result;
        }

        $primary = $this->primarySortField($result['body']['sort'] ?? []);
        if ($primary !== null && $primary !== '_score' && !str_starts_with($primary, 'position')) {
            return $result;
        }

        $window = $this->exploration->windowSize($size);
        if ($from >= $window) {
            return $result;
        }

        $this->requestScope->setExplorationWindow($from, $size);
        $result['body']['from'] = 0;
        $result['body']['size'] = max($from + $size, $window);

        return $result;
    }

    /**
     * @param array<int, mixed> $sort
     */
    private function primarySortField(array $sort): ?string
    {
        $first = $sort[0] ?? null;
        if (!is_array($first)) {
            return null;
        }

        $field = array_key_first($first);

        return $field === null ? null : (string) $field;
    }

    /**
     * Append `_score` as the LAST sort key, so a boost can actually move a product.
     *
     * Boosting score is inert on its own here, and that had to be measured to be believed: this
     * store sends `sort: [{"position_category_21": {"order": "asc"}}]` for a listing and
     * `[{"position_category_2": {"order": "asc"}}]` for a search. Neither mentions `_score`, so a
     * function_score that changes every relevant score changes nothing about the order. Turning the
     * impact dial to 100% moved not one product.
     *
     * LAST is the whole design, not a detail. The merchant's position is the merchandising order,
     * and the locked stage ordering puts merchandising rules after personalisation with the merchant
     * always winning. As a trailing key, personalisation can only break ties the merchant left
     * unbroken: where they have ordered products explicitly their sequence is untouched, and where
     * they have expressed no preference the shopper's own history decides. Putting `_score` first
     * would silently overrule merchandising on every page, which is exactly what the ADR forbids.
     *
     * @param array<int, mixed> $sort
     * @return array<int, mixed>
     */
    private function withScoreTiebreaker(array $sort): array
    {
        foreach ($sort as $clause) {
            if (is_array($clause) && array_key_exists('_score', $clause)) {
                return $sort;
            }
        }

        $sort[] = ['_score' => ['order' => 'desc']];

        return $sort;
    }
}
