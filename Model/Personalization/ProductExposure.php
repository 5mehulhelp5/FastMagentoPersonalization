<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Personalization;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagento\Helper\WriteLog;
use ParkkTech\FastMagentoPersonalization\Model\Analytics\EventHistoryProvider;

/**
 * How well a product converts FOR THE EXPOSURE IT GOT — the denominator the rest of the system
 * lacked.
 *
 * Every popularity-driven ranking is a rich-get-richer loop: what ranks gets shown, what gets shown
 * gets bought, what gets bought ranks. A product added last week has no history, so it never enters
 * the loop, and the merchant's newest and often highest-margin stock is invisible until somebody
 * pins it by hand. PERSONALIZATION.md §5 names the fix — rank on conversion PER IMPRESSION, not on
 * raw counts — and that needs a number nothing here could produce until impressions were collected.
 * This is that number.
 *
 * Two things it refuses to do, and they are the whole point:
 *
 * IT DOES NOT TREAT "NEVER SHOWN" AS "NEVER BOUGHT". A product with no impressions has no rate. Not
 * zero — unknown. Scoring it zero is precisely the bug this exists to remove, so a product below the
 * exposure floor gets `null`, and {@see underExposed()} hands it to the exploration slot as
 * something to give a hearing rather than something to bury.
 *
 * IT DOES NOT TRUST A SMALL SAMPLE. One sale from one impression is not a 100% conversion rate. The
 * stored figure is a Wilson lower bound at 95%, which is the rate we can defend rather than the
 * rate we observed — so a product needs both a good rate AND enough exposure to be believed before
 * it outranks an established one.
 *
 * THE WINDOW IS THE TRAP. Impressions only exist from the day collection was switched on; sales go
 * back to the store's first order. Dividing all-time sales by two weeks of impressions gives a
 * flattering, meaningless number to exactly the oldest products — the opposite of the intended
 * effect. So the numerator is restricted to the window the denominator covers, starting at the
 * earliest impression on record.
 *
 * GRANULARITY, and this one only showed up against real orders. Impressions are recorded for what a
 * listing SHOWS: configurable parents. Sales are not reliably recorded there. A configurable bought
 * through the product page writes a parent row plus a child row — count both and every such sale is
 * doubled — while an order placed against the variant directly (which is what this store's own
 * seeded orders and much of Magento's sample data look like) writes ONE row carrying the CHILD id,
 * which matches no impression at all. Filtering on `parent_item_id IS NULL` fixes the first and
 * leaves the second reading zero sales for the best-selling product in the catalogue.
 *
 * So the numerator is netted, de-duplicated by `parent_item_id`, and then ROLLED UP to the
 * configurable parent through `catalog_product_super_link`. Both shapes then land on the same id the
 * denominator counts. Getting this wrong does not fail loudly — it reports every product converting
 * at zero, which looks like a quiet catalogue rather than a broken join.
 *
 * Rebuilt off the request path, stored as one document per store view beside the discrimination
 * table, and read as a single `get`. Nothing here runs during a page render.
 */
class ProductExposure
{
    /**
     * Impressions a product needs before its conversion rate is worth quoting.
     *
     * Below this the Wilson bound is so wide it says nothing, and reporting a number anyway would
     * invite someone to rank on it. 30 is the conventional floor for a proportion and is about one
     * page of a 36-product grid.
     */
    public const MIN_IMPRESSIONS = 30;

    /** 95% one-sided; the bound we can defend, not the rate we happened to see. */
    private const Z = 1.96;

    /** @var array<int, array<string, mixed>|null> per-request memo, keyed by store id */
    private array $memo = [];

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly OpenSearchConfig $config,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\IndexNames $indexNames,
        private readonly WriteLog $writeLog,
        private readonly EventHistoryProvider $events,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Recompute the table for one store view, or every store view when $storeId is null.
     *
     * @return int number of store views written
     */
    public function rebuild(?int $storeId = null, int $limit = 5000): int
    {
        $this->ensureIndex();

        $stores = $storeId !== null
            ? [$storeId]
            : array_map(static fn ($s) => (int) $s->getId(), $this->storeManager->getStores());

        $written = 0;
        foreach ($stores as $id) {
            if ($this->rebuildStore($id, $limit)) {
                $written++;
            }
        }

        $this->memo = [];

        return $written;
    }

    /**
     * The defensible conversion-per-impression rate, or null when the product has not been shown
     * often enough to have one.
     *
     * Null is a real answer and callers must treat it as one. "We do not know" and "it converts at
     * zero" lead to opposite ranking decisions, and collapsing them is the failure this class was
     * built to prevent.
     */
    public function getRate(int $productId, ?int $storeId = null): ?float
    {
        $row = $this->getRow($productId, $storeId);

        return $row === null || $row['rate'] === null ? null : (float) $row['rate'];
    }

