<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Personalization;

/**
 * The exploration slot — a bounded fraction of page one, lent to products that have not had a fair
 * hearing (PERSONALIZATION.md §5, the last of its three mechanisms).
 *
 * The loop this breaks was measured on this store, not assumed: the five most-shown products had
 * 484–491 impressions each and zero sales, while the product carrying 59 of 60 sales had been shown
 * 69 times. Exposure follows position, position follows past exposure, and a product that never
 * enters the loop never gets to demonstrate anything. Ranking cannot fix that, because ranking is
 * the loop. Only giving the product a slot can.
 *
 * HOW THE SLOT IS FILLED — deterministically, and that is a feature, not a compromise. The textbook
 * version of this is epsilon-greedy: give the slot to a RANDOM under-exposed candidate. Randomness
 * here would be strictly worse. It cannot be cached (every request a different page), it cannot be
 * reproduced (a merchant asking "why was this product on page one yesterday" gets a shrug), and it
 * is not fairer — it is just blind. Instead the slot goes to the LEAST-SHOWN candidates, and the
 * rotation the randomness was supposed to provide falls out of the feedback loop that already
 * exists: an elevated product is seen, its impression count rises, it stops being least-shown, and
 * the next candidate takes the slot at the next exposure rebuild. The impressions M4 collects are
 * what turn the crank.
 *
 * THE THREE RULES THAT BOUND IT:
 *
 *   the merchant's head is untouched   slots come off the END of page one. Positions 1..(S-K)
 *                                      render exactly as ranked, and K comes from a merchant dial
 *                                      that reaches zero.
 *   candidates MATCHED the query       they come from ranks just below the fold of the same result
 *                                      set — never injected from outside it. A product that does
 *                                      not match is not "explored", it is wrong.
 *   an explicit sort is sacred         a shopper who sorted by price gets price order, untouched.
 *                                      Exploration exists inside relevance/merchandising order
 *                                      only; callers enforce this and it is part of the contract.
 *
 * PAGINATION IS WHERE NAIVE VERSIONS LEAK, so the permutation is defined once, globally, and every
 * page slices it. Elevating a product onto page one without telling page two produces a duplicate
 * (the product appears again at its original rank) and a hole (the displaced product appears
 * nowhere). Instead {@see permute()} is a pure function of the ranked window: candidates come only
 * from ranks [S, 3S), land in slots [S-K, S), everything else keeps its relative order, and the
 * list is provably identical to the original from rank 3S onward. Any request for a page inside
 * that window recomputes the same permutation and slices it; any request beyond it skips
 * exploration entirely and sees the original ranking, which the permutation guarantees is the same
 * thing.
 */
class ExplorationSlot
{
    // (no per-page window multiplier: the window is flat — see windowSize())

    /** However generous the dial, most of page one belongs to ranking. */
    private const MAX_SLOT_SHARE = 0.5;

    /** Absolute ceiling on the expanded fetch, whatever the page size. */
    public const MAX_WINDOW = 144;

    public function __construct(
        private readonly PersonalizationConfig $config,
        private readonly ProductExposure $exposure
    ) {
    }

    public function isActive(?int $storeId = null): bool
    {
        return $this->config->isApplied($storeId)
            && $this->config->getExplorationPercent($storeId) > 0.0
            && $this->exposure->isAvailable($storeId);
    }

    /** How many of a page's slots the dial currently lends out. */
    public function slots(int $pageSize, ?int $storeId = null): int
    {
        $percent = $this->config->getExplorationPercent($storeId);
        if ($percent <= 0.0) {
            return 0;
        }

        $k = (int) round($pageSize * $percent / 100);

        return max(1, min($k, (int) floor($pageSize * self::MAX_SLOT_SHARE)));
    }

    /**
     * The fetch window a caller must rank before slicing.
     *
     * Flat at MAX_WINDOW rather than a multiple of the page size, and the first version got this
     * wrong in a way its own test caught: with a three-page window, a product the merchant appends
     * at the END of a category — position 999, the single most natural way new stock gets buried —
     * sat at rank 49 of 50 and was never a candidate. The window exists to reach the buried; a
     * window that scales with page size reaches less of the catalogue precisely when pages are
     * small, which is backwards.
     *
     * 144 docvalue-only hits cost OpenSearch nothing measurable. The honest limitation stands and
     * is documented rather than hidden: a catalogue burying new stock deeper than rank 144 is
     * beyond the slot's reach, and that is the point at which a merchant needs the exposure REPORT
     * (fastmagento:personalization:exposure --show) rather than the slot.
     */
    public function windowSize(int $pageSize): int
    {
        return max(self::MAX_WINDOW, $pageSize);
    }

    /**
     * The globally consistent exploration permutation of one ranked window.
     *
     * Identity whenever there is nothing honest to do: the dial at zero, no exposure table, no
     * ranks below the fold, or no candidate below the exposure floor. Callers can rely on
     * `$out === $ids` meaning "exploration changed nothing".
     *
     * @param array<int, int> $ids ranked product ids, from rank 0
     * @return array<int, int> the same ids, with slot candidates moved into [S-K, S)
     */
    public function permute(array $ids, int $pageSize, ?int $storeId = null): array
    {
        $ids = array_values($ids);
        $count = count($ids);
        if ($pageSize <= 0 || $count <= $pageSize || !$this->isActive($storeId)) {
            return $ids;
        }

        $k = $this->slots($pageSize, $storeId);
        if ($k <= 0) {
            return $ids;
        }

        // Candidates: below the fold, inside the window. Least-shown first is the selection AND
        // the fairness argument — see the class comment on why this beats a random pick.
        $window = array_slice($ids, $pageSize, $this->windowSize($pageSize) - $pageSize);
        $elevated = array_slice($this->exposure->underExposed($window, $storeId), 0, $k);
        if (!$elevated) {
            return $ids;
        }

        $isElevated = array_flip($elevated);
        $keep = [];
        foreach ($ids as $id) {
            if (!isset($isElevated[$id])) {
                $keep[] = $id;
            }
        }

        // Head unchanged, slots filled, everyone displaced keeps their relative order below.
        $cut = $pageSize - count($elevated);

        return array_merge(
            array_slice($keep, 0, $cut),
            $elevated,
            array_slice($keep, $cut)
        );
    }
}
