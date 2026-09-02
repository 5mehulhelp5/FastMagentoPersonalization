<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Personalization;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Search\EngineResolverInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagentoPersonalization\Model\Analytics\AbReport;

/**
 * Self-tunes the personalisation weight from POOLED evidence — the "Auto" behind the dial.
 *
 * The first version of this hill-climbed on last night versus the night before, and its own demo
 * showed why that is wrong: nightly conversion is noisy enough that the walk wandered while the
 * per-setting chart — every night at a weight pooled together — showed the truth clearly. So the
 * tuner now decides on exactly the evidence that chart draws ({@see AbReport::performanceByWeight()},
 * one method for both, so the dashboard and the tuner can never disagree about what the store has
 * learned), and it is DELIBERATELY SLOW, because chasing the perfect setting through noise is how
 * an optimiser turns weather into policy:
 *
 *   it dwells        a setting is judged only after DWELL_NIGHTS disjoint nights and a pooled
 *                    session floor. Until then the answer is "stay — still collecting", however
 *                    tempting yesterday looked.
 *   it needs a margin a move toward another setting needs that setting's POOLED lift to beat the
 *                    current one's by MIN_LIFT_ADVANTAGE — five whole points of lift. Anything
 *                    smaller is indistinguishable from weekend traffic, and a tuner that acts on
 *                    it will spend its life un-doing itself.
 *   it steps once,   0.05 per night at most, and only after the dwell — so in practice the weight
 *   and small        changes every few days by a twentieth. There is no step-doubling and no
 *                    momentum; the evidence carries the speed, not the algorithm.
 *   it explores      only from boredom, never from excitement: after EXPLORE_AFTER nights at a
 *   reluctantly      setting no measured alternative beats, it tries one adjacent step on the
 *                    less-measured side — because never exploring means the best weight is simply
 *                    whichever one was tried first.
 *
 * Each nightly record measures the LAST DAY ONLY, and this is load-bearing: the first version
 * measured a rolling week each night, so pooling nights would have counted every day seven times.
 * Disjoint windows are what make "sum the sessions, sum the orders" honest. The record carries
 * `measured_weight` — the weight the night actually ran at — because the chosen weight starts
 * tomorrow, and crediting tonight's sales to it would shift every bucket by one night.
 *
 * The rest is unchanged: refuses without a comparison group, weight clamped [0.25, 3.0], every
 * decision written with its reason (the dashboard's tuning log is the audit trail), and the chosen
 * weight written to config so the request path reads it for free.
 */
class AutoWeightTuner
{
    /** Nights a setting must run before it is judged at all. */
    private const DWELL_NIGHTS = 3;

    /** Pooled shoppers-with-personalisation a bucket needs before its rate means anything. */
    private const MIN_SESSIONS_POOLED = 150;

    /** Pooled lift points another setting must win by before the tuner moves toward it. */
    private const MIN_LIFT_ADVANTAGE = 0.05;

    /** Nights at an unbeaten setting before one exploratory step is permitted. */
    private const EXPLORE_AFTER = 7;

    private const STEP = 0.05;

    private const WEIGHT_MIN = 0.25;
    private const WEIGHT_MAX = 3.0;

