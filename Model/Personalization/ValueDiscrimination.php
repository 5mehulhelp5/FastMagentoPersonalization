<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Personalization;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use ParkkTech\FastMagento\Helper\WriteLog;

/**
 * The second gate: does acting on a preference actually change anything?
 *
 * The concentration gate on {@see AttributeAffinity} answers "does this shopper have a
 * preference?". It cannot answer "would boosting it reorder the page?", because that is a fact
 * about the CATALOGUE, not about the shopper. Both have to be true before a boost is worth
 * emitting.
 *
 * Why this exists, measured on a real store rather than reasoned about. Magento's catalogue search
 * index holds only VISIBLE products — configurable parents, each carrying the union of its
 * children's option ids. Over 187 documents:
 *
 *     size L (174)  98 docs      colour Black (49)  62 docs
 *     size XS (171) 97 docs      colour Gray  (52)  32 docs
 *     L AND XS      97 docs      Black AND Gray     11 docs
 *     L but not XS   1 doc       Black but not Gray 51 docs
 *
 * So boosting "size L" promotes the same 97 products as boosting "size XS": a shopper who wears L
 * and one who wears XS get an identical re-ranking. Every document matches, every score shifts by
 * the same factor, the order does not move. That is a silent SUCCESS — the feature reports itself
 * working while doing nothing — and it is the exact shape this module's doctor exists to eliminate.
 *
 * The measure is inverse document frequency, and it is deliberately PER VALUE, never per attribute.
 * Size 180 scores 1.80 on this catalogue — a rare non-apparel size, more discriminating than any
 * colour — so a rule saying "size is a weak signal here" would suppress a real one. Only the five
 * uniform apparel sizes are worthless, and only because they are uniform. There is no
 * attribute-level allowlist anywhere in this class, and no per-vertical special-casing.
 *
 * Global rather than candidate-set relative. Candidate-set IDF is more correct but needs a second
 * pass that `function_score` cannot express, and the one case it would buy — an attribute already
 * implied by the listing you are on — is largely handled for free, because a value carried by every
 * candidate (the root category, at 100% share) scores 0.00 on its own.
 *
 * Cheap enough not to argue about: one terms aggregation covers every option across every profiled
 * attribute in ~17ms, so it is computed per reindex and read once per request. Query time keeps its
 * locked budget of one profile `get`.
 *
 * On any miss this reports "no opinion" and the caller emits NO boost. Failing closed is the right
 * direction here: an un-gated boost is the silent-success bug, and the doctor reports a missing or
 * stale table loudly rather than letting it pass as working.
 */
class ValueDiscrimination
{
    /** Values on more than this share of the catalogue cannot reorder it meaningfully. */
    public const NEAR_UNIFORM_SHARE = 0.5;

    /**
     * Category-relative measurement. A value can be rare across the store and universal inside
     * one category (every product in "Brass Fittings" is brass; every piece in "Plastic Sets" is
     * plastic). Boosting it on THAT listing moves nothing, so shares are also measured per
     * category and a category listing is gated on its own population when it has enough
     * products to say something. Smaller categories fall back to the store-wide figure.
     */
    public const MIN_CATEGORY_DOCS = 20;
    public const MAX_CATEGORIES = 3000;
    public const MAX_VALUES_PER_ATTRIBUTE = 300;

    /**
     * Magento's own catalogue search index — what the category listing and native search rank.
     * Holds only VISIBLE products (configurable parents), and stores the profiled attributes as
     * EAV option IDS (`color` = 49).
     */
    public const TARGET_NATIVE = 'native';

    /**
     * FastMagento's serving index — what the instant-search grid ranks. Holds parents AND their
     * variants, and stores the profiled attributes as LABELS under `attributes.*`
     * (`attributes.color` = "Black").
     *
     * The two are mirror images, and the difference is not cosmetic: size L covers 52% of the
     * native index (worthless — every parent comes in L) but 13% of the serving index (meaningful —
     * only the L variants). Gating a variant-level boost with parent-level shares would silently
     * apply the wrong verdict, so each target is measured separately against the documents that
     * will actually be ranked.
     */
    public const TARGET_SERVING = 'serving';

