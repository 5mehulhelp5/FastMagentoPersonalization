<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Analytics;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\Search\EngineResolverInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\PurchaseHistoryProvider;

/**
 * Turn recorded searches and facet selections into the same weighted observations the profile
 * already understands.
 *
 * These are STATED preferences, and the profile's own precedence rule puts stated above inferred. A
 * purchase tells you what someone settled for once stock, price and delivery had their say; a facet
 * click tells you what they asked for before any of that interfered. So an event carries more
 * weight per observation than a purchase does — see {@see PersonalizationConfig::getEventWeight()}.
 *
 * Two normalisations happen here rather than at capture, because both cost work and neither belongs
 * on a request a shopper is waiting for:
 *
 * 1. The two surfaces record the same selection differently. The search grid sends the LABEL
 *    (`color=Black`), because that is what its facet UI deals in; layered navigation on a category
 *    sends the OPTION ID (`color=49`), because that is what Magento puts in the URL. Left alone the
 *    aggregate would treat one preference as two.
 * 2. A typed query is free text, and most of it is not an attribute. "black hoodie" contains a
 *    colour the catalogue knows; matching the words against real option labels extracts it without
 *    any model, and ignores the rest. Anything cleverer than exact matching is M5's problem — this
 *    is the honest floor, not a substitute for it.
 */
