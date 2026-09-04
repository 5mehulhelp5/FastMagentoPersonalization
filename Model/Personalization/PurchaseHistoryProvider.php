<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Personalization;

use Magento\Framework\App\ResourceConnection;

/**
 * The attribute values a shopper has actually bought, straight from order history.
 *
 * Order history is why this milestone needs no event pipeline: the strongest signal we have is
 * already sitting in the database. Reads the SIMPLE product actually purchased rather than the
 * configurable parent, because the parent has no size or colour of its own — the variant is where
 * the shopper's choice lives.
 *
 * Runs off the request path (CLI / queue). It is deliberately one query per shopper, not per order.
 */
class PurchaseHistoryProvider
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param string[] $attributeCodes attributes to profile
     * @param float $halfLifeDays recency decay; older purchases count for less. 0 disables.
     * @return array<int, array{values: array<string, string>, weight: float}>
     */
    public function forCustomer(
        int $customerId,
        array $attributeCodes,
        float $halfLifeDays = 180.0
    ): array {
        if ($customerId <= 0 || !$attributeCodes) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                ['oi' => $this->resource->getTableName('sales_order_item')],
                ['item_id' => 'oi.item_id', 'product_id' => 'oi.product_id', 'qty' => 'oi.qty_ordered', 'created_at' => 'oi.created_at', 'price' => 'oi.price', 'sku' => 'oi.sku']
            )
            ->join(
                ['o' => $this->resource->getTableName('sales_order')],
                'o.entity_id = oi.order_id',
                []
            )
            ->where('o.customer_id = ?', $customerId)
            // Drop a configurable/bundle PARENT line when its child line is also present, so the
            // same purchase is not counted twice. Where no child line exists the parent stays and
            // is resolved by SKU below.
            ->where(
                'oi.item_id NOT IN (?)',
                new \Zend_Db_Expr(
                    $connection->select()
                        ->from($this->resource->getTableName('sales_order_item'), ['parent_item_id'])
                        ->where('parent_item_id IS NOT NULL')
                        ->__toString()
                )
            );

        $rows = $connection->fetchAll($select);
        if (!$rows) {
            return [];
        }

        // Resolve each line to the VARIANT actually bought. Two data shapes exist in the wild and
        // both have to work: some stores write a simple child line alongside the configurable
        // parent, others (including Magento's own sample data) write only the parent line — but in
        // BOTH the line's `sku` is the variant's. Prefer that SKU; fall back to product_id, which
        // is right for a plain simple product. Without this the profile reads attributes off the
        // configurable parent, which has no colour or size of its own, and comes back empty.
        $rows = $this->resolveVariants($connection, $rows);

        $productIds = array_values(array_unique(array_map(static fn ($r) => (int) $r['product_id'], $rows)));
        $valuesByProduct = $this->loadAttributeValues($productIds, $attributeCodes);
        $categoriesByProduct = $this->loadCategories($productIds);
        $categoryIdsByProduct = $this->loadCategoryPathIds($productIds);

        // What came back. A returned item is not evidence of a preference, and continuing to count
        // it as one is the single most confidently wrong thing this profile could do — it would
        // keep recommending the exact thing the shopper rejected, and grow MORE certain each time
        // they returned it.
        $returned = $this->getReturnedQtyByOrderItem($customerId);

        $purchases = [];
        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $values = $valuesByProduct[$productId] ?? [];
            if (!empty($categoriesByProduct[$productId])) {
                $values['category'] = $categoriesByProduct[$productId];
            }
            if (!$values) {
                continue;
            }

            // Net, not binary: returning one of three is still evidence about the other two.
            $qty = max(0.0, (float) $row['qty']) - ($returned[(int) $row['item_id']] ?? 0.0);
            if ($qty <= 0.0) {
                continue;
            }

            $purchases[] = [
                'values' => $values,
                'weight' => $qty * $this->recencyWeight((string) $row['created_at'], $halfLifeDays),
                // Every category this purchase counts towards: the assigned ones AND their
                // ancestors, so a purchase in "Tops > Hoodies" is evidence on the Tops listing too.
                'category_ids' => $categoryIdsByProduct[$productId] ?? [],
            ];
        }

        return $purchases;
    }

    /**
     * Swap each order line's product_id for the id of the SKU on that line, where that SKU resolves.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function resolveVariants($connection, array $rows): array
    {
        $skus = array_values(array_unique(array_filter(array_map(
            static fn ($r) => trim((string) ($r['sku'] ?? '')),
            $rows
        ))));

        if (!$skus) {
            return $rows;
        }

        $idBySku = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_entity'), ['sku', 'entity_id'])
                ->where('sku IN (?)', $skus)
        );

        foreach ($rows as &$row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku !== '' && isset($idBySku[$sku])) {
                $row['product_id'] = (int) $idBySku[$sku];
            }
        }

        return $rows;
    }

    /**
     * Resolved option LABELS, not option ids — a profile keyed on "49" is unreadable in a report
     * and unusable in an explanation ("because you usually buy black").
     *
     * @param int[] $productIds
     * @param string[] $attributeCodes
     * @return array<int, array<string, string>>
     */
    /**
     * What this shopper has saved for later.
     *
     * Read as STATE rather than caught as an event, and that is the better model rather than a
     * fallback. A wishlist is durable: reading the table at aggregation time picks up everything
     * saved before this module was ever installed, survives an event nobody was listening for, and
     * cannot drift out of step with what the shopper actually sees on their wishlist page. An
     * add/remove event stream would have to be replayed perfectly to arrive at the same answer the
     * table already holds.
     *
     * It is also the cleanest per-product statement short of a purchase, and cleaner in one
     * respect: no money changed hands, so it is not a compromise with stock, price or delivery.
     * They wanted this one, and said so.
     *
     * @return int[]
     */
    public function getWishlistProductIds(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['wi' => $this->resource->getTableName('wishlist_item')], ['product_id'])
            ->join(
                ['w' => $this->resource->getTableName('wishlist')],
                'w.wishlist_id = wi.wishlist_id',
                []
            )
            ->where('w.customer_id = ?', $customerId)
            ->order('wi.added_at DESC')
            ->limit(200);

        return array_values(array_filter(array_map('intval', $connection->fetchCol($select))));
    }

    /**
     * How much of each order line came back, keyed by order item id.
     *
     * Magento Open Source has no RMA module, so a CREDIT MEMO is the return record — it is what a
     * merchant creates when they refund, and refunding is what returning causes. On Commerce an RMA
     * would be the richer source (it carries a reason), and reading credit memos still works there
     * because an approved RMA produces one.
     *
     * Keyed by order item rather than by product, so returning one of three of the same line nets
     * exactly one away instead of discarding the line.
     *
     * @return array<int, float>
     */
    public function getReturnedQtyByOrderItem(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                ['ci' => $this->resource->getTableName('sales_creditmemo_item')],
                ['order_item_id', 'qty' => new \Zend_Db_Expr('SUM(ci.qty)')]
            )
            ->join(
                ['c' => $this->resource->getTableName('sales_creditmemo')],
                'c.entity_id = ci.parent_id',
                []
            )
            ->join(
                ['o' => $this->resource->getTableName('sales_order')],
                'o.entity_id = c.order_id',
                []
            )
            ->where('o.customer_id = ?', $customerId)
            ->where('ci.order_item_id IS NOT NULL')
            ->group('ci.order_item_id');

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $out[(int) $row['order_item_id']] = (float) $row['qty'];
        }

        return $out;
    }

    /**
     * Products this shopper has sent back.
     *
     * Recorded so serving can avoid re-offering the exact item, and DELIBERATELY not turned into a
     * negative on its attributes. Most apparel returns are the wrong size, not the wrong colour —
     * inferring "dislikes black" from a return of a black jumper that did not fit would invent a
     * dislike the shopper never expressed, and it would do so most often to the shoppers who buy
     * most. Netting the purchase away removes the false positive; claiming a negative would replace
     * it with a false negative, which is worse because nothing later contradicts it.
     *
     * @return int[]
     */
    public function getReturnedProductIds(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->distinct()
            ->from(['ci' => $this->resource->getTableName('sales_creditmemo_item')], ['product_id'])
            ->join(
                ['c' => $this->resource->getTableName('sales_creditmemo')],
                'c.entity_id = ci.parent_id',
                []
            )
            ->join(
                ['o' => $this->resource->getTableName('sales_order')],
                'o.entity_id = c.order_id',
                []
            )
            ->where('o.customer_id = ?', $customerId)
            ->where('ci.product_id IS NOT NULL');

        return array_values(array_filter(array_map('intval', $connection->fetchCol($select))));
    }

    /**
     * Attribute values for an arbitrary set of products, in the same shape the purchase path uses.
     *
     * Public so signals that name a PRODUCT rather than an attribute — a wishlist add today, a
     * product view later — resolve through exactly the same code as a purchase. Two resolvers would
     * eventually disagree about configurables, or about which store's labels to use, and the
     * disagreement would show up as an affinity that is subtly wrong rather than as an error.
     *
     * @param int[] $productIds
     * @param string[] $attributeCodes
     * @return array<int, array<string, string>> product id => [code => label]
     */
    public function resolveProductAttributes(array $productIds, array $attributeCodes): array
    {
        $values = $this->loadAttributeValues($productIds, $attributeCodes);

        if (in_array('category', $attributeCodes, true)) {
            foreach ($this->loadCategories($productIds) as $productId => $categories) {
                if ($categories) {
                    $values[$productId]['category'] = $categories;
                }
            }
        }

        return $values;
    }

    private function loadAttributeValues(array $productIds, array $attributeCodes): array
    {
        if (!$productIds) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['e' => $this->resource->getTableName('catalog_product_entity')], ['entity_id'])
            ->join(
                ['v' => $this->resource->getTableName('catalog_product_entity_int')],
                'v.entity_id = e.entity_id AND v.store_id = 0',
                ['value']
            )
            ->join(
                ['a' => $this->resource->getTableName('eav_attribute')],
                'a.attribute_id = v.attribute_id',
                ['attribute_code']
            )
            ->joinLeft(
                ['ov' => $this->resource->getTableName('eav_attribute_option_value')],
                'ov.option_id = v.value AND ov.store_id = 0',
                ['label' => 'ov.value']
            )
            ->where('e.entity_id IN (?)', $productIds)
            ->where('a.attribute_code IN (?)', $attributeCodes);

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $label = $row['label'] !== null && $row['label'] !== ''
                ? (string) $row['label']
                : (string) $row['value'];
            $out[(int) $row['entity_id']][(string) $row['attribute_code']] = $label;
        }

        return $out;
    }

    /**
     * Exponential decay, so last year's taste does not outvote last month's.
     */
    private function recencyWeight(string $createdAt, float $halfLifeDays): float
    {
        if ($halfLifeDays <= 0) {
            return 1.0;
        }

        $timestamp = strtotime($createdAt);
        if ($timestamp === false) {
            return 1.0;
        }

        $ageDays = max(0.0, (time() - $timestamp) / 86400);

        return 2 ** (-$ageDays / $halfLifeDays);
    }

    /**
     * Categories each product belongs to, by NAME.
     *
     * Multi-valued on purpose — a product is in several categories and all of them are part of what
     * the shopper chose. AffinityCalculator splits the item's weight across them, so being filed in
     * eight categories does not make a product count eight times.
     *
     * Root and site-root categories (level < 2) are excluded: "Default Category" is true of
     * everything and therefore says nothing about anyone.
     *
     * @param int[] $productIds
     * @return array<int, string[]>
     */
    /**
     * Category ids per product, ancestors included (every id on the assigned categories' paths
     * from level 2 down), for category-scoped affinities.
     *
     * @param int[] $productIds
     * @return array<int, int[]>
     */
    /**
     * A purchased row is resolved to the VARIANT that was bought (that is where size and colour
     * live), but variants are not assigned to categories — their configurable/bundle/grouped
     * parent is. So category evidence is read through the parent as well as the product itself.
     *
     * @param int[] $productIds
     * @return array<int, int[]> product id => [itself, ...its parents]
     */
    private function withParents(array $productIds): array
    {
        $out = [];
        foreach ($productIds as $id) {
            $out[(int) $id] = [(int) $id];
        }
        if (!$productIds) {
            return $out;
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_product_relation'), ['parent_id', 'child_id'])
            ->where('child_id IN (?)', $productIds);
        foreach ($connection->fetchAll($select) as $row) {
            $out[(int) $row['child_id']][] = (int) $row['parent_id'];
        }
        return $out;
    }

    private function loadCategoryPathIds(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $lineage = $this->withParents($productIds);
        $lookupIds = array_values(array_unique(array_merge(...array_values($lineage))));
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['ccp' => $this->resource->getTableName('catalog_category_product')], ['product_id'])
            ->join(
                ['c' => $this->resource->getTableName('catalog_category_entity')],
                'c.entity_id = ccp.category_id',
                ['path' => 'c.path']
            )
            ->where('ccp.product_id IN (?)', $lookupIds);
        $byLookup = [];
        foreach ($connection->fetchAll($select) as $row) {
            $ids = array_map('intval', explode('/', (string) $row['path']));
            // Drop the tree root (level 0) and the store root (level 1): nobody lists those.
            foreach (array_slice($ids, 2) as $id) {
                $byLookup[(int) $row['product_id']][$id] = $id;
            }
        }
        $out = [];
        foreach ($lineage as $productId => $ids) {
            foreach ($ids as $id) {
                foreach ($byLookup[$id] ?? [] as $categoryId) {
                    $out[$productId][$categoryId] = $categoryId;
                }
            }
        }
        return array_map('array_values', $out);
    }

    private function loadCategories(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }

        $lineage = $this->withParents($productIds);
        $lookupIds = array_values(array_unique(array_merge(...array_values($lineage))));
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['ccp' => $this->resource->getTableName('catalog_category_product')], ['product_id'])
            ->join(
                ['c' => $this->resource->getTableName('catalog_category_entity')],
                'c.entity_id = ccp.category_id',
                []
            )
            ->join(
                ['a' => $this->resource->getTableName('eav_attribute')],
                'a.attribute_code = \'name\' AND a.entity_type_id = ('
                . $connection->select()
                    ->from($this->resource->getTableName('eav_entity_type'), ['entity_type_id'])
                    ->where('entity_type_code = ?', 'catalog_category')
                    ->__toString()
                . ')',
                []
            )
            ->join(
                ['v' => $this->resource->getTableName('catalog_category_entity_varchar')],
                'v.entity_id = c.entity_id AND v.attribute_id = a.attribute_id AND v.store_id = 0',
                ['name' => 'v.value']
            )
            ->where('ccp.product_id IN (?)', $lookupIds)
            ->where('c.level >= ?', 2);

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $out[(int) $row['product_id']][] = (string) $row['name'];
        }

        return $out;
    }

    /**
     * Shopper TRAITS, as opposed to product affinities: what they spend, and whether a discount is
     * what moves them.
     *
     * These are not attribute values and do not belong in the affinity model — price is continuous
     * and coupon use is a property of the shopper rather than of any product. They are separately
     * actionable: a shopper whose orders are mostly couponed is a different merchandising problem
     * from one who has never used a code, and price band is a boost range rather than a value match.
     *
     * @return array<string, mixed>
     */
    public function getTraits(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $connection = $this->resource->getConnection();

        $orders = $connection->fetchAll(
            $connection->select()
                ->from(
                    ['o' => $this->resource->getTableName('sales_order')],
                    [
                        'grand_total' => 'o.grand_total',
                        'discount' => 'o.discount_amount',
                        'subtotal' => 'o.subtotal',
                        'coupon_code' => 'o.coupon_code',
                    ]
                )
                ->where('o.customer_id = ?', $customerId)
        );

        if (!$orders) {
            return [];
        }

        $withCoupon = 0;
        $discounted = 0;
        $discountShare = [];
        $orderTotals = [];

        foreach ($orders as $order) {
            $subtotal = (float) $order['subtotal'];
            $discount = abs((float) $order['discount']);

            if (trim((string) $order['coupon_code']) !== '') {
                $withCoupon++;
            }
            if ($discount > 0.0) {
                $discounted++;
                if ($subtotal > 0.0) {
                    $discountShare[] = $discount / $subtotal;
                }
            }
            $orderTotals[] = (float) $order['grand_total'];
        }

        $count = count($orders);

        return [
            'orders' => $count,
            'coupon_rate' => round($withCoupon / $count, 4),
            'discount_rate' => round($discounted / $count, 4),
            'avg_discount_share' => $discountShare
                ? round(array_sum($discountShare) / count($discountShare), 4)
                : 0.0,
            // A shopper who only ever buys with a code is telling you something load-bearing about
            // when to show them one.
            'discount_driven' => $count >= 2 && ($withCoupon / $count) >= 0.6,
            'order_total' => $this->percentiles($orderTotals),
        ];
    }

    /**
     * Price band from the ITEM prices a shopper actually paid.
     *
     * Percentiles rather than a mean: one outlier purchase should not drag the band, and p25..p90
     * is directly usable as a soft range boost.
     *
     * @param string[] $attributeCodes
     * @return array<string, float>
     */
    public function getPriceBand(int $customerId, array $attributeCodes = []): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $prices = $connection->fetchCol(
            $connection->select()
                ->from(['oi' => $this->resource->getTableName('sales_order_item')], ['price'])
                ->join(
                    ['o' => $this->resource->getTableName('sales_order')],
                    'o.entity_id = oi.order_id',
                    []
                )
                ->where('o.customer_id = ?', $customerId)
                ->where('oi.product_type = ?', 'simple')
                ->where('oi.price > 0')
        );

        return $this->percentiles(array_map('floatval', $prices));
    }

    /**
     * @param float[] $values
     * @return array<string, float>
     */
    private function percentiles(array $values): array
    {
        $values = array_values(array_filter($values, static fn ($v) => $v > 0));
        if (!$values) {
            return [];
        }

        sort($values);
        $at = static function (float $q) use ($values): float {
            $index = (int) floor($q * (count($values) - 1));

            return round($values[$index], 2);
        };

        return ['n' => count($values), 'p25' => $at(0.25), 'p50' => $at(0.50), 'p90' => $at(0.90)];
    }

    /**
     * Reviews the shopper has written, with the rating that matters.
     *
     * A review is a deliberate act, which makes it a strong signal — but it is the only signal here
     * that can be NEGATIVE, and treating it as plain interest gets it exactly backwards. Someone who
     * rates a product one star has told you what not to show them; folding that into a positive
     * affinity would recommend more of the thing they disliked.
     *
     * Rating is normalised to -1..+1 around the midpoint, so a 5★ contributes +1, a 3★ roughly
     * nothing, and a 1★ contributes -1. Callers feed the positives into affinities and the
     * negatives into the profile's `negative` block (see USER-INTELLIGENCE.md).
     *
     * @return array<int, array{product_id: int, sku: string, rating: float, sentiment: float,
     *         title: string, created_at: string}>
     */
    public function getReviews(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from(['r' => $this->resource->getTableName('review')], ['entity_pk_value', 'created_at'])
            ->join(
                ['d' => $this->resource->getTableName('review_detail')],
                'd.review_id = r.review_id',
                ['title', 'nickname']
            )
            ->joinLeft(
                ['v' => $this->resource->getTableName('rating_option_vote')],
                'v.review_id = r.review_id',
                ['percent' => 'AVG(v.percent)']
            )
            ->joinLeft(
                ['e' => $this->resource->getTableName('catalog_product_entity')],
                'e.entity_id = r.entity_pk_value',
                ['sku']
            )
            ->where('d.customer_id = ?', $customerId)
            ->group(['r.review_id', 'r.entity_pk_value', 'r.created_at', 'd.title', 'd.nickname', 'e.sku']);

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $percent = $row['percent'] === null ? null : (float) $row['percent'];
            // percent is 20/40/60/80/100 for 1..5 stars
            $stars = $percent === null ? 0.0 : $percent / 20.0;

            $out[] = [
                'product_id' => (int) $row['entity_pk_value'],
                'sku' => (string) ($row['sku'] ?? ''),
                'rating' => round($stars, 2),
                // -1 .. +1 around the 3-star midpoint; 0 when unrated.
                'sentiment' => $stars > 0 ? round(($stars - 3.0) / 2.0, 2) : 0.0,
                'title' => (string) ($row['title'] ?? ''),
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $out;
    }

    /**
     * How many distinct values the catalogue offers per attribute — the denominator the entropy
     * guard normalises against.
     *
     * @param string[] $attributeCodes
     * @return array<string, int>
     */
    /**
     * Resolve the stored LABELS back to the ids the search index actually holds.
     *
     * The profile records what a shopper bought in human terms — "Black", "L", "Hoodies &
     * Sweatshirts" — because that is what the inspector has to print and what a merchant has to be
     * able to argue with. Magento's catalogue search index stores none of those: `color` and `size`
     * are the EAV **option ids** as integers, and category membership is `category_ids`. A boost
     * written as {"term": {"color": "Black"}} matches nothing at all, and fails silently, which is
     * the failure shape this module works hardest to eliminate.
     *
     * So the ids are resolved once at BUILD time and stored alongside the labels. Query time then
     * costs one profile `get` and no lookups, which the per-page query budget requires.
     *
     * `category` is not an EAV attribute — it is assembled by {@see loadCategories()} from category
     * names — so it resolves against the category tree instead of the option table.
     *
     * @param string[] $attributeCodes
     * @return array<string, array<string, int>> attribute code => [label => id]
     */
    public function resolveValueIds(array $attributeCodes): array
    {
        $connection = $this->resource->getConnection();
        $out = [];

        $eavCodes = array_values(array_filter($attributeCodes, static fn ($c) => $c !== 'category'));
        if ($eavCodes) {
            $select = $connection->select()
                ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_code'])
                ->join(
                    ['o' => $this->resource->getTableName('eav_attribute_option')],
                    'o.attribute_id = a.attribute_id',
                    ['option_id']
                )
                ->join(
                    ['ov' => $this->resource->getTableName('eav_attribute_option_value')],
                    'ov.option_id = o.option_id AND ov.store_id = 0',
                    ['label' => 'ov.value']
                )
                ->where('a.attribute_code IN (?)', $eavCodes);

            foreach ($connection->fetchAll($select) as $row) {
                $out[(string) $row['attribute_code']][(string) $row['label']] = (int) $row['option_id'];
            }
        }

        if (in_array('category', $attributeCodes, true)) {
            $select = $connection->select()
                ->from(['c' => $this->resource->getTableName('catalog_category_entity')], ['entity_id'])
                ->join(
                    ['a' => $this->resource->getTableName('eav_attribute')],
                    'a.attribute_code = \'name\' AND a.entity_type_id = ('
                    . $connection->select()
                        ->from($this->resource->getTableName('eav_entity_type'), ['entity_type_id'])
                        ->where('entity_type_code = ?', 'catalog_category')
                        ->__toString()
                    . ')',
                    []
                )
                ->join(
                    ['v' => $this->resource->getTableName('catalog_category_entity_varchar')],
                    'v.entity_id = c.entity_id AND v.attribute_id = a.attribute_id AND v.store_id = 0',
                    ['name' => 'v.value']
                )
                ->where('c.level >= ?', 2);

            foreach ($connection->fetchAll($select) as $row) {
                // Category names are not unique across the tree. First id wins, which matches
                // loadCategories() attributing a purchase to the name it found.
                $name = (string) $row['name'];
                if (!isset($out['category'][$name])) {
                    $out['category'][$name] = (int) $row['entity_id'];
                }
            }
        }

        return $out;
    }

    public function getCatalogueCardinality(array $attributeCodes): array
    {
        if (!$attributeCodes) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_code'])
            ->join(
                ['o' => $this->resource->getTableName('eav_attribute_option')],
                'o.attribute_id = a.attribute_id',
                ['options' => new \Zend_Db_Expr('COUNT(DISTINCT o.option_id)')]
            )
            ->where('a.attribute_code IN (?)', $attributeCodes)
            ->group('a.attribute_code');

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $out[(string) $row['attribute_code']] = (int) $row['options'];
        }

        return $out;
    }
}
