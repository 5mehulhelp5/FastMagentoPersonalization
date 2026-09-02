<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Analytics;

use Magento\Framework\App\Cache\StateInterface;

/**
 * Who is responsible for reporting a facet selection — PHP, or the browser.
 *
 * Exactly one of them, never both, and the choice is forced rather than preferred.
 *
 * A facet selection arrives as a request parameter, so PHP is holding it and recording it there is
 * free. That was the original design and it is right on a store with no full-page cache. It is
 * quietly, badly wrong on a store with one: measured on `magedemo`, the first request for
 * `/women/tops-women.html?color=49` took 1.19s, ran PHP and recorded the event; the second and
 * third took 0.04s, were served from cache without PHP running at all, and recorded nothing. Every
 * shopper after the one who warmed that cache entry is invisible until it expires.
 *
 * Worse than sparse, it is BIASED, and in the direction that matters most. A popular filter
 * combination is almost always cached, so it is recorded roughly once per TTL no matter how many
 * shoppers choose it. A rare combination misses the cache nearly every time and is recorded nearly
 * every time. Ranking on that data would systematically over-weight the filters fewest people use.
 * Losing the signal outright would at least be obvious; this looks like data.
 *
 * So: with a full-page cache in front, the BROWSER reports the selection, through the same endpoint
 * that already reports views, impressions and hover for exactly the same reason. With no full-page
 * cache, PHP sees every request and reports it itself, and the browser is told to stay quiet.
 *
 * The cost is honest and worth stating: a shopper with JavaScript disabled stops contributing facet
 * selections on a cached store. That is already true of three of the five signals collected here,
 * and on a storefront whose search grid is itself JavaScript, such a shopper is not really shopping.
 */
class CaptureMode
{
    public function __construct(
        private readonly StateInterface $cacheState
    ) {
    }

    /**
     * True when a full-page cache is in front of the storefront, so PHP cannot be relied on to see
     * a listing request at all.
     */
    public function isBrowserOwned(): bool
    {
        try {
            return $this->cacheState->isEnabled(\Magento\PageCache\Model\Cache\Type::TYPE_IDENTIFIER);
        } catch (\Throwable $e) {
            // If the question cannot be answered, assume the cache is there. Over-assuming costs a
            // no-JS shopper's facet clicks; under-assuming costs a biased sample presented as fact.
            return true;
        }
    }
}
