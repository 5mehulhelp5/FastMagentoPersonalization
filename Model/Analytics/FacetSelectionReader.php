<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Analytics;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Which request parameters on a listing URL are a shopper stating a preference.
 *
 * Layered navigation puts each selection in a bare query parameter named after the attribute
 * (`?color=49&size=174`), which is indistinguishable in SHAPE from paging, sorting, a campaign tag
 * or a cache-buster. So selections are read against the attributes the merchant has actually
 * configured as facets, and nothing else counts.
 *
 * That allowlist is the point. Reading every parameter would record `p`, `product_list_order` and
 * whatever a marketing email appended, and the resulting "preferences" would be noise wearing the
 * costume of data.
 *
 * It lives here, in one class, because two callers now need it: the server-side observer, and the
 * browser-reported path that exists because a cached page never reaches that observer. The
 * allowlist is applied SERVER-SIDE in both cases — the browser is told which parameters are worth
 * reporting so it does not post for nothing, but what it says is never what decides.
 */
class FacetSelectionReader
{
    private const XML_PATH_FACET_ATTRIBUTES = 'fastmagento/search/facet_attributes';

    /** Facets that are not EAV attributes and so are never in the configured list. */
    private const STRUCTURAL_FACETS = ['cat', 'price'];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * The stated selections in a set of request parameters, or an empty array when there are none.
     *
     * @param array<string, mixed> $params
     * @return array<string, string[]>
     */
    public function fromParams(array $params): array
    {
        $filters = [];

        foreach ($this->facetCodes() as $code) {
            $value = $params[$code] ?? null;
            if ($value === null || $value === '' || is_object($value)) {
                continue;
            }
            $filters[$code] = is_array($value)
                ? array_values(array_map('strval', $value))
                : explode(',', (string) $value);
        }

        return $filters;
    }

    /**
     * The same, from a raw query string as the browser reports it.
     *
     * @return array<string, string[]>
     */
    public function fromQueryString(string $query): array
    {
        $query = ltrim(trim($query), '?');
        if ($query === '') {
            return [];
        }

        $params = [];
        parse_str($query, $params);

        return $this->fromParams($params);
    }

    /**
     * @return string[]
     */
    public function facetCodes(?int $storeId = null): array
    {
        $configured = (string) $this->scopeConfig->getValue(
            self::XML_PATH_FACET_ATTRIBUTES,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $codes = array_filter(array_map('trim', explode(',', $configured)));

        return array_values(array_unique(array_merge($codes, self::STRUCTURAL_FACETS)));
    }
}
