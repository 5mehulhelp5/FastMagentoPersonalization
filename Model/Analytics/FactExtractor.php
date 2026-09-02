<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Analytics;

use ParkkTech\FastMagentoPersonalization\Model\Personalization\ValueNormalizer;

/**
 * Pull a stated REQUIREMENT out of what a shopper typed, for catalogues that have no attribute to
 * hold it.
 *
 * Affinities answer "what do they like". Facts answer "what must fit", and the two are governed
 * differently: a fact is allowed to filter where an affinity may only boost. Somebody searching
 * "sheets for a queen bed" or "filters for a 2021 model" has told the store the single most useful
 * thing about themselves, on their first visit, before any purchase exists to infer from — and on
 * most catalogues it lands in a free-text search box and is thrown away.
 *
 * COLD START IS THE POINT. A profile built from order history knows nothing about a first-time
 * visitor. One search is thin evidence, so a fact extracted this way is stored at LOW confidence,
 * attributed to its source, and inspectable/clearable operator-side
 * (`fastmagento:profile:inspect --forget-facts`). Being wrong about what fits someone's need is
 * worse than knowing nothing, so this proposes; it does not decide.
 *
 * WHAT THIS DOES AND DOES NOT DO, honestly. It recognises SHAPES: dimensional specs, and four-digit
 * years in a plausible range. It does not know what a brand's model name refers to — that is
 * semantic, it needs a catalogue-specific vocabulary, and it is precisely the residue where a model
 * earns its place. What is built here is the part that works everywhere without one, and the seam where that
 * model would attach: {@see extract()} returns candidates with a source and a confidence, and a
 * model-backed extractor would add to the same list rather than replace it.
 */
class FactExtractor
{
    /** One search is thin evidence. Stated-in-a-form facts should outrank this comfortably. */
    public const CONFIDENCE_FROM_SEARCH = 0.35;

    private const YEAR_MIN = 1950;

    public function __construct(
        private readonly ValueNormalizer $normalizer
    ) {
    }

    /**
     * Candidate facts in a query, keyed by fact name.
     *
     * @return array<string, array{value: string, confidence: float, source: string, evidence: string}>
     */
    public function extract(string $query): array
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');
        if ($query === '') {
            return [];
        }

        $facts = [];

        // A dimensional spec — a tyre, a wheel, a hose, a filter. Normalised through the same
        // folding the affinities use, so "32x10R15" typed here and "32x10-15" in the catalogue are
        // one fact rather than two.
        // The separator class must match the normaliser's, or the two disagree about the same
        // string: `R` is a separator in a tyre size, and leaving it out meant `32x10R15` — the
        // example this milestone is written around — matched nothing at all, while `32/10/15`
        // matched fine. A trailing \b also fails against `…R15` because R is a word character, so
        // the boundary is asserted only where it is meaningful.
        if (preg_match('/(?<![\w.])\d+(?:\.\d+)?(?:\s*[xX*\/\-rR"\']\s*\d+(?:\.\d+)?){1,3}(?![\w.])/u', $query, $m)) {
            $facts['dimension'] = [
                'value' => $this->normalizer->canonical($m[0]),
                'confidence' => self::CONFIDENCE_FROM_SEARCH,
                'source' => 'search',
                'evidence' => $m[0],
            ];
        }

        // A model year. Bounded at both ends: an unbounded four-digit match would read part numbers
        // and prices as years, and a fact that confident and that wrong is worse than none.
        $maxYear = (int) gmdate('Y') + 2;
        if (preg_match_all('/\b(\d{4})\b/u', $query, $all)) {
            foreach ($all[1] as $candidate) {
                $year = (int) $candidate;
                if ($year >= self::YEAR_MIN && $year <= $maxYear) {
                    $facts['year'] = [
                        'value' => (string) $year,
                        'confidence' => self::CONFIDENCE_FROM_SEARCH,
                        'source' => 'search',
                        'evidence' => $candidate,
                    ];
                    break;
                }
            }
        }

        return $facts;
    }
}