    /**
     * @return array{impressions: int, units: int, rate: float|null}|null
     */
    public function getRow(int $productId, ?int $storeId = null): ?array
    {
        $table = $this->table($this->resolveStoreId($storeId));
        $row = $table['products'][(string) $productId] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return [
            'impressions' => (int) ($row['i'] ?? 0),
            'units' => (int) ($row['u'] ?? 0),
            'rate' => isset($row['r']) ? (float) $row['r'] : null,
        ];
    }

    /**
     * Which of these products have not had a fair hearing yet.
     *
     * Takes the candidate set rather than scanning the catalogue, because the caller — the
     * exploration slot — always has one, and because "under-exposed" is only meaningful against the
     * products actually competing for these slots. A product absent from the table entirely has
     * never been shown at all, which is the strongest case there is, so absence counts.
     *
     * Least-exposed first, so a caller filling N slots takes the first N.
     *
     * @param int[] $productIds
     * @return int[]
     */
    public function underExposed(array $productIds, ?int $storeId = null): array
    {
        $table = $this->table($this->resolveStoreId($storeId));
        if ($table === null) {
            // No measurement means no opinion. Returning everything would let a missing table
            // quietly turn the whole page into an exploration slot.
            return [];
        }

        $out = [];
        foreach (array_unique(array_map('intval', $productIds)) as $id) {
            $impressions = (int) ($table['products'][(string) $id]['i'] ?? 0);
            if ($impressions < self::MIN_IMPRESSIONS) {
                $out[$id] = $impressions;
            }
        }

        asort($out);

        return array_keys($out);
    }

