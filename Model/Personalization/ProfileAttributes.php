<?php
declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Personalization;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Which product attributes a shopper is profiled on.
 *
 * Personalisation used to be hard-wired to `color` and `size`. That is the right pair for an
 * apparel store and the wrong pair for almost every other one: a hardware store's shoppers keep
 * choosing a thread size or a voltage, a chess store's keep choosing a material or a piece style,
 * and none of those would ever have been counted. The catalogue already says which attributes
 * shoppers choose by — the ones the merchant made filterable — so that is the default here.
 *
 * Resolution order:
 *   1. The admin setting "Attributes To Profile" when it is non-blank (comma-separated codes).
 *   2. Otherwise auto-detect: every select/multiselect product attribute that is filterable on
 *      category pages or in search, has at least two options, and is not a system attribute.
 *      `color` and `size` lead when they exist, then the widest attributes first, capped.
 *   3. Every attribute the merchant mapped a stated requirement ("fact") to is always included,
 *      because a fact recorded against an unprofiled attribute would rank nothing.
 *
 * Whether a value CAN lift anything is not decided here: that is the catalogue discrimination
 * table's job (see ValueDiscrimination). An attribute being profiled means "count what this
 * shopper chose"; a value with one share of the store, or of the category being listed, is still
 * refused at boost time. So profiling a wide list is safe — it costs profile size, not relevance.
 */
class ProfileAttributes
{
    public const SOURCE_CONFIGURED = 'configured';
    public const SOURCE_AUTO = 'auto';

    /** Auto-detect never profiles more than this many attributes. */
    public const MAX_AUTO = 20;

    /** Product attributes that are selects but never a preference. */
    private const SYSTEM_CODES = [
        'status',
        'visibility',
        'tax_class_id',
        'country_of_manufacture',
        'quantity_and_stock_status',
        'gift_message_available',
        'msrp_display_actual_price_type',
        'custom_design',
        'page_layout',
        'options_container',
        'custom_layout',
        'gift_wrapping_available',
        'is_returnable',
    ];

    private const CACHE_KEY = 'fm_personalization_profile_attributes';
    private const CACHE_TTL = 3600;

    /** @var array<int, array{codes: string[], source: string, auto: string[], facts: string[]}> */
    private array $memo = [];

    public function __construct(
        private readonly PersonalizationConfig $config,
        private readonly ResourceConnection $resource,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * The attribute codes to profile for a store, in priority order.
     *
     * @return string[]
     */
    public function resolve(?int $storeId = null): array
    {
        return $this->describe($storeId)['codes'];
    }

    /**
     * The same list with the reasoning attached, for the doctor and the CLI banners.
     *
     * @return array{codes: string[], source: string, auto: string[], facts: string[]}
     */
    public function describe(?int $storeId = null): array
    {
        $key = (int) $storeId;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $configured = $this->config->getProfileAttributeCodes($storeId);
        $facts = array_values(array_unique(array_values($this->config->getFactAttributes($storeId))));

        if ($configured) {
            $codes = $configured;
            $source = self::SOURCE_CONFIGURED;
            $auto = [];
        } else {
            $auto = $this->autoDetect();
            $codes = $auto;
            $source = self::SOURCE_AUTO;
        }

        $codes = array_values(array_unique(array_merge($codes, $facts)));

        return $this->memo[$key] = [
            'codes' => $codes,
            'source' => $source,
            'auto' => $auto,
            'facts' => $facts,
        ];
    }

    /**
     * The list the catalogue discrimination table is measured on: the profiled attributes plus
     * `category`, which is profiled implicitly (every purchase carries its categories).
     *
     * @return string[]
     */
    public function forDiscrimination(?int $storeId = null): array
    {
        return array_values(array_unique(array_merge($this->resolve($storeId), ['category'])));
    }

    public function forget(): void
    {
        $this->memo = [];
        $this->cache->remove(self::CACHE_KEY);
    }

    /**
     * @return string[]
     */
    private function autoDetect(): array
    {
        $cached = $this->cache->load(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return array_values(array_map('strval', $decoded));
            }
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_code'])
            ->join(
                ['t' => $this->resource->getTableName('eav_entity_type')],
                't.entity_type_id = a.entity_type_id',
                []
            )
            ->join(
                ['c' => $this->resource->getTableName('catalog_eav_attribute')],
                'c.attribute_id = a.attribute_id',
                []
            )
            ->join(
                ['o' => $this->resource->getTableName('eav_attribute_option')],
                'o.attribute_id = a.attribute_id',
                ['options' => new \Zend_Db_Expr('COUNT(DISTINCT o.option_id)')]
            )
            ->where('t.entity_type_code = ?', \Magento\Catalog\Model\Product::ENTITY)
            ->where('a.frontend_input IN (?)', ['select', 'multiselect'])
            ->where('c.is_filterable > 0 OR c.is_filterable_in_search = 1')
            ->where('a.attribute_code NOT IN (?)', self::SYSTEM_CODES)
            ->group('a.attribute_code')
            ->having('COUNT(DISTINCT o.option_id) >= 2')
            ->order(new \Zend_Db_Expr('COUNT(DISTINCT o.option_id) DESC'))
            ->order('a.attribute_code ASC');

        $codes = [];
        foreach ($connection->fetchAll($select) as $row) {
            $codes[] = (string) $row['attribute_code'];
        }

        // Colour and size lead when present: they are the attributes most stores' shoppers are
        // most consistent about, and keeping them first keeps existing profiles' shape stable.
        $lead = array_values(array_intersect(['color', 'size'], $codes));
        $rest = array_values(array_diff($codes, $lead));
        $codes = array_slice(array_merge($lead, $rest), 0, self::MAX_AUTO);

        $this->cache->save(
            (string) json_encode($codes),
            self::CACHE_KEY,
            [\Magento\Eav\Model\Cache\Type::CACHE_TAG, \Magento\Eav\Model\Entity\Attribute::CACHE_TAG],
            self::CACHE_TTL
        );

        return $codes;
    }
}