    /** @var array<int, array<string, mixed>|null> per-request memo, keyed by store id */
    private array $memo = [];

    /** @var array<string, array<string, mixed>|null> per-request memo of category sections */
    private array $categoryMemo = [];

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly SearchIndexNameResolver $indexNameResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly OpenSearchConfig $config,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\IndexNames $indexNames,
        private readonly WriteLog $writeLog
    ) {
    }

    /**
     * Recompute the table for one store view, or every store view when $storeId is null.
     *
     * @param string[] $attributeCodes attributes to measure, e.g. ['color', 'size']
     * @return int number of store views rebuilt
     */
    public function rebuild(array $attributeCodes, ?int $storeId = null): int
    {
        $storeIds = $storeId !== null
            ? [$storeId]
            : array_map(static fn ($s) => (int) $s->getId(), $this->storeManager->getStores());

        $built = 0;
        foreach ($storeIds as $id) {
            if ($this->rebuildStore($attributeCodes, $id)) {
                $built++;
            }
        }

        return $built;
    }

    /**
     * Inverse document frequency for one attribute value, or null when there is no table to
     * consult. Null means "no opinion" and the caller must not boost.
     */
    public function getIdf(
        string $attributeCode,
        string $value,
        string $target = self::TARGET_NATIVE,
        ?int $storeId = null,
        ?int $categoryId = null
    ): ?float {
        $section = $this->section($target, $storeId, $categoryId);
        if ($section === null) {
            return null;
        }

        $total = (int) ($section['total_docs'] ?? 0);
        if ($total <= 0) {
            return null;
        }

        $docs = $section['attributes'][$attributeCode][$value] ?? null;
        if ($docs === null) {
            // The index holds nothing with this value. A real answer, not a miss — but there is
            // nothing to boost, so it carries no weight.
            return 0.0;
        }

        return log($total / max(1, (int) $docs));
    }

    /**
     * Share of the catalogue carrying this value, or null when there is no table.
     */
    public function getShare(
        string $attributeCode,
        string $value,
        string $target = self::TARGET_NATIVE,
        ?int $storeId = null,
        ?int $categoryId = null
    ): ?float {
        $section = $this->section($target, $storeId, $categoryId);
        if ($section === null) {
            return null;
        }

        $total = (int) ($section['total_docs'] ?? 0);
        if ($total <= 0) {
            return null;
        }

        return ((int) ($section['attributes'][$attributeCode][$value] ?? 0)) / $total;
    }

    /**
     * Whether boosting this value could move the page at all.
     *
     * The honest verdict for a shopper who always buys size L on a catalogue where every garment
     * comes in L: a real preference that cannot change this listing.
     */
    public function isDiscriminating(
        string $attributeCode,
        string $value,
        string $target = self::TARGET_NATIVE,
        ?int $storeId = null,
        ?int $categoryId = null
    ): bool {
        $share = $this->getShare($attributeCode, $value, $target, $storeId, $categoryId);

        return $share !== null && $share > 0.0 && $share <= self::NEAR_UNIFORM_SHARE;
    }

    public function isAvailable(string $target = self::TARGET_NATIVE, ?int $storeId = null): bool
    {
        return $this->section($target, $storeId) !== null;
    }

    /** ISO-8601 timestamp of the last rebuild, or null when there is no table. */
    public function getBuiltAt(?int $storeId = null): ?string
    {
        $table = $this->load($this->resolveStoreId($storeId));
        $builtAt = $table['built_at'] ?? null;

        return is_string($builtAt) && $builtAt !== '' ? $builtAt : null;
    }

    public function getTotalDocs(string $target = self::TARGET_NATIVE, ?int $storeId = null): int
    {
        $section = $this->section($target, $storeId);

        return (int) ($section['total_docs'] ?? 0);
    }

    /**
     * One target's measured slice, or null when it has never been measured.
     *
     * @return array<string, mixed>|null
     */
    private function section(string $target, ?int $storeId, ?int $categoryId = null): ?array
    {
        $storeId = $this->resolveStoreId($storeId);
        if ($categoryId !== null && $categoryId > 0 && $target === self::TARGET_NATIVE) {
            $scoped = $this->categorySection($storeId, $categoryId);
            if ($scoped !== null) {
                return $scoped;
            }
        }
        $table = $this->load($storeId);
        $section = $table['targets'][$target] ?? null;

        return is_array($section) ? $section : null;
    }

    /**
     * Whether a category listing is gated on its own population (true) or on the store's.
     */
    public function hasCategorySection(int $categoryId, ?int $storeId = null): bool
    {
        return $categoryId > 0 && $this->categorySection($this->resolveStoreId($storeId), $categoryId) !== null;
    }

    /**
     * How many categories were large enough to be measured on their own.
     */
    public function getCategoriesMeasured(?int $storeId = null): int
    {
        $table = $this->load($this->resolveStoreId($storeId));

        return (int) ($table['categories_measured'] ?? 0);
    }

    private function categorySection(int $storeId, int $categoryId): ?array
    {
        $key = $storeId . ':' . $categoryId;
        if (array_key_exists($key, $this->categoryMemo)) {
            return $this->categoryMemo[$key];
        }
        $section = null;
        try {
            $response = $this->client()->getOpenSearchClient()->get([
                'index' => $this->indexNames->getValueDiscriminationIndexName(),
                'id' => self::categoryDocId($storeId, $categoryId),
            ]);
            if (!empty($response['found']) && isset($response['_source']['total_docs'])) {
                $section = $response['_source'];
            }
        } catch (\Throwable $e) {
            $section = null;
        }

        return $this->categoryMemo[$key] = $section;
    }

    private static function categoryDocId(int $storeId, int $categoryId): string
    {
        return 'store:' . $storeId . ':cat:' . $categoryId;
    }

    /**
     * Every measured value for one attribute as [value_id => ['docs', 'share', 'idf']], strongest
     * first. Used by the inspector and the doctor to show their working.
     *
     * @return array<int, array{docs: int, share: float, idf: float}>
     */
    public function describe(
        string $attributeCode,
        string $target = self::TARGET_NATIVE,
        ?int $storeId = null,
        ?int $categoryId = null
    ): array {
        $section = $this->section($target, $storeId, $categoryId);
        $total = (int) ($section['total_docs'] ?? 0);
        if ($section === null || $total <= 0) {
            return [];
        }

        $out = [];
        foreach ($section['attributes'][$attributeCode] ?? [] as $value => $docs) {
            $docs = max(0, (int) $docs);
            $out[(string) $value] = [
                'docs' => $docs,
                'share' => $docs / $total,
                'idf' => log($total / max(1, $docs)),
            ];
        }

        uasort($out, static fn (array $a, array $b) => $b['idf'] <=> $a['idf']);

        return $out;
    }

    /**
     * One aggregation over Magento's OWN catalogue search index — not FastMagento's serving index.
     *
     * This has to measure the documents that will actually be ranked. Both the category listing and
     * native search rank against Magento's index; the module's own index is used for hydration and
     * for its instant-search grid. Measuring the wrong one would produce a table that looks right
     * and gates the wrong population.
     */
    private function rebuildStore(array $attributeCodes, int $storeId): bool
    {
        $attributeCodes = array_values(array_filter($attributeCodes));
        if (!$attributeCodes) {
            return false;
        }

        $targets = [];

        // Magento's index, via the alias it maintains across reindexes ({prefix}_product_{storeId})
        // and never the versioned concrete name, which changes on every full reindex.
        $native = $this->measure(
            $this->indexNameResolver->getIndexName($storeId, Fulltext::INDEXER_ID),
            $attributeCodes,
            static fn (string $code) => $code === 'category' ? 'category_ids' : $code
        );
        if ($native !== null) {
            $targets[self::TARGET_NATIVE] = $native;
        }

        // FastMagento's serving index. Labels under `attributes.*`, and `category` is deliberately
        // absent: the root of that index is `dynamic: false`, so category_ids is stored in _source
        // but never indexed and cannot be filtered on.
        $serving = $this->measure(
            $this->config->getIndexName(),
            array_values(array_filter($attributeCodes, static fn ($c) => $c !== 'category')),
            static fn (string $code) => 'attributes.' . $code
        );
        if ($serving !== null) {
            $targets[self::TARGET_SERVING] = $serving;
        }

        if (!$targets) {
            return false;
        }

        $categories = isset($targets[self::TARGET_NATIVE])
            ? $this->measureByCategory(
                $this->indexNameResolver->getIndexName($storeId, Fulltext::INDEXER_ID),
                array_values(array_filter($attributeCodes, static fn ($c) => $c !== 'category'))
            )
            : [];

        $table = [
            'store_id' => $storeId,
            'built_at' => gmdate('c'),
            'targets' => $targets,
            'categories_measured' => count($categories),
        ];

        try {
            $this->ensureIndex();
            $this->client()->getOpenSearchClient()->index([
                'index' => $this->indexNames->getValueDiscriminationIndexName(),
                'id' => 'store:' . $storeId,
                'body' => $table,
            ]);
            $this->saveCategorySections($storeId, $categories, (string) $table['built_at']);
            unset($this->memo[$storeId]);
            $this->categoryMemo = [];
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog(
                '[FastMagento] discrimination save failed for store ' . $storeId . ': ' . $e->getMessage()
            );

            return false;
        }

        return true;
    }

    /**
     * One terms aggregation per attribute against one index.
     *
     * The bucket KEY is stored verbatim as the lookup key, so each target is keyed in whatever form
     * that index actually holds — option ids for Magento's, labels for FastMagento's. Callers must
     * look up in the matching form; that is the whole reason the two are measured apart.
     *
     * @param string[] $attributeCodes
     * @param callable(string):string $fieldFor
     * @return array<string, mixed>|null
     */
    private function measure(string $index, array $attributeCodes, callable $fieldFor): ?array
    {
        if (!$attributeCodes) {
            return null;
        }

        $aggs = [];
        foreach ($attributeCodes as $code) {
            $aggs[$code] = ['terms' => ['field' => $fieldFor($code), 'size' => 10000]];
        }

        try {
            $response = $this->client()->getOpenSearchClient()->search([
                'index' => $index,
                'body' => ['size' => 0, 'aggs' => $aggs],
            ]);
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog(
                '[FastMagento] discrimination measure failed against ' . $index . ': ' . $e->getMessage()
            );

            return null;
        }

        $total = (int) ($response['hits']['total']['value'] ?? 0);
        if ($total <= 0) {
            return null;
        }

        $attributes = [];
        foreach ($attributeCodes as $code) {
            foreach ($response['aggregations'][$code]['buckets'] ?? [] as $bucket) {
                $key = (string) $bucket['key'];
                // An empty label is "this product has no value for that attribute" — a
                // configurable parent whose colour lives on its children. Never boostable.
                if ($key === '') {
                    continue;
                }
                $attributes[$code][$key] = (int) $bucket['doc_count'];
            }
        }

        return [
            'index' => $index,
            'total_docs' => $total,
            'attributes' => $attributes,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * Per-category value counts from the native index: one search per attribute, categories as
     * the outer buckets (only those with MIN_CATEGORY_DOCS products or more), values inside.
     *
     * @return array<int, array{total_docs: int, attributes: array<string, array<string, int>>}>
     */
    private function measureByCategory(string $index, array $attributeCodes): array
    {
        $out = [];
        foreach ($attributeCodes as $code) {
            try {
                $response = $this->client()->getOpenSearchClient()->search([
                    'index' => $index,
                    'body' => [
                        'size' => 0,
                        'aggs' => [
                            'cats' => [
                                'terms' => [
                                    'field' => 'category_ids',
                                    'size' => self::MAX_CATEGORIES,
                                    'min_doc_count' => self::MIN_CATEGORY_DOCS,
                                ],
                                'aggs' => [
                                    'vals' => [
                                        'terms' => ['field' => $code, 'size' => self::MAX_VALUES_PER_ATTRIBUTE],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);
            } catch (\Throwable $e) {
                $this->writeLog->writeErrorLog(
                    '[FastMagento] category discrimination measure failed for ' . $code . ': ' . $e->getMessage()
                );
                continue;
            }
            foreach ($response['aggregations']['cats']['buckets'] ?? [] as $cat) {
                $categoryId = (int) $cat['key'];
                if ($categoryId <= 0) {
                    continue;
                }
                $out[$categoryId]['total_docs'] = (int) $cat['doc_count'];
                $out[$categoryId]['attributes'] ??= [];
                foreach ($cat['vals']['buckets'] ?? [] as $bucket) {
                    $key = (string) $bucket['key'];
                    if ($key !== '') {
                        $out[$categoryId]['attributes'][$code][$key] = (int) $bucket['doc_count'];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Replace this store's per-category docs. Old ones go first so a category that shrank below
     * the threshold stops being gated on stale figures and falls back to the store-wide table.
     */
    private function saveCategorySections(int $storeId, array $categories, string $builtAt): void
    {
        $client = $this->client()->getOpenSearchClient();
        $index = $this->indexNames->getValueDiscriminationIndexName();
        try {
            $client->deleteByQuery([
                'index' => $index,
                'refresh' => true,
                'conflicts' => 'proceed',
                'body' => ['query' => ['bool' => ['filter' => [
                    ['term' => ['store_id' => $storeId]],
                    ['exists' => ['field' => 'category_id']],
                ]]]],
            ]);
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog(
                '[FastMagento] category discrimination cleanup failed for store ' . $storeId . ': ' . $e->getMessage()
            );
        }
        if (!$categories) {
            return;
        }
        $body = [];
        foreach ($categories as $categoryId => $section) {
            $body[] = ['index' => ['_index' => $index, '_id' => self::categoryDocId($storeId, (int) $categoryId)]];
            $body[] = [
                'store_id' => $storeId,
                'category_id' => (int) $categoryId,
                'built_at' => $builtAt,
                'total_docs' => (int) $section['total_docs'],
                'attributes' => $section['attributes'] ?: new \stdClass(),
            ];
            if (count($body) >= 1000) {
                $this->bulk($client, $body);
                $body = [];
            }
        }
        if ($body) {
            $this->bulk($client, $body);
        }
    }

    private function bulk($client, array $body): void
    {
        try {
            $client->bulk(['body' => $body]);
        } catch (\Throwable $e) {
            $this->writeLog->writeErrorLog('[FastMagento] category discrimination save failed: ' . $e->getMessage());
        }
    }

    private function load(int $storeId): ?array
    {
        if (array_key_exists($storeId, $this->memo)) {
            return $this->memo[$storeId];
        }

        $table = null;
        try {
            $response = $this->client()->getOpenSearchClient()->get([
                'index' => $this->indexNames->getValueDiscriminationIndexName(),
                'id' => 'store:' . $storeId,
            ]);
            if (!empty($response['found']) && isset($response['_source'])) {
                $table = $response['_source'];
            }
        } catch (\Throwable $e) {
            // Absent table or unreachable cluster. The caller treats null as "no opinion" and
            // emits no boost; the doctor is what makes this visible.
            $table = null;
        }

        return $this->memo[$storeId] = $table;
    }

    public function ensureIndex(): void
    {
        $indexName = $this->indexNames->getValueDiscriminationIndexName();
        $client = $this->client();

        if ($client->indexExists($indexName)) {
            return;
        }

        $client->createIndex($indexName, [
            'settings' => ['number_of_shards' => 1],
            'mappings' => [
                'properties' => [
                    'store_id' => ['type' => 'short'],
                    'category_id' => ['type' => 'integer'],
                    'categories_measured' => ['type' => 'integer'],
                    'built_at' => ['type' => 'date'],
                    'total_docs' => ['type' => 'integer'],
                    'source_index' => ['type' => 'keyword'],
                    // Read whole and never queried by field, so storing it unindexed keeps every
                    // new attribute value out of the mapping.
                    'attributes' => ['type' => 'object', 'enabled' => false],
                ],
            ],
        ]);
    }

    public function refresh(): void
    {
        try {
            $this->client()->getOpenSearchClient()->indices()->refresh([
                'index' => $this->indexNames->getValueDiscriminationIndexName(),
            ]);
        } catch (\Throwable $e) {
            // Only ever called to make a just-written table countable; a stale count is not worth
            // failing a completed rebuild over.
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
