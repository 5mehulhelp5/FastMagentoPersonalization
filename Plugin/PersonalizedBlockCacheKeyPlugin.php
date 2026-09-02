<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Plugin;

use ParkkTech\FastMagentoPersonalization\Model\Personalization\RequestScope;

/**
 * Fork a personalised block's cache entry by the same signature that forks the page cache.
 *
 * The full-page cache is not the only cache on the path. A block's HTML is cached under
 * `getCacheKeyInfo()` alone — it has no knowledge of the HTTP context — so a configurable product's
 * option JSON, once rendered with one shopper's preselected size and colour, is handed unchanged to
 * every later shopper regardless of what the page cache decided.
 *
 * Measured before this existed: with `block_html` enabled, four shoppers with four different
 * profiles and four distinct `X-Magento-Vary` values all received the FIRST one's preselection.
 * Disabling `block_html` made all four correct, which is what identified it. The page-cache
 * signature was doing its job; the block cache below it was not.
 *
 * Appending the same signature keeps the two layers in step: shoppers who share a page-cache entry
 * share the block entry, and shoppers who do not, do not. An unpersonalised shopper appends nothing
 * and keeps exactly today's key, so nothing about a store that never turns this on changes.
 */
class PersonalizedBlockCacheKeyPlugin
{
    public function __construct(
        private readonly RequestScope $requestScope
    ) {
    }

    /**
     * @param array<int, mixed> $result
     * @return array<int, mixed>
     */
    public function afterGetCacheKeyInfo(object $subject, array $result): array
    {
        $signature = $this->requestScope->getSignature();
        if ($signature === null || $signature === '' || $signature === '0') {
            return $result;
        }

        $result[] = 'fm_personalization_' . $signature;

        return $result;
    }
}