class EventHistoryProvider
{
    /** Never aggregate more than this per shopper: a bot with a cookie should not reshape a profile. */
    private const MAX_EVENTS = 500;

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly OpenSearchConfig $config,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\IndexNames $indexNames,
        private readonly PurchaseHistoryProvider $products,
        private readonly FactExtractor $factExtractor
    ) {
    }

    /**
     * Weighted observations derived from what this shopper searched for and filtered on.
     *
     * @param string[] $attributeCodes attributes worth profiling
     * @param array<string, array<string, int>> $valueIds code => [label => option id], for normalising
     * @return array<int, array{values: array<string, string>, weight: float}>
     */
    public function forShopper(
        ?int $customerId,
        ?string $anonId,
        array $attributeCodes,
        array $valueIds,
        float $halfLifeDays = 180.0,
        float $eventWeight = 1.5,
        float $viewWeight = 0.25
    ): array {
        $events = $this->fetchEvents($customerId, $anonId);
        if (!$events) {
            return [];
        }

        // label => option id, and option id => label, per attribute — so a value recorded in either
        // form lands on the same key.
        $labelById = [];
        foreach ($valueIds as $code => $map) {
            foreach ($map as $label => $id) {
                $labelById[(string) $code][(string) $id] = (string) $label;
            }
        }

        $codes = array_flip($attributeCodes);

        // Views name a product, so resolve the whole set in one query through the same resolver the
        // purchase path uses.
        $viewedIds = [];
        foreach ($events as $event) {
            $type = $event['type'] ?? '';
            if (($type === 'view' || $type === 'hover') && !empty($event['product_id'])) {
                $viewedIds[] = (int) $event['product_id'];
            }
        }
        $viewedAttributes = ($viewedIds && $viewWeight > 0.0)
            ? $this->products->resolveProductAttributes(array_values(array_unique($viewedIds)), $attributeCodes)
            : [];

        $observations = [];

        foreach ($events as $event) {
            $recency = $this->recencyWeight((string) ($event['created_at'] ?? ''), $halfLifeDays);
            if ($recency <= 0.0) {
                continue;
            }

            $values = [];
            $weightForEvent = $eventWeight;

            // Impressions are never a preference. Being shown something says nothing about wanting
            // it, and at grid volume they would swamp every real signal. They are a denominator —
            // see impressionCounts().
            if (($event['type'] ?? '') === 'impression') {
                continue;
            }

            if (($event['type'] ?? '') === 'hover') {
                if ($viewWeight <= 0.0) {
                    continue;
                }
                $resolved = $viewedAttributes[(int) ($event['product_id'] ?? 0)] ?? [];
                foreach ($resolved as $code => $label) {
                    if (isset($codes[$code])) {
                        $values[$code] = $label;
                    }
                }
                // A fraction of a view: the shopper looked and did NOT open it, which is weaker
                // than opening it and weaker still than buying.
                $weightForEvent = $viewWeight * 0.25;
            }

            if (($event['type'] ?? '') === 'view') {
                if ($viewWeight <= 0.0) {
                    continue;
                }
                $resolved = $viewedAttributes[(int) ($event['product_id'] ?? 0)] ?? [];
                foreach ($resolved as $code => $label) {
                    if (isset($codes[$code])) {
                        $values[$code] = $label;
                    }
                }
                // Dwell scales a view, but only within a narrow band. Three minutes of attention
                // means more than four seconds, and nothing like as much as buying the thing — so
                // the multiplier tops out well before a long look could rival a purchase.
                $dwell = (int) ($event['dwell_seconds'] ?? 0);
                $weightForEvent = $viewWeight * min(2.0, 1.0 + ($dwell / 120));
            }

            foreach ($event['filters'] ?? [] as $filter) {
                // Layered navigation names the category facet `cat`; the profile keys it
                // `category`, because that is what the purchase side synthesises. Same preference,
                // two names, and without this the strongest category signal a shopper can give —
                // explicitly narrowing to one — would be silently dropped.
                $code = (string) ($filter['attribute'] ?? '');
                $code = $code === 'cat' ? 'category' : $code;
                $raw = (string) ($filter['value'] ?? '');
                if ($code === '' || $raw === '' || !isset($codes[$code])) {
                    continue;
                }
                $label = $this->canonicalLabel($code, $raw, $labelById, $valueIds);
                if ($label !== null) {
                    $values[$code] = $label;
                }
            }

            // A typed query contributes only where its words are literally an option the catalogue
            // sells. "black hoodie" yields the colour and nothing else, which is the correct amount
            // of inference to do without a model.
            $query = (string) ($event['query'] ?? '');
            if ($query !== '') {
                foreach ($this->matchQueryToOptions($query, $attributeCodes, $valueIds) as $code => $label) {
                    $values[$code] = $values[$code] ?? $label;
                }
            }

            if (!$values) {
                continue;
            }

            $observations[] = ['values' => $values, 'weight' => $recency * $weightForEvent];
        }

        return $observations;
    }

    /**
     * Requirements this shopper has stated in a search box.
     *
     * Repetition is the only corroboration available for a cold-start fact, so a value searched
     * several times is trusted more than one searched once — capped well below the confidence a
     * shopper typing it into a form would deserve, because they never confirmed it here.
     *
     * @return array<string, array{value: string, confidence: float, source: string, observations: int, evidence: string}>
     */
    public function factsForShopper(?int $customerId, ?string $anonId): array
    {
        $events = $this->fetchEvents($customerId, $anonId);
        if (!$events) {
            return [];
        }

        $seen = [];
        foreach ($events as $event) {
            $query = (string) ($event['query'] ?? '');
            if ($query === '') {
                continue;
            }
            foreach ($this->factExtractor->extract($query) as $name => $fact) {
                $key = $name . '=' . $fact['value'];
                $seen[$key] = $seen[$key] ?? $fact + ['name' => $name, 'observations' => 0];
                $seen[$key]['observations']++;
            }
        }

        // One fact per name — the most-repeated candidate wins, so a shopper who searched two
        // different years gets the one they meant rather than the one they typed first.
        $best = [];
        foreach ($seen as $fact) {
            $name = $fact['name'];
            if (!isset($best[$name]) || $fact['observations'] > $best[$name]['observations']) {
                $best[$name] = $fact;
            }
        }

        $out = [];
        foreach ($best as $name => $fact) {
            unset($fact['name']);
            // Corroboration lifts confidence, but never to the level of something stated outright.
            $fact['confidence'] = round(
                min(0.8, $fact['confidence'] * (1 + 0.25 * ($fact['observations'] - 1))),
                3
            );
            $out[$name] = $fact;
        }

        return $out;
    }

    /**
     * Resolve a recorded value to the label the profile keys on, whichever form it arrived in.
     *
     * @param array<string, array<string, string>> $labelById
     * @param array<string, array<string, int>> $valueIds
     */
    private function canonicalLabel(string $code, string $raw, array $labelById, array $valueIds): ?string
    {
        // Numeric: an option id from layered navigation.
        if (ctype_digit($raw) && isset($labelById[$code][$raw])) {
            return $labelById[$code][$raw];
        }

        // Otherwise a label from the search grid — accepted only if the catalogue actually has it,
        // so a hand-edited URL cannot inject values into a profile.
        foreach ($valueIds[$code] ?? [] as $label => $id) {
            if (strcasecmp((string) $label, $raw) === 0) {
                return (string) $label;
            }
        }

        return null;
    }

    /**
     * Attribute values named literally in a search query.
     *
     * @param string[] $attributeCodes
     * @param array<string, array<string, int>> $valueIds
     * @return array<string, string>
     */
    private function matchQueryToOptions(string $query, array $attributeCodes, array $valueIds): array
    {
        $haystack = ' ' . mb_strtolower($query) . ' ';
        $found = [];

        foreach ($attributeCodes as $code) {
            foreach ($valueIds[$code] ?? [] as $label => $id) {
                $needle = mb_strtolower(trim((string) $label));
                // Word-boundary match: "red" must not fire on "prepared", and a two-word option
                // like "Light Blue" still matches as a phrase.
                if ($needle !== '' && strpos($haystack, ' ' . $needle . ' ') !== false) {
                    $found[$code] = (string) $label;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchEvents(?int $customerId, ?string $anonId): array
    {
        $should = [];
        if ($customerId !== null && $customerId > 0) {
            $should[] = ['term' => ['customer_id' => $customerId]];

            // Guest-to-customer stitching, with no extra storage. Once a shopper is logged in every
            // event carries BOTH ids, so the events that already name this customer also name the
            // anonymous ids they have used — and those ids reach back to everything they did before
            // they ever signed in. That earlier browsing is often the most intent-rich thing a
            // store has about a new customer, and it would otherwise be stranded under a cookie
            // nobody ever asked about again.
            foreach ($this->linkedAnonIds($customerId) as $linked) {
                $should[] = ['term' => ['anon_id' => $linked]];
            }
        }
        if ($anonId !== null && $anonId !== '') {
            // The anonymous thread counts on its own too, for a shopper who is not logged in — the
            // reason the id survives logout rather than rotating.
            $should[] = ['term' => ['anon_id' => $anonId]];
        }
        if (!$should) {
            return [];
        }

        try {
            $response = $this->client()->getOpenSearchClient()->search([
                'index' => $this->indexNames->getEventIndexName(),
                'body' => [
                    'size' => self::MAX_EVENTS,
                    'query' => ['bool' => ['should' => $should, 'minimum_should_match' => 1]],
                    'sort' => [['created_at' => ['order' => 'desc']]],
                ],
            ]);
        } catch (\Throwable $e) {
            // No index yet, or an unreachable cluster. A profile built from purchases alone is the
            // correct degradation.
            return [];
        }

        $out = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            if (isset($hit['_source'])) {
                $out[] = $hit['_source'];
            }
        }

        return $out;
    }

    /**
     * How many times each product has been SHOWN, across all shoppers.
     *
     * The one thing impressions are for. Ranking on raw sales buries a product that has never had
     * the chance to sell; ranking on conversion PER IMPRESSION asks the fairer question — of the
     * people who saw it, how many bought it — which is what PERSONALIZATION.md §5 needs for
     * new-product fairness and what the milestone was waiting for before building this at all.
     *
     * Store-wide rather than per shopper, because a denominator computed from one person's browsing
     * is not a denominator.
     *
     * @return array<int, int> product id => times shown
     */
    public function impressionCounts(int $limit = 500): array
    {
        try {
            $response = $this->client()->getOpenSearchClient()->search([
                'index' => $this->indexNames->getEventIndexName(),
                'body' => [
                    'size' => 0,
                    'query' => ['term' => ['type' => 'impression']],
                    'aggs' => ['products' => ['terms' => ['field' => 'product_ids', 'size' => $limit]]],
                ],
            ]);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($response['aggregations']['products']['buckets'] ?? [] as $bucket) {
            $out[(int) $bucket['key']] = (int) $bucket['doc_count'];
        }

        return $out;
    }

    /**
     * The anonymous ids worth building a profile for.
     *
     * Floor and cap, both deliberate. The floor (minimum stated events — searches and facets, not
     * impressions) keeps a drive-by visit from occupying a profile document: below a handful of
     * stated signals the confidence gate would refuse to act anyway, so the write would buy
     * nothing. The cap bounds a single build pass, and the ids come back most-recently-active
     * first, so the shoppers most likely to return are the ones whose profiles are freshest.
     *
     * @return string[] anon ids, most recently active first
     */
    public function activeAnonIds(int $minStatedEvents = 3, int $limit = 1000, ?string $since = null): array
    {
        $must = [['terms' => ['type' => ['search', 'facet']]]];
        if ($since !== null && $since !== '') {
            $must[] = ['range' => ['created_at' => ['gte' => $since]]];
        }

        try {
            $response = $this->client()->getOpenSearchClient()->search([
                'index' => $this->indexNames->getEventIndexName(),
                'body' => [
                    'size' => 0,
                    'query' => ['bool' => ['must' => $must]],
                    'aggs' => [
                        'ids' => [
                            'terms' => [
                                'field' => 'anon_id',
                                'size' => $limit,
                                'min_doc_count' => max(1, $minStatedEvents),
                                'order' => ['last_seen' => 'desc'],
                            ],
                            'aggs' => ['last_seen' => ['max' => ['field' => 'created_at']]],
                        ],
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($response['aggregations']['ids']['buckets'] ?? [] as $bucket) {
            $key = (string) ($bucket['key'] ?? '');
            if ($key !== '') {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * The customer ids with any event since a moment — for the cron, so an hourly refresh rebuilds
     * the profiles that can have changed and not the ten thousand that cannot.
     *
     * @return int[]
     */
    public function customerIdsWithEventsSince(string $since, int $limit = 1000): array
    {
        try {
            $response = $this->client()->getOpenSearchClient()->search([
                'index' => $this->indexNames->getEventIndexName(),
                'body' => [
                    'size' => 0,
                    'query' => ['range' => ['created_at' => ['gte' => $since]]],
                    'aggs' => ['ids' => ['terms' => ['field' => 'customer_id', 'size' => $limit]]],
                ],
            ]);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($response['aggregations']['ids']['buckets'] ?? [] as $bucket) {
            $id = (int) ($bucket['key'] ?? 0);
            if ($id > 0) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * When the denominator starts — the earliest impression on record, as an ISO date.
     *
     * The window this whole measure is only valid inside. Impressions exist from the day collection
     * was switched on; sales go back to the store's first order. Dividing one by the other without
     * this bound hands the oldest products a flattering, meaningless rate, which is the exact
     * opposite of what impression-normalised scoring is for.
     */
    public function firstImpressionAt(): ?string
    {
        try {
            $response = $this->client()->getOpenSearchClient()->search([
                'index' => $this->indexNames->getEventIndexName(),
                'body' => [
                    'size' => 1,
                    'query' => ['term' => ['type' => 'impression']],
                    'sort' => [['created_at' => 'asc']],
                    '_source' => ['created_at'],
                ],
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        $hit = $response['hits']['hits'][0]['_source']['created_at'] ?? null;

        return $hit === null ? null : (string) $hit;
    }

    /**
     * Impressions belonging to products the aggregation could not list within its bucket limit.
     *
     * {@see impressionCounts()} asks for the top N products, and a catalogue larger than N leaves a
     * remainder. Zero means the counts are complete. Anything else has to be REPORTED rather than
     * swallowed, because downstream a missing product is indistinguishable from one that was never
     * shown — and "never shown" is the verdict this whole mechanism exists to hand out carefully.
     */
    public function unlistedImpressionCount(int $limit = 500): int
    {
        try {
            $response = $this->client()->getOpenSearchClient()->search([
                'index' => $this->indexNames->getEventIndexName(),
                'body' => [
                    'size' => 0,
                    'query' => ['term' => ['type' => 'impression']],
                    'aggs' => ['products' => ['terms' => ['field' => 'product_ids', 'size' => $limit]]],
                ],
            ]);
        } catch (\Throwable $e) {
            return 0;
        }

        return (int) ($response['aggregations']['products']['sum_other_doc_count'] ?? 0);
    }

    /**
     * The anonymous ids this customer has been seen under.
     *
     * One aggregation over the events that already name them. Capped, because a shopper on many
     * devices is normal and a shopper with hundreds of cookie ids is a bot or a broken client.
     *
     * @return string[]
     */
    private function linkedAnonIds(int $customerId): array
    {
        try {
            $response = $this->client()->getOpenSearchClient()->search([
                'index' => $this->indexNames->getEventIndexName(),
                'body' => [
                    'size' => 0,
                    'query' => ['term' => ['customer_id' => $customerId]],
                    'aggs' => ['ids' => ['terms' => ['field' => 'anon_id', 'size' => 20]]],
                ],
            ]);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($response['aggregations']['ids']['buckets'] ?? [] as $bucket) {
            $key = (string) ($bucket['key'] ?? '');
            if ($key !== '') {
                $out[] = $key;
            }
        }

        return $out;
    }

    private function recencyWeight(string $createdAt, float $halfLifeDays): float
    {
        if ($halfLifeDays <= 0) {
            return 1.0;
        }

        $timestamp = strtotime($createdAt);
        if (!$timestamp) {
            return 0.0;
        }

        $ageDays = max(0.0, (time() - $timestamp) / 86400);

        return 2 ** (-$ageDays / $halfLifeDays);
    }

    private function client()
    {
        return $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
    }
}
