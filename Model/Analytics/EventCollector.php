<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Analytics;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagento\Helper\WriteLog;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\PersonalizationConfig;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\RequestScope;

/**
 * Record the two strongest signals this module already sees and has never kept: what shoppers
 * SEARCH for, and which facets they SELECT.
 *
 * Both are stated rather than inferred. A purchase says what someone ended up with, after stock,
 * price and delivery had their say; a facet click says what they were looking for, in their own
 * words, before any of that interfered. That is why they rank above purchase history in the
 * profile's own precedence rule, and why they were worth building before views or dwell time.
 *
 * Captured SERVER-SIDE, at the point the search or the filter is actually executed. The analytics
 * stack decision (JS to collect, PHP to ingest, OpenSearch to store) governs signals the server
 * cannot see — scroll, hover, dwell. A search query and a facet selection arrive as request
 * parameters and are already in PHP's hands, so collecting them in the browser would add a network
 * round trip to re-tell the server something it just did.
 *
 * WRITE COST. One `index` call on a request that is already talking to OpenSearch, wrapped so that
 * nothing it does can surface to a shopper. It adds no SQL, which is the constraint that actually
 * binds — the per-page query count must not rise. Collection is independent of whether
 * personalisation is APPLIED, for the same reason profiles are built while serving is off: the data
 * has to be warm before anyone switches it on, or an A/B starts with one arm empty.
 */
class EventCollector
{
    public const TYPE_SEARCH = 'search';
    public const TYPE_FACET = 'facet';
    public const TYPE_VIEW = 'view';
    public const TYPE_IMPRESSION = 'impression';
    public const TYPE_ORDER = 'order';
    public const TYPE_HOVER = 'hover';

    /** Queries longer than this are not searches, they are paste accidents or probes. */
    private const MAX_QUERY_LENGTH = 128;

    /** Below this a view is a bounce, not interest. */
    private const DWELL_FLOOR_SECONDS = 3;

    /** Above this it is a tab left open, not attention. */
    private const DWELL_CEILING_SECONDS = 180;

    /** Below this a hover is the cursor crossing the card on its way somewhere else. */
    private const HOVER_FLOOR_MS = 800;

