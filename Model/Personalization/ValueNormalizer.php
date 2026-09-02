<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Personalization;

/**
 * Collapse the many spellings of one attribute value into a single key to count on.
 *
 * The problem this solves is arithmetic, not linguistic. A shopper who has bought `32x10R15`,
 * `32x10-15` and `32/10/15` has bought the same tyre three times, but the profile counts three
 * different values — so the concentration gate sees a shopper spread evenly across three options
 * and refuses to act, when in fact they could not be more consistent. Messy attribute data does not
 * make personalisation slightly worse; it inverts the verdict.
 *
 * DELIBERATELY DETERMINISTIC. The milestone this belongs to is called "AI assist", and the
 * temptation is to ask a model which values mean the same thing. For the cases that actually occur
 * — separator variance, case, spacing, unit suffixes, leading zeros — a model would be slower, more
 * expensive, non-reproducible, and no more correct than the rules below, which anyone can read and
 * predict. The project's own rule is that Python (and by extension a model) is added only if it
 * earns its place; see {@see \ParkkTech\FastMagentoPersonalization\Model\Personalization\AffinityCalculator} for
 * the same argument applied to the counting itself.
 *
 * What this genuinely CANNOT do is decide that "Charcoal" and "Dark Grey" are one colour, or that
 * "3/4 sleeve" and "Three Quarter Sleeve" are one sleeve. Those are semantic, they need knowledge
 * of the catalogue rather than of syntax, and they are the narrow residue where a model would earn
 * its keep. The normaliser reports what it could not fold ({@see explainUnfolded()}) so that
 * decision can be made from evidence rather than by assumption.
 *
 * Normalisation affects only the KEY used for counting. The label the shopper is shown, and the
 * option id used to build a query, are always the original — a merchant must never open the
 * inspector and find a value they do not sell.
 */
class ValueNormalizer
{
    /**
     * Canonical counting key for a value, or the trimmed original when nothing folds.
     *
     * Idempotent: normalising an already-normalised value returns it unchanged.
     */
    public function canonical(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $key = mb_strtolower($value);

        // Unicode punctuation that looks like ASCII and is not. Product data pasted from a
        // spreadsheet is full of en-dashes where someone typed a hyphen.
        $key = strtr($key, [
            "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-',
            "\u{2014}" => '-', "\u{2212}" => '-', "\u{00a0}" => ' ', "\u{2033}" => '"',
            "\u{201d}" => '"', "\u{2032}" => "'", "\u{2019}" => "'",
        ]);

        // A dimensional spec — the case the milestone names. Any of x, X, *, /, -, or spaces may
        // separate the parts, and an R or a quote mark may sit between them (`32x10R15`, `32/10/15`,
        // `32 x 10 - 15`). Reduced to digits joined by a single separator, so all three become
        // `32x10x15`. Requires at least two numeric parts, so a plain size is left alone.
        // Decimals are part of the number, not a separator — `33x12.50R15` is a real and common
        // tyre size, and treating the point as punctuation split it into `33x12 50r15`, which folds
        // with nothing and silently defeats the whole exercise for the domain the example came from.
        if (preg_match('/^\d+(?:\.\d+)?(?:\s*[xX*\/\-rR"\']+\s*\d+(?:\.\d+)?){1,3}$/u', $key)) {
            $parts = preg_split('/[^\d.]+/u', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $parts = array_values(array_filter($parts, static fn ($p) => $p !== '.'));
            if (count($parts) >= 2) {
                $parts = array_map(static function ($p) {
                    // Leading zeros are formatting, not meaning (`08` = `8`); trailing zeros after
                    // a decimal point likewise (`12.50` = `12.5`), so the two spellings of one size
                    // land on one key.
                    $p = ltrim($p, '0');
                    if (strpos($p, '.') !== false) {
                        $p = rtrim(rtrim($p, '0'), '.');
                    }

                    return $p === '' ? '0' : $p;
                }, $parts);

                return implode('x', $parts);
            }
        }

        // Everything else: fold whitespace and punctuation that carries no meaning, so
        // `Light Blue`, `light-blue` and `LIGHT  BLUE` count as one.
        //
        // A decimal point BETWEEN DIGITS is meaning, not punctuation — shoe size 8.5 is not size
        // "8 5", and splitting it there would fold 8.5 with nothing while quietly changing the
        // value a merchant sees. Protected before the punctuation pass and restored after.
        $key = preg_replace('/(?<=\d)\.(?=\d)/u', "\x00", $key) ?? $key;
        $key = preg_replace('/[\s_\-.]+/u', ' ', $key) ?? $key;
        $key = str_replace("\x00", '.', $key);

        return trim($key);
    }

    /**
     * Group values by canonical key, choosing the most common spelling as each group's label.
     *
     * The winner is what a merchant sees, so it should be the spelling their catalogue uses most,
     * not whichever happened to be encountered first.
     *
     * @param array<string, float> $weightsByValue original value => weight
     * @return array{weights: array<string, float>, folded: array<string, string[]>}
     *         weights keyed by the winning label, plus what was folded into it
     */
    public function fold(array $weightsByValue): array
    {
        $groups = [];
        foreach ($weightsByValue as $value => $weight) {
            $key = $this->canonical((string) $value);
            if ($key === '') {
                continue;
            }
            $groups[$key][(string) $value] = ($groups[$key][(string) $value] ?? 0.0) + (float) $weight;
        }

        $weights = [];
        $folded = [];
        foreach ($groups as $members) {
            arsort($members);
            $label = (string) array_key_first($members);
            $weights[$label] = array_sum($members);
            if (count($members) > 1) {
                $folded[$label] = array_keys($members);
            }
        }

        arsort($weights);

        return ['weights' => $weights, 'folded' => $folded];
    }

    /**
     * Values that remain distinct after folding, so the residue a model would have to handle can be
     * measured rather than guessed at.
     *
     * @param string[] $values
     * @return array<string, string[]> canonical key => the spellings that produced it
     */
    public function explainUnfolded(array $values): array
    {
        $byKey = [];
        foreach ($values as $value) {
            $key = $this->canonical((string) $value);
            if ($key !== '') {
                $byKey[$key][(string) $value] = true;
            }
        }

        return array_map(static fn (array $v) => array_keys($v), $byKey);
    }
}