    /**
     * The measured table, strongest defensible rate first, for the CLI and the doctor.
     *
     * @return array<int, array{impressions: int, units: int, rate: float|null}>
     */
    public function describe(?int $storeId = null, int $limit = 50): array
    {
        $table = $this->table($this->resolveStoreId($storeId));
        if ($table === null) {
            return [];
        }

        $rows = [];
        foreach ($table['products'] ?? [] as $id => $row) {
            $rows[(int) $id] = [
                'impressions' => (int) ($row['i'] ?? 0),
                'units' => (int) ($row['u'] ?? 0),
                'rate' => isset($row['r']) ? (float) $row['r'] : null,
            ];
        }

        uasort($rows, static fn (array $a, array $b) => ($b['rate'] ?? -1) <=> ($a['rate'] ?? -1));

        return array_slice($rows, 0, $limit, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function summary(?int $storeId = null): ?array
    {
        $table = $this->table($this->resolveStoreId($storeId));
        if ($table === null) {
            return null;
        }

        return [
            'built_at' => (string) ($table['built_at'] ?? ''),
            'window_from' => (string) ($table['window_from'] ?? ''),
            'products' => count($table['products'] ?? []),
            'judged' => (int) ($table['judged'] ?? 0),
            'impressions' => (int) ($table['total_impressions'] ?? 0),
            'unlisted_impressions' => (int) ($table['unlisted_impressions'] ?? 0),
        ];
    }

    public function isAvailable(?int $storeId = null): bool
    {
        return $this->table($this->resolveStoreId($storeId)) !== null;
    }

    private function rebuildStore(int $storeId, int $limit): bool
    {
        // The consumer of impressionCounts(). Store-wide rather than per shopper, because a
        // denominator computed from one person's browsing is not a denominator.
        $impressions = $this->events->impressionCounts($limit);
        $windowFrom = $this->events->firstImpressionAt();

        if (!$impressions || $windowFrom === null) {
            // Nothing shown yet. Writing an empty table would be worse than writing none: callers
            // read absence as "no opinion" and an empty table as "measured, and everything is
            // under-exposed".
            return false;
        }

        $units = $this->unitsSoldSince($windowFrom);

        $products = [];
        $judged = 0;
        $totalImpressions = 0;
        foreach ($impressions as $productId => $shown) {
            $shown = max(0, (int) $shown);
            $sold = (int) ($units[$productId] ?? 0);
            $totalImpressions += $shown;

            $rate = null;
            if ($shown >= self::MIN_IMPRESSIONS) {
                // Sales can exceed impressions — a shopper buys from a link, an email, a repeat
                // order. Clamping keeps the proportion a proportion; the excess is real demand this
                // measure simply cannot attribute to a listing.
                $rate = round($this->wilsonLowerBound(min($sold, $shown), $shown), 6);
                $judged++;
            }

            $products[(string) $productId] = array_filter(
                ['i' => $shown, 'u' => $sold, 'r' => $rate],
                static fn ($v) => $v !== null
            );
        }

        $document = [
            'store_id' => $storeId,
            'built_at' => gmdate('c'),
            'window_from' => $windowFrom,
            'min_impressions' => self::MIN_IMPRESSIONS,
            'total_impressions' => $totalImpressions,
            'judged' => $judged,
            // Products the impression aggregation could not list within its bucket limit. Reported
            // rather than swallowed: a silent truncation here reads downstream as "these products
            // were never shown", which is the exact false conclusion this class exists to avoid.
            'unlisted_impressions' => $this->events->unlistedImpressionCount($limit),
            'products' => $products,
        ];

        try {
            $this->client()->getOpenSearchClient()->index([
                'index' => $this->indexNames->getProductExposureIndexName(),
                'id' => 'store_' . $storeId,
                'body' => $document,
            ]);
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('Exposure table write failed: ' . $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Units sold per product since the denominator started, netted and at listing granularity.
     *
     * Netted of cancellations and refunds for the same reason a returned item does not count as a
     * purchase anywhere else in this module: it was not, in the end, a sale. Rows carrying a
     * `parent_item_id` are skipped so a configurable sale is counted once, against the parent —
     * which is the thing a listing showed and therefore the thing an impression counted.
     *
     * @return array<int, int>
     */
    private function unitsSoldSince(string $windowFrom): array
    {
        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from(
                ['oi' => $this->resource->getTableName('sales_order_item')],
                [
                    'product_id',
                    'units' => new \Zend_Db_Expr(
                        'SUM(GREATEST(oi.qty_ordered - oi.qty_canceled - oi.qty_refunded, 0))'
                    ),
                ]
            )
            ->join(
                ['o' => $this->resource->getTableName('sales_order')],
                'o.entity_id = oi.order_id',
                []
            )
            ->where('oi.parent_item_id IS NULL')
            ->where('o.created_at >= ?', $windowFrom)
            ->where('o.state NOT IN (?)', ['canceled'])
            ->group('oi.product_id');

        $raw = [];
        foreach ($connection->fetchAll($select) as $row) {
            $raw[(int) $row['product_id']] = (int) $row['units'];
        }

        return $this->rollUpToParents($raw);
    }

    /**
     * Move variant sales onto the configurable parent that was actually listed.
     *
     * One query, and only for the ids that turned out to be children — a product that is not a
     * variant keeps its own id, so a catalogue of simples pays nothing for this.
     *
     * @param array<int, int> $units
     * @return array<int, int>
     */
    private function rollUpToParents(array $units): array
    {
        if (!$units) {
            return $units;
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                ['l' => $this->resource->getTableName('catalog_product_super_link')],
                ['product_id', 'parent_id']
            )
            ->where('l.product_id IN (?)', array_keys($units));

        $parents = [];
        foreach ($connection->fetchAll($select) as $row) {
            $parents[(int) $row['product_id']] = (int) $row['parent_id'];
        }

        if (!$parents) {
            return $units;
        }

        $out = [];
        foreach ($units as $productId => $sold) {
            $target = $parents[$productId] ?? $productId;
            $out[$target] = ($out[$target] ?? 0) + $sold;
        }

        return $out;
    }

    /**
     * The lower end of the 95% confidence interval for a proportion.
     *
     * Wilson rather than the naive rate, and rather than a normal approximation, because the whole
     * problem lives at small n and near-zero p where the naive answers are worst: 1/1 is not a
     * certainty and 0/40 is not an impossibility.
     */
    private function wilsonLowerBound(int $successes, int $trials): float
    {
        if ($trials <= 0) {
            return 0.0;
        }

        $p = $successes / $trials;
        $z2 = self::Z ** 2;

        $numerator = $p + $z2 / (2 * $trials)
            - self::Z * sqrt(($p * (1 - $p) + $z2 / (4 * $trials)) / $trials);

        return max(0.0, $numerator / (1 + $z2 / $trials));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function table(int $storeId): ?array
    {
        if (array_key_exists($storeId, $this->memo)) {
            return $this->memo[$storeId];
        }

        try {
            $response = $this->client()->getOpenSearchClient()->get([
                'index' => $this->indexNames->getProductExposureIndexName(),
                'id' => 'store_' . $storeId,
            ]);
            $table = !empty($response['found']) && isset($response['_source']) ? $response['_source'] : null;
        } catch (\Throwable $e) {
            $table = null;
        }

        return $this->memo[$storeId] = $table;
    }

    public function ensureIndex(): void
    {
        $indexName = $this->indexNames->getProductExposureIndexName();
        $client = $this->client();

        if ($client->indexExists($indexName)) {
            return;
        }

        $client->createIndex($indexName, [
            'settings' => ['number_of_shards' => 1],
            'mappings' => [
                'properties' => [
                    'store_id' => ['type' => 'short'],
                    'built_at' => ['type' => 'date'],
                    'window_from' => ['type' => 'date'],
                    'total_impressions' => ['type' => 'long'],
                    'judged' => ['type' => 'integer'],
                    // Read whole, never queried by field. Left unindexed so a catalogue's worth of
                    // product ids never becomes a catalogue's worth of mapping fields.
                    'products' => ['type' => 'object', 'enabled' => false],
                ],
            ],
        ]);
    }

    public function refresh(): void
    {
        try {
            $this->client()->getOpenSearchClient()->indices()->refresh([
                'index' => $this->indexNames->getProductExposureIndexName(),
            ]);
        } catch (\Throwable $e) {
            // Only ever called to make a just-written table readable; not worth failing over.
        }
    }

    private function resolveStoreId(?int $storeId): int
    {
        if ($storeId !== null) {
            return $storeId;
        }

        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function client()
    {
        return $this->clientResolver->create($this->engineResolver->getCurrentSearchEngine());
    }
}