    /** One page cannot claim more impressions than this. A grid is finite; a loop is not. */
    private const MAX_IMPRESSIONS_PER_PAGE = 60;

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly OpenSearchConfig $config,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\IndexNames $indexNames,
        private readonly PersonalizationConfig $personalizationConfig,
        private readonly AnonymousId $anonymousId,
        private readonly RequestScope $requestScope,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Personalization\AbTest $abTest,
        private readonly StoreManagerInterface $storeManager,
        private readonly WriteLog $writeLog
    ) {
    }

    /**
     * A shopper searched. Facets applied at the same time are recorded with it, because "black
     * hoodie" typed and "hoodie" typed then black selected are the same intent.
     *
     * @param array<string, string[]> $filters
     */
    public function recordSearch(string $query, array $filters = [], int $resultCount = 0): void
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');
        if ($query === '' || mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return;
        }

        $this->record(self::TYPE_SEARCH, [
            'query' => mb_strtolower($query),
            'filters' => $this->normaliseFilters($filters),
            'result_count' => $resultCount,
        ]);
    }

    /**
     * A shopper narrowed a listing. This is the single most direct statement of intent the
     * storefront ever receives — they were shown everything and said "this attribute, this value".
     *
     * Returns whether anything was actually stored, like the other collectors here — the
     * browser-reported path counts what it accepted rather than what it was offered, and a filter
     * set that normalises away to nothing must not be reported back as recorded.
     *
     * @param array<string, string[]> $filters
     */
    public function recordFacetSelection(array $filters, ?string $query = null): bool
    {
        $filters = $this->normaliseFilters($filters);
        if (!$filters) {
            return false;
        }

        $this->record(self::TYPE_FACET, [
            'filters' => $filters,
            'query' => $query !== null && $query !== '' ? mb_strtolower(trim($query)) : null,
        ]);

        return true;
    }

    /**
     * A shopper looked at a product, and for how long.
     *
     * The weakest signal here by a distance, and deliberately treated as such — a view is a
     * question, not an answer, and a catalogue of 2,000 products generates views by the thousand
     * for every purchase. It is a REFINEMENT: useful for separating two products a shopper is
     * already interested in, useless as a preference on its own.
     *
     * Dwell is clamped at both ends before it is trusted. Below the floor it is a bounce — a
     * mis-click, or a back button — and counting it as interest would let noise outvote intent.
     * Above the ceiling it is a tab left open over lunch, and without a cap that single page would
     * outweigh a shopper's entire purchase history.
     */
    public function recordView(int $productId, int $dwellSeconds): bool
    {
        if ($productId <= 0 || $dwellSeconds < self::DWELL_FLOOR_SECONDS) {
            return false;
        }

        $this->record(self::TYPE_VIEW, [
            'product_id' => $productId,
            'dwell_seconds' => min($dwellSeconds, self::DWELL_CEILING_SECONDS),
        ]);

        return true;
    }

    /**
     * Products that were actually on screen.
     *
     * ONE ROW PER PAGE, carrying a list — not one row per product. A 36-product grid would
     * otherwise write 36 documents for a single page view, and impressions are already the highest
     * volume and lowest value signal here; storing them per product would make the events index
     * mostly impressions within a week.
     *
     * They are deliberately NOT a preference signal. USER-INTELLIGENCE rates them one star —
     * "enormous volume for little else" — and it is right: being shown something says nothing about
     * wanting it. What they ARE is the denominator the rest of the system lacks. Ranking on
     * conversion PER IMPRESSION rather than on raw sales is what stops a new product being buried
     * because it has never had the chance to sell (PERSONALIZATION.md §5), and that is the specific
     * need the milestone said impressions were waiting for.
     *
     * @param int[] $productIds
     */
    public function recordImpressions(array $productIds): bool
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$ids) {
            return false;
        }

        $this->record(self::TYPE_IMPRESSION, [
            'product_ids' => array_slice($ids, 0, self::MAX_IMPRESSIONS_PER_PAGE),
        ]);

        return true;
    }

    /**
     * A cursor rested on a product card without clicking it.
     *
     * The grid-level analogue of dwell: attention paid to something the shopper did NOT go on to
     * open. Weaker than a view by construction, and desktop-only — a touch device has no hover, so
     * this signal simply does not exist for most traffic and must never be treated as its absence
     * meaning disinterest.
     */
    public function recordHover(int $productId, int $milliseconds): bool
    {
        if ($productId <= 0 || $milliseconds < self::HOVER_FLOOR_MS) {
            return false;
        }

        $this->record(self::TYPE_HOVER, [
            'product_id' => $productId,
            'hover_ms' => min($milliseconds, 30000),
        ]);

        return true;
    }

    /**
     * An order was placed — the numerator of every conversion rate on the dashboard.
     *
     * Recorded as an event rather than read from sales_order at report time, for one reason: the
     * ARM. Conversion per arm needs to know which experience the shopper who ordered was getting,
     * and that lives in the analytics cookie at the moment of purchase — attribution the order
     * tables cannot reconstruct for a guest. Revenue rides along so the dashboard can show sales
     * curves, not just rates.
     */
    /**
     * @param int|null $customerId the order's own customer, so a checkout that completes over
     *        REST or GraphQL — where there is no storefront session or cookie to attribute the
     *        event from — is still counted for the shopper who placed it
     */
    public function recordOrder(int $orderId, float $grandTotal, int $itemCount, ?int $customerId = null): void
    {
        if ($orderId <= 0) {
            return;
        }

        $this->record(self::TYPE_ORDER, [
            'order_id' => $orderId,
            'revenue' => round(max(0.0, $grandTotal), 2),
            'items' => max(0, $itemCount),
        ], $customerId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function record(string $type, array $payload, ?int $explicitCustomerId = null): void
    {
        try {
            if (!$this->personalizationConfig->isCollectingEvents()) {
                return;
            }

            $customerId = $explicitCustomerId !== null && $explicitCustomerId > 0
                ? $explicitCustomerId
                : $this->requestScope->getCustomerId();
            $anonId = $this->anonymousId->get();

            // Nobody to attribute it to and no cookie we could set — a crawler, or a client that
            // refuses cookies. An unattributable event is noise, not data.
            if (($customerId === null || $customerId <= 0) && $anonId === null) {
                return;
            }

            $doc = array_merge($payload, [
                'type' => $type,
                'customer_id' => $customerId !== null && $customerId > 0 ? $customerId : null,
                'anon_id' => $anonId,
                // Stamped on every event whether or not the test is running, so the day it IS
                // switched on the history is already labelled — the same always-warm principle as
                // profile building. Derived from the cookie hash, not stored state.
                'arm' => $this->abTest->currentArm(),
                'store_id' => (int) $this->storeManager->getStore()->getId(),
                'created_at' => gmdate('c'),
            ]);

            $this->ensureIndex();
            $this->client()->getOpenSearchClient()->index([
                'index' => $this->indexNames->getEventIndexName(),
                'body' => $doc,
            ]);
        } catch (\Throwable $e) {
            // Analytics must never cost a shopper their search results. Logged, not surfaced.
            $this->writeLog->writeErrorLog('[FastMagento] event capture failed: ' . $e->getMessage());
        }
    }

    /**
     * Lower-case, de-duplicate, drop empties, and cap the number of values per attribute.
     *
     * The cap is not tidiness. Filter parameters are shopper-controlled and arrive straight off a
     * URL, so an unbounded list is an unbounded document.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array{attribute: string, value: string}>
     */
    private function normaliseFilters(array $filters): array
    {
        $out = [];
        $seen = [];

        foreach ($filters as $code => $values) {
            $code = trim((string) $code);
            if ($code === '' || !preg_match('/^[a-z0-9_]{1,64}$/i', $code)) {
                continue;
            }

            foreach ((array) $values as $value) {
                $value = trim((string) $value);
                if ($value === '' || mb_strlen($value) > 64) {
                    continue;
                }
                $key = $code . '=' . $value;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = ['attribute' => $code, 'value' => $value];

                if (count($out) >= 25) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /** The one mapping definition, used to create the index and to upgrade an existing one. */
    private const MAPPING = [
            'properties' => [
            'type' => ['type' => 'keyword'],
            'customer_id' => ['type' => 'long'],
            'anon_id' => ['type' => 'keyword'],
            'arm' => ['type' => 'keyword'],
            'order_id' => ['type' => 'long'],
            'revenue' => ['type' => 'double'],
            'items' => ['type' => 'integer'],
            'store_id' => ['type' => 'short'],
            'created_at' => ['type' => 'date'],
            'query' => ['type' => 'keyword'],
            'result_count' => ['type' => 'integer'],
            'product_id' => ['type' => 'long'],
            'dwell_seconds' => ['type' => 'integer'],
            'hover_ms' => ['type' => 'integer'],
            'product_ids' => ['type' => 'long'],
            // Nested, not object: an event carries several attribute/value pairs and they
            // must stay paired. Flattened, "colour black" and "size L" in one event would
            // also match a query for "colour L".
            'filters' => [
                'type' => 'nested',
                'properties' => [
                'attribute' => ['type' => 'keyword'],
                'value' => ['type' => 'keyword'],
                ],
            ],
            ],
        ];

    /** @var bool one mapping-upgrade attempt per request, not one per event */
    private bool $mappingEnsured = false;

    public function ensureIndex(): void
    {
        $indexName = $this->indexNames->getEventIndexName();
        $client = $this->client();

        if ($client->indexExists($indexName)) {
            // The index predating a release that ADDS fields is the trap this closes: without it,
            // a new field's first write is dynamically mapped — `arm` came out as text, which
            // silently breaks every aggregation over it. put_mapping is additive and idempotent
            // for new fields; a genuinely conflicting old field throws, is logged once, and needs
            // the reindex migration documented in ENVIRONMENT.md.
            if (!$this->mappingEnsured) {
                $this->mappingEnsured = true;
                try {
                    $client->getOpenSearchClient()->indices()->putMapping([
                        'index' => $indexName,
                        'body' => self::MAPPING,
                    ]);
                } catch (\Throwable $e) {
                    $this->writeLog->writeErrorLog(
                        '[FastMagento] events mapping upgrade refused — reindex needed: ' . $e->getMessage()
                    );
                }
            }

            return;
        }

        $client->createIndex($indexName, [
            'settings' => ['number_of_shards' => 1],
            'mappings' => self::MAPPING,
        ]);
    }

    public function refresh(): void
    {
        try {
            $this->client()->getOpenSearchClient()->indices()->refresh([
                'index' => $this->indexNames->getEventIndexName(),
            ]);
        } catch (\Throwable $e) {
            // Only ever called to make just-written events countable.
        }
    }

    private function client()
    {
        return $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
    }
}
