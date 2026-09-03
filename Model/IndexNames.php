<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Names of the OpenSearch indexes this module owns, derived from the same default prefix core's
 * product and category indexes use (`catalog/search/opensearch_index_prefix`), so they sit next
 * to them in the cluster.
 */
class IndexNames
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Per-shopper personalisation profile index (sibling of the product/category indexes).
     * One document per shopper — customer id when known, anonymous id otherwise — holding decayed
     * attribute affinities, requirements and traits. e.g. magento2_user_profiles.
     *
     * Deliberately its OWN index rather than a field on the product index: profiles change on a
     * different schedule, carry personal data with its own retention rules, and must be deletable
     * without touching the catalogue.
     */
    public function getUserProfileIndexName(): string
    {
        $defaultPrefix = $this->scopeConfig->getValue('catalog/search/opensearch_index_prefix') ?? 'magento2';
        $customSuffix = $this->scopeConfig->getValue('fastmagento/indexing/opensearch_profile_index_prefix') ?? 'user_profiles';

        return $defaultPrefix . '_' . $customSuffix;
    }

    /**
     * Behavioural events — searches and facet selections.
     *
     * Its own index because its lifecycle is nothing like the others': it is append-only, it is the
     * only store here holding raw shopper actions rather than derived summaries, and a merchant
     * will want to age it out on a retention schedule the profile index must not share.
     */
    public function getEventIndexName(): string
    {
        $defaultPrefix = $this->scopeConfig->getValue('catalog/search/opensearch_index_prefix') ?? 'magento2';

        return $defaultPrefix . '_events';
    }

    /**
     * Where the per-value discrimination (IDF) table lives.
     *
     * Separate from the profile index on purpose: profiles are per shopper and change constantly,
     * this is one small document per store view that only changes on reindex.
     */
    public function getValueDiscriminationIndexName(): string
    {
        $defaultPrefix = $this->scopeConfig->getValue('catalog/search/opensearch_index_prefix') ?? 'magento2';

        return $defaultPrefix . '_value_discrimination';
    }

    /**
     * Where the per-product exposure table lives — impressions, units sold in the same window, and
     * the conversion rate we can defend from the two.
     */
    public function getProductExposureIndexName(): string
    {
        $defaultPrefix = $this->scopeConfig->getValue('catalog/search/opensearch_index_prefix') ?? 'magento2';

        return $defaultPrefix . '_product_exposure';
    }

    /** Where the auto-weight tuner records each decision — the dashboard's weight curve. */
    public function getTuningIndexName(): string
    {
        $defaultPrefix = $this->scopeConfig->getValue('catalog/search/opensearch_index_prefix') ?? 'magento2';

        return $defaultPrefix . '_personalization_tuning';
    }
}
