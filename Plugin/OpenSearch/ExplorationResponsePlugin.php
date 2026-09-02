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
            $window = $this->requestScope->takeExplorationWindow();
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

            $permuted = $this->exploration->permute($rankedIds, $window['size']);

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
}