    public function __construct(
        private readonly PersonalizationConfig $config,
        private readonly AbReport $report,
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly OpenSearchConfig $openSearchConfig,
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    /**
     * One nightly step. Returns the record it wrote, for the CLI to print.
     *
     * @return array<string, mixed>
     */
    public function tune(int $windowDays = 1): array
    {
        $weight = $this->config->autoWeightValue();
        $night = $this->report->summary(max(1, $windowDays));

        if ($this->config->getWeightMode() !== 'auto') {
            return $this->recordDecision($weight, $weight, $night, 'hold', 'weight mode is not Auto');
        }
        if (!$this->config->isAbTestEnabled()) {
            return $this->recordDecision($weight, $weight, $night, 'hold', 'no comparison group — enable the A/B test, or tuning would chase noise');
        }

        // Pool everything measured so far, INCLUDING tonight's record (written below with tonight's
        // numbers) — the maths here just reads history plus the summary in hand.
        $buckets = [];
        foreach ($this->report->performanceByWeight() as $bucket) {
            $buckets[(string) (int) round($bucket['weight'] / self::STEP)] = $bucket;
        }
        $current = $this->mergeNightIntoBucket(
            $buckets[(string) (int) round($weight / self::STEP)] ?? null,
            $weight,
            $night
        );

        // Judge nothing until the current setting has really been tried.
        if ($current['nights'] < self::DWELL_NIGHTS || $current['sessions'] < self::MIN_SESSIONS_POOLED) {
            return $this->recordDecision($weight, $weight, $night, 'stay', sprintf(
                'collecting evidence at %.2f× — %d of %d night(s), %d of %d shopper(s)',
                $weight,
                $current['nights'],
                self::DWELL_NIGHTS,
                $current['sessions'],
                self::MIN_SESSIONS_POOLED
            ));
        }

        // The best OTHER setting that has also earned an opinion.
        $best = null;
        foreach ($buckets as $bucket) {
            if (abs($bucket['weight'] - $weight) < 0.001
                || $bucket['nights'] < self::DWELL_NIGHTS
                || $bucket['sessions'] < self::MIN_SESSIONS_POOLED
                || $bucket['lift'] === null
            ) {
                continue;
            }
            if ($best === null || $bucket['lift'] > $best['lift']) {
                $best = $bucket;
            }
        }

        $currentLift = $current['lift'];

        if ($best !== null && $currentLift !== null && $best['lift'] >= $currentLift + self::MIN_LIFT_ADVANTAGE) {
            // A measured setting is convincingly better: one small step TOWARD it. Not a jump to
            // it — the evidence says "that direction", and the intermediate weights deserve their
            // own hearing on the way.
            $newWeight = $this->clampStep($weight, $best['weight'] > $weight ? 1 : -1);

            return $this->recordDecision($newWeight, $weight, $night, $newWeight > $weight ? 'raise' : 'lower', sprintf(
                'step toward %.2f× — pooled lift there %.1f%% over %d night(s), vs %.1f%% here; margin exceeds %d points',
                $best['weight'],
                $best['lift'] * 100,
                $best['nights'],
                $currentLift * 100,
                (int) (self::MIN_LIFT_ADVANTAGE * 100)
            ));
        }

        if ($current['nights'] >= self::EXPLORE_AFTER) {
            // Nothing measured beats this setting, and it has been sat on for a week: one
            // exploratory step toward the side we know less about. Reluctant on purpose — but a
            // tuner that never explores anoints whichever weight came first.
            $direction = $this->lessMeasuredSide($buckets, $weight);
            $newWeight = $this->clampStep($weight, $direction);
            if (abs($newWeight - $weight) > 0.001) {
                return $this->recordDecision($newWeight, $weight, $night, 'explore', sprintf(
                    '%d night(s) at %.2f× with no better setting measured — trying %.2f× to widen the evidence',
                    $current['nights'],
                    $weight,
                    $newWeight
                ));
            }
        }

        return $this->recordDecision($weight, $weight, $night, 'hold', sprintf(
            '%.2f× is the best measured setting (pooled lift %s over %d night(s)) — gathering more nights',
            $weight,
            $currentLift !== null ? sprintf('%.1f%%', $currentLift * 100) : '—',
            $current['nights']
        ));
    }

    /**
     * Tonight's numbers folded into the current weight's pooled bucket, so the decision sees the
     * night it is about to record rather than always running one night behind.
     *
     * @param array<string, mixed>|null $bucket
     * @param array<string, mixed> $night
     * @return array{nights: int, sessions: int, orders: int, sessions_control: int, orders_control: int, lift: float|null}
     */
    private function mergeNightIntoBucket(?array $bucket, float $weight, array $night): array
    {
        $p = $night['arms']['personalized'] ?? [];
        $c = $night['arms']['control'] ?? [];

        $out = [
            'nights' => (int) ($bucket['nights'] ?? 0) + 1,
            'sessions' => (int) ($bucket['sessions'] ?? 0) + (int) ($p['sessions'] ?? 0),
            'orders' => (int) ($bucket['orders'] ?? 0) + (int) ($p['orders'] ?? 0),
            'sessions_control' => (int) ($bucket['sessions_control'] ?? 0) + (int) ($c['sessions'] ?? 0),
            'orders_control' => (int) ($bucket['orders_control'] ?? 0) + (int) ($c['orders'] ?? 0),
            'lift' => null,
        ];

        $rateP = $out['sessions'] > 0 ? $out['orders'] / $out['sessions'] : null;
        $rateC = $out['sessions_control'] > 0 ? $out['orders_control'] / $out['sessions_control'] : null;
        if ($rateP !== null && $rateC !== null && $rateC > 0) {
            $out['lift'] = $rateP / $rateC - 1;
        }

        return $out;
    }

    /**
     * Which adjacent step has less evidence behind it — where exploration teaches the most.
     */
    private function lessMeasuredSide(array $buckets, float $weight): int
    {
        $nightsAt = function (float $w) use ($buckets): int {
            $bucket = $buckets[(string) (int) round($w / self::STEP)] ?? null;

            return $bucket === null ? 0 : (int) $bucket['nights'];
        };

        $up = $weight + self::STEP <= self::WEIGHT_MAX ? $nightsAt($weight + self::STEP) : PHP_INT_MAX;
        $down = $weight - self::STEP >= self::WEIGHT_MIN ? $nightsAt($weight - self::STEP) : PHP_INT_MAX;

        if ($up === $down) {
            // Both sides equally unknown: widen upward first — the dial's lower half is closer to
            // "off", which the merchant can already reach by hand.
            return $up === PHP_INT_MAX ? -1 : 1;
        }

        return $up < $down ? 1 : -1;
    }

    private function clampStep(float $weight, int $direction): float
    {
        return round(max(self::WEIGHT_MIN, min(self::WEIGHT_MAX, $weight + $direction * self::STEP)), 3);
    }

    /**
     * @param array<string, mixed>|null $summary
     * @return array<string, mixed>
     */
    private function recordDecision(
        float $chosenWeight,
        float $measuredWeight,
        ?array $summary,
        string $action,
        string $reason
    ): array {
        $p = $summary['arms']['personalized'] ?? [];
        $c = $summary['arms']['control'] ?? [];

        $doc = [
            'created_at' => gmdate('c'),
            'weight' => $chosenWeight,
            // The weight tonight's numbers were produced UNDER — the pooling key. The chosen
            // weight starts tomorrow.
            'measured_weight' => $measuredWeight,
            'action' => $action,
            'reason' => $reason,
            'lift' => $summary['lift'] ?? null,
            'sessions_personalized' => (int) ($p['sessions'] ?? 0),
            'sessions_control' => (int) ($c['sessions'] ?? 0),
            'orders_personalized' => (int) ($p['orders'] ?? 0),
            'orders_control' => (int) ($c['orders'] ?? 0),
            'conversion_personalized' => $p['conversion'] ?? null,
            'conversion_control' => $c['conversion'] ?? null,
            'revenue_personalized' => (float) ($p['revenue'] ?? 0),
            'revenue_control' => (float) ($c['revenue'] ?? 0),
        ];

        try {
            $client = $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
            $index = $this->openSearchConfig->getTuningIndexName();
            if (!$client->indexExists($index)) {
                $client->createIndex($index, [
                    'settings' => ['number_of_shards' => 1],
                    'mappings' => ['properties' => [
                        'created_at' => ['type' => 'date'],
                        'weight' => ['type' => 'double'],
                        'measured_weight' => ['type' => 'double'],
                        'action' => ['type' => 'keyword'],
                        'lift' => ['type' => 'double'],
                    ]],
                ]);
            }
            $client->getOpenSearchClient()->index(['index' => $index, 'body' => $doc]);
            $client->getOpenSearchClient()->indices()->refresh(['index' => $index]);
        } catch (\Throwable $e) {
            // A decision that cannot be recorded still applies; the dashboard just has a gap.
        }

        if (abs($chosenWeight - $this->config->autoWeightValue()) > 0.001) {
            $this->configWriter->save('fastmagento/personalization/auto_weight_value', (string) $chosenWeight);
            $this->cacheTypeList->cleanType(\Magento\Framework\App\Cache\Type\Config::TYPE_IDENTIFIER);
        }

        return $doc;
    }
}
