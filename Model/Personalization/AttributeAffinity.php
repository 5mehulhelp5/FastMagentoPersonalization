<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Personalization;

/**
 * One attribute's affinity for one shopper: which values they choose, and — the part that matters —
 * how CONCENTRATED that choice is.
 *
 * Frequency alone is not enough, and getting this wrong is what makes personalisation feel random.
 * Three shoppers who each bought four jackets are three different people:
 *
 *   four black, all size L    -> real colour signal AND real size signal
 *   four colours, all size L  -> no colour signal; boosting colour here is actively wrong
 *   four black, four sizes    -> gift buyer or household; size is noise
 *
 * "Most common value" answers all three identically and is wrong twice. {@see $strength} is the
 * guard: it says how peaked the distribution is, so a caller can ignore an attribute this shopper
 * is plainly indifferent about.
 */
class AttributeAffinity
{
    /**
     * @param string $attributeCode
     * @param array<string, float> $values value => weight, summing to 1, highest first
     * @param float $strength 0..1 concentration, (topShare - 1/n) / (1 - 1/n). 1 = always the same
     *        value, 0 = spread evenly across the values they chose. NOT normalised entropy — that
     *        was the first attempt and it rated an indifferent shopper as having a preference.
     * @param float $confidence 0..1 from sample size — a strong affinity built from one purchase
     *        is worse than none.
     * @param int $observations how many purchased items fed this
     */
    public function __construct(
        private readonly string $attributeCode,
        private readonly array $values,
        private readonly float $strength,
        private readonly float $confidence,
        private readonly int $observations,
        /** @var array<string, string[]> winning label => the spellings folded into it */
        private readonly array $folded = []
    ) {
    }

    /**
     * Which spellings were counted as one, for anything that has to show its working.
     *
     * @return array<string, string[]>
     */
    public function getFolded(): array
    {
        return $this->folded;
    }

    public function getAttributeCode(): string
    {
        return $this->attributeCode;
    }

    /** @return array<string, float> */
    public function getValues(): array
    {
        return $this->values;
    }

    public function getStrength(): float
    {
        return $this->strength;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    public function getObservations(): int
    {
        return $this->observations;
    }

    /**
     * The single value worth boosting, or null when the shopper has shown no real preference.
     */
    public function getTopValue(): ?string
    {
        $top = array_key_first($this->values);

        return $top === null ? null : (string) $top;
    }

    /**
     * Whether this affinity should influence ranking at all.
     *
     * Both gates have to pass. Strength without confidence is one purchase masquerading as a
     * preference; confidence without strength is a shopper who buys every colour we sell.
     */
    public function isActionable(float $minStrength = 0.35, float $minConfidence = 0.5): bool
    {
        return $this->strength >= $minStrength && $this->confidence >= $minConfidence;
    }

    /**
     * Boost multiplier for a product whose value for this attribute is $value.
     *
     * Scaled by strength AND confidence, so a weak or thinly-evidenced affinity nudges rather than
     * shoves. Returns 0.0 (no opinion) rather than a negative — this is a boost model, never a
     * filter. See ROADMAP §4.
     */
    public function weightFor(string $value): float
    {
        if (!$this->isActionable()) {
            return 0.0;
        }

        return ($this->values[$value] ?? 0.0) * $this->strength * $this->confidence;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'attribute' => $this->attributeCode,
            'strength' => round($this->strength, 4),
            'confidence' => round($this->confidence, 4),
            'observations' => $this->observations,
            'actionable' => $this->isActionable(),
            'values' => array_map(static fn ($w) => round($w, 4), $this->values),
            // Recorded so a merchant can see that three spellings were treated as one value, and
            // disagree if they mean different things.
            'folded' => $this->folded,
        ];
    }
}
