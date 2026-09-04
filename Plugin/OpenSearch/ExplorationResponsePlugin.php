<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Plugin\OpenSearch;

use Magento\Elasticsearch\SearchAdapter\ResponseFactory;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\ExplorationSlot;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\RequestScope;

/**
 * The response half of the exploration slot: permute the widened ranking, hand back the page.
 *
 * The request-side plugin ({@see PersonalizeSearchRequest::expandForExploration()}) asked
 * OpenSearch for the whole exploration window instead of one page, and left a note in RequestScope
 * saying which page the shopper actually wants. This runs just before Magento turns the raw hits
 * into Documents, applies {@see ExplorationSlot::permute()} to the full ranked window, and slices
 * the requested page back out — so downstream receives exactly the page shape it always received,
 * with the slot filled.
 *
 * SCORES ARE REWRITTEN to descend in the permuted order, and this is load-bearing rather than
 * cosmetic. Downstream consumers disagree about what ranks a result: some preserve document order,
 * some re-sort by `_score`. After a permutation the two disagree — the elevated product's real
 * score is a tail score — so whichever kind of consumer is reading, monotonic scores make both
 * arrive at the same page. The absolute values are meaningless (they always were; a function_score
 * multiplier saw to that) and nothing downstream reads them as anything but an ordering.
 *
 * The note is consumed once. A request can run more than one search — layered-navigation
 * aggregations, widgets — and only the search that was widened may be sliced; a second search
 * finding a stale note would slice a response that was never expanded, which is a corrupted page
 * rather than a missing feature.
 *
 * `total` passes through untrue-to-the-fetch and true-to-the-query, which is the correct pair: the
 * pager shows how many products MATCH, and that was never changed — only how many were fetched.
 */
class ExplorationResponsePlugin
{
    public function __construct(
        private readonly ExplorationSlot $exploration,
        private readonly RequestScope $requestScope
    ) {
    }

    /**
     * @param array<string, mixed> $response
     * @return array{0: array<string, mixed>}
     */
    public function beforeCreate(ResponseFactory $subject, $response): array
    {
        try {
            $rerank = $this->requestScope->takeRerankWindow();
            $window = $this->requestScope->takeExplorationWindow() ?? $rerank;
            if ($window === null || !is_array($response)) {
                return [$response];
            }

            $documents = $response['documents'] ?? [];
            if (!is_array($documents) || count($documents) <= $window['size']) {
                // The catalogue ran out before the fold — one page's worth of matches or fewer, so
                // there is nothing below the fold to explore and nothing to slice.
                return [$response];
            }

            $byId = [];
            $rankedIds = [];
            foreach ($documents as $document) {
                // Two shapes, and the second is what this store actually returns. Magento queries
                // with `stored_fields: _none_` and docvalue fields, so a hit carries NO top-level
                // `_id` — the id arrives as `fields._id[0]`, which is exactly the case the core
                // ResponseFactory's own fallback exists for. Reading only `_id` resolved every
                // document to zero and sliced the entire page away: an empty listing with a
                // healthy engine, healthy query and healthy total.
                $id = (int) ($document['_id'] ?? ($document['fields']['_id'][0] ?? 0));
                if ($id > 0) {
                    $byId[$id] = $document;
                    $rankedIds[] = $id;
                }
            }

            if (count($rankedIds) !== count($documents)) {
                // A hit whose id cannot be resolved is a shape this code does not understand, and
                // permuting around it would drop it. Whole pages beat clever fragments.
                return [$response];
            }

            if ($rerank !== null) {
                $rankedIds = $this->rerankByPrior($rankedIds, $byId, (int) $rerank['band'], (float) $rerank['strength']);
            }
            $permuted = $this->exploration->isActive()
                ? $this->exploration->permute($rankedIds, $window['size'])
                : $rankedIds;

            $page = array_slice($permuted, $window['from'], $window['size']);

            // Monotonic scores over the whole permuted window, so the page's scores are consistent
            // with where it sits in the pagination, not just internally.
            $scoreBase = count($permuted);
            $out = [];
            foreach ($page as $position => $id) {
                $document = $byId[$id];
                $document['_score'] = (float) ($scoreBase - $window['from'] - $position);
                $out[] = $document;
            }

            $response['documents'] = $out;

            return [$response];
        } catch (\Throwable $e) {
            // A broken slice must never cost the shopper their search results.
            return [$response];
        }
    }

    /**
     * Position-aware personalised order: final = prior(rank) × lift.
     *
     * prior(rank) = exp(-rank / band): the merchant's order as a decaying prior, so a product
     * `band` positions down needs a lift of e to draw level with position one — and a product
     * with no personal lift keeps exactly its merchant rank relative to its neighbours.
     * lift = 1 + strength × (score / floor − 1): the shopper's boosts, read from the `_score`
     * OpenSearch computed alongside the position sort (track_scores) — the function_score
     * multiplier over the window's floor score, so an un-boosted product has lift 1 whatever the
     * base score happened to be. Stable: equal finals keep merchant order.
     *
     * @param int[] $rankedIds merchant order (position sort)
     * @param array<int, array<string, mixed>> $byId
     * @return int[]
     */
    private function rerankByPrior(array $rankedIds, array $byId, int $band, float $strength): array
    {
        if ($band <= 0 || $strength <= 0.0 || count($rankedIds) < 2) {
            return $rankedIds;
        }
        $scores = [];
        foreach ($rankedIds as $id) {
            $scores[$id] = (float) ($byId[$id]['_score'] ?? 0.0);
        }
        $floor = min($scores);
        if ($floor <= 0.0) {
            return $rankedIds;   // no scores came back — nothing to rank on, merchant order stands
        }
        $final = [];
        $rankOf = array_flip($rankedIds);
        foreach ($rankedIds as $rank => $id) {
            $lift = 1.0 + $strength * (($scores[$id] / $floor) - 1.0);
            $final[$id] = exp(-$rank / $band) * $lift;
        }
        $order = $rankedIds;
        usort($order, static function (int $a, int $b) use ($final, $rankOf): int {
            $cmp = $final[$b] <=> $final[$a];
            return $cmp !== 0 ? $cmp : ($rankOf[$a] <=> $rankOf[$b]);
        });
        return $order;
    }
}
