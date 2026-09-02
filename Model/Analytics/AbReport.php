<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Analytics;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\Search\EngineResolverInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;

/**
 * The numbers behind the dashboard: sessions, orders, revenue and conversion, per arm, per day.
 *
 * Computed from the events index at read time — no report tables, no ETL, nothing to drift out of
 * sync with the data it summarises. Every event carries its arm (stamped at collection, derived
 * from the same cookie hash that decides serving), so the whole report is one date-histogram
 * aggregation with an arms sub-bucket.
 *
 * A SESSION here is "a shopper seen that day" — daily-unique visitors, counted by cardinality of
 * the analytics cookie. Deliberately not Magento's session concept: PHP sessions expire hourly and
 * rotate on login, which would make the denominator an artefact of session config rather than a
 * count of people. Conversion = shoppers who ordered that day / shoppers seen that day, per arm —
 * the same definition on both sides of the split, which is the only property that matters.
 *
 * Events with no arm (cookie-less clients) are excluded from BOTH sides. They cannot be attributed
 * to an experience, so they belong in neither denominator; leaving them in would dilute whichever
 * arm the query happened to lump them into.
 */
class AbReport
{
    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly OpenSearchConfig $config
    ) {
    }

    /**
     * Daily metrics per arm over a window.
     *
     * @return array<string, array<string, array{sessions: int, buyers: int, orders: int, revenue: float, conversion: float|null}>>
     *         date => arm => metrics
     */
    public function daily(int $days = 30): array
    {
        try {
            $response = $this->client()->getOpenSearchClient()->search([
                'index' => $this->config->getEventIndexName(),
                'body' => [
                    'size' => 0,
                    'query' => [
                        'bool' => [
                            'must' => [
                                ['range' => ['created_at' => ['gte' => 'now-' . max(1, $days) . 'd/d']]],
                                ['exists' => ['field' => 'arm']],
                            ],
                        ],
                    ],
                    'aggs' => [
                        'days' => [
                            'date_histogram' => ['field' => 'created_at', 'calendar_interval' => 'day'],
                            'aggs' => [
                                'arms' => [
                                    'terms' => ['field' => 'arm', 'size' => 2],
                                    'aggs' => [
                                        'sessions' => ['cardinality' => ['field' => 'anon_id']],
                                        'orders' => ['filter' => ['term' => ['type' => 'order']],
                                            'aggs' => [
                                                'buyers' => ['cardinality' => ['field' => 'anon_id']],
                                                'revenue' => ['sum' => ['field' => 'revenue']],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($response['aggregations']['days']['buckets'] ?? [] as $day) {
            $date = substr((string) ($day['key_as_string'] ?? ''), 0, 10);
            if ($date === '') {
                continue;
            }
            foreach ($day['arms']['buckets'] ?? [] as $armBucket) {
                $arm = (string) ($armBucket['key'] ?? '');
                $sessions = (int) ($armBucket['sessions']['value'] ?? 0);
                $buyers = (int) ($armBucket['orders']['buyers']['value'] ?? 0);
                $out[$date][$arm] = [
                    'sessions' => $sessions,
                    'buyers' => $buyers,
                    'orders' => (int) ($armBucket['orders']['doc_count'] ?? 0),
                    'revenue' => round((float) ($armBucket['orders']['revenue']['value'] ?? 0.0), 2),
                    // Buyers over shoppers, both daily-unique. Null when nobody was seen — a rate
                    // with a zero denominator is not zero, it is no data.
                    'conversion' => $sessions > 0 ? round($buyers / $sessions, 4) : null,
                ];
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * The whole window rolled up per arm, plus the headline: lift.
     *
     * @return array<string, mixed>
     */
    public function summary(int $days = 30): array
    {
        $daily = $this->daily($days);

        $totals = [];
        foreach ($daily as $arms) {
            foreach ($arms as $arm => $row) {
                $t = $totals[$arm] ?? ['sessions' => 0, 'buyers' => 0, 'orders' => 0, 'revenue' => 0.0];
                $t['sessions'] += $row['sessions'];
                $t['buyers'] += $row['buyers'];
                $t['orders'] += $row['orders'];
                $t['revenue'] += $row['revenue'];
                $totals[$arm] = $t;
            }
        }

        foreach ($totals as $arm => $t) {
            $totals[$arm]['conversion'] = $t['sessions'] > 0 ? round($t['buyers'] / $t['sessions'], 4) : null;
            $totals[$arm]['revenue'] = round($t['revenue'], 2);
        }

        $p = $totals['personalized']['conversion'] ?? null;
        $c = $totals['control']['conversion'] ?? null;

        return [
            'days' => $days,
            'arms' => $totals,
            // Lift is only a number when both arms have one. "Infinite lift over a control nobody
            // visited" is how dashboards lie.
            'lift' => ($p !== null && $c !== null && $c > 0) ? round($p / $c - 1, 4) : null,
        ];
    }

    /**
     * The tuner's history — weight over time with the conversion it observed, for the weight curve.
     *
     * @return array<int, array<string, mixed>> oldest first
     */
    public function tuningHistory(int $limit = 90): array
    {
        try {
            $response = $this->client()->getOpenSearchClient()->search([
                'index' => $this->config->getTuningIndexName(),
                'body' => [
                    'size' => max(1, $limit),
                    'sort' => [['created_at' => 'desc']],
                ],
            ]);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            if (isset($hit['_source'])) {
                $out[] = $hit['_source'];
            }
        }

        return array_reverse($out);
    }

    /**
     * The tuning history rolled up BY THE WEIGHT EACH NIGHT ACTUALLY RAN AT — pooled, not averaged.
     *
     * This is the evidence the dashboard's "which weight sells best" chart draws and the evidence
     * the auto-tuner decides on, and it is one method so the two can never disagree about what the
     * store has learned.
     *
     * Pooled means summed sessions and orders per bucket, with the rate computed once at the end —
     * not an average of nightly rates, which would let a 90-session Sunday vote with the same
     * strength as a 600-session Monday. Buckets are 0.05 wide because that is the tuner's step
     * grid. Lift is pooled the same way, both sides summed within the bucket's nights, which also
     * cancels day-of-week effects: every night contributes its own control.
     *
     * `measured_weight` — the weight in effect WHILE the night's numbers were produced — is the
     * bucketing key. The recorded `weight` is the weight chosen at the END of the night, so using
     * it would credit every night's sales to the setting that had not started yet: an off-by-one
     * that quietly shifts the whole chart. Older records without the field fall back to the
     * previous record's chosen weight, which is the same value by construction.
     *
     * @return array<int, array{weight: float, nights: int, sessions: int, orders: int,
     *         sessions_control: int, orders_control: int, conversion: float|null,
     *         lift: float|null, revenue: float}> ordered by weight ascending
     */
    public function performanceByWeight(): array
    {
        $buckets = [];
        $previousChosen = null;

        foreach ($this->tuningHistory(365) as $row) {
            $measured = $row['measured_weight'] ?? $previousChosen;
            $previousChosen = isset($row['weight']) ? (float) $row['weight'] : $previousChosen;

            if ($measured === null || ($row['lift'] ?? null) === null) {
                continue;   // a hold-while-collecting measured nothing attributable
            }

            $key = (string) (int) round((float) $measured / 0.05);
            $b = $buckets[$key] ?? ['weight' => round((float) $measured, 2), 'nights' => 0,
                'sessions' => 0, 'orders' => 0, 'sessions_control' => 0, 'orders_control' => 0,
                'revenue' => 0.0];
            $b['nights']++;
            $b['sessions'] += (int) ($row['sessions_personalized'] ?? 0);
            $b['orders'] += (int) ($row['orders_personalized'] ?? 0);
            $b['sessions_control'] += (int) ($row['sessions_control'] ?? 0);
            $b['orders_control'] += (int) ($row['orders_control'] ?? 0);
            $b['revenue'] += (float) ($row['revenue_personalized'] ?? 0);
            $buckets[$key] = $b;
        }

        $out = [];
        foreach ($buckets as $b) {
            $p = $b['sessions'] > 0 ? $b['orders'] / $b['sessions'] : null;
            $c = $b['sessions_control'] > 0 ? $b['orders_control'] / $b['sessions_control'] : null;
            $b['conversion'] = $p;
            $b['lift'] = ($p !== null && $c !== null && $c > 0) ? round($p / $c - 1, 4) : null;
            $b['revenue'] = round($b['revenue'], 2);
            $out[] = $b;
        }
        usort($out, static fn (array $a, array $b) => $a['weight'] <=> $b['weight']);

        return $out;
    }

    private function client()
    {
        return $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
    }
}
