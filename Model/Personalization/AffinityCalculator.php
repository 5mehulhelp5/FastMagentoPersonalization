<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Personalization;

/**
 * Turns "the attribute values this shopper actually bought" into weighted affinities with a
 * concentration guard.
 *
 * Pure arithmetic on purpose. There is no model here and there should not be: counting, decay and
 * entropy are fast, debuggable, free, and more accurate than asking an LLM to do a weighted count.
 * AI belongs upstream of this — normalising `32x10R15` / `32x10-15` / `32/10/15` into one value so
 * the count is of one thing rather than three (see USER-INTELLIGENCE.md).
 */
class AffinityCalculator
{
    public function __construct(
        private readonly ValueNormalizer $normalizer
    ) {
    }

    /** Purchases below this and confidence is scaled down — one purchase is not a preference. */
    private const CONFIDENT_AT_OBSERVATIONS = 4;

    /**
     * Build affinities for every attribute present in the purchase history.
     *
     * @param array<int, array{values: array<string, string>, weight?: float}> $purchases
     *        One entry per purchased item: its attribute code => value map, and an optional weight
     *        (recency decay, quantity — anything the caller wants to count it by).
     * @param array<string, int> $cardinality attribute code => distinct values the catalogue
     *        offers. Reported for context only; deliberately NOT part of the concentration
     *        measure — see computeStrength().
     * @return array<string, AttributeAffinity> keyed by attribute code, strongest first
     */
    public function calculate(array $purchases, array $cardinality = []): array
    {
        /** @var array<string, array<string, float>> $totals */
        $totals = [];
        /** @var array<string, float> $observed */
        $observed = [];

        foreach ($purchases as $purchase) {
            $weight = (float) ($purchase['weight'] ?? 1.0);
            if ($weight <= 0) {
                continue;
            }
            foreach ($purchase['values'] ?? [] as $code => $value) {
                // A value may be a LIST — a product belongs to several categories at once. Split
                // the item's weight across them rather than counting each at full weight, or a
                // product filed in eight categories would outvote one filed in two purely by being
                // over-categorised. The item is still one purchase however it is classified.
                $values = is_array($value) ? $value : [$value];
                $values = array_values(array_filter(array_map(
                    static fn ($v) => trim((string) $v),
                    $values
                ), static fn ($v) => $v !== ''));

                if (!$values) {
                    continue;
                }

                $share = $weight / count($values);
                foreach ($values as $single) {
                    $totals[$code][$single] = ($totals[$code][$single] ?? 0.0) + $share;
                }
                $observed[$code] = ($observed[$code] ?? 0.0) + $weight;
            }
        }

        $affinities = [];
        foreach ($totals as $code => $valueWeights) {
            $sum = $observed[$code];
            if ($sum <= 0) {
                continue;
            }

            // Fold the spellings of one value together BEFORE measuring concentration. A shopper
            // who bought 32x10R15, 32x10-15 and 32/10/15 bought one tyre three times; counted as
            // three values they look perfectly indifferent, and the gate refuses to act on the most
            // consistent shopper in the catalogue. The winning LABEL is the spelling the data uses
            // most, so a merchant never sees a value they do not sell.
            $folded = $this->normalizer->fold($valueWeights);

            $normalised = [];
            foreach ($folded['weights'] as $value => $weight) {
                $normalised[$value] = $weight / $sum;
            }
            arsort($normalised);

            $affinities[$code] = new AttributeAffinity(
                $code,
                $normalised,
                $this->computeStrength($normalised),
                $this->computeConfidence($sum),
                (int) round($sum),
                $folded['folded']
            );
        }

        uasort(
            $affinities,
            static fn (AttributeAffinity $a, AttributeAffinity $b) => $b->getStrength() <=> $a->getStrength()
        );

        return $affinities;
    }

    /**
     * Concentration: how far the shopper's choices lean towards ONE value, versus being spread
     * evenly across the values they picked.
     *
     * Measured as the top value's share above what an indifferent shopper would produce:
     *
     *     excess = (topShare - 1/n) / (1 - 1/n)
     *
     * 0 when every chosen value is equally likely (no favourite — do not boost anything), 1 when
     * they always pick the same value.
     *
     * NOT normalised Shannon entropy against catalogue cardinality, which was the first attempt
     * and is wrong in a way that only shows up under test: it conflates BREADTH (how much of the
     * range they explored) with PEAKEDNESS (whether they favour one value). A shopper who bought
     * one each of three colours out of twelve scored 0.56 and was ranked "actionable" — they have
     * no favourite colour at all, and boosting one of them would be inventing a preference. This
     * metric scores that case 0, which is the honest answer.
     *
     * Catalogue cardinality is still reported alongside for context, but it does not belong in the
     * concentration measure.
     *
     * @param array<string, float> $distribution normalised, sums to 1, highest first
     */
    private function computeStrength(array $distribution): float
    {
        $distinct = count($distribution);
        if ($distinct <= 1) {
            // One value every time — perfectly concentrated. Whether that is MEANINGFUL is the
            // confidence gate's job, not this one's.
            return 1.0;
        }

        $topShare = (float) reset($distribution);
        $uniform = 1.0 / $distinct;

        return max(0.0, min(1.0, ($topShare - $uniform) / (1.0 - $uniform)));
    }

    /**
     * How much to trust the affinity, from sample size. Linear to a floor of evidence, then flat —
     * the difference between four purchases and forty matters far less than the difference between
     * one and four.
     */
    private function computeConfidence(float $observations): float
    {
        return max(0.0, min(1.0, $observations / self::CONFIDENT_AT_OBSERVATIONS));
    }
}
