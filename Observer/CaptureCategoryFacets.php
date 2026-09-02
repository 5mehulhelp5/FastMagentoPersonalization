<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Observer;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use ParkkTech\FastMagentoPersonalization\Model\Analytics\CaptureMode;
use ParkkTech\FastMagentoPersonalization\Model\Analytics\EventCollector;
use ParkkTech\FastMagentoPersonalization\Model\Analytics\FacetSelectionReader;

/**
 * Record facet selections made on a category listing — when PHP is the one that can see them.
 *
 * Which is not always, and that is the whole reason this class no longer acts unconditionally. With
 * a full-page cache in front, this observer runs for the shopper who WARMS a cache entry and for
 * nobody after them; see {@see CaptureMode} for the measurement and for why a sample shaped like
 * that is worse than no sample. On such a store the browser reports the selection instead, and this
 * returns immediately so the two can never both count the same click.
 *
 * Runs on the category action specifically, which fires after the generic pre-dispatch that
 * resolves who the shopper is — so an event can be attributed rather than discarded.
 */
class CaptureCategoryFacets implements ObserverInterface
{
    public function __construct(
        private readonly EventCollector $events,
        private readonly FacetSelectionReader $reader,
        private readonly CaptureMode $captureMode,
        private readonly RequestInterface $request
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if ($this->captureMode->isBrowserOwned()) {
                // The browser owns this. Recording here as well would double-count exactly the
                // shopper who happened to warm the cache — the one request that is already the
                // least representative of the population.
                return;
            }

            $filters = $this->reader->fromParams($this->request->getParams());
            if (!$filters) {
                // An unfiltered category view is a browse, not a stated preference. M4 records what
                // a shopper ASKED for; plain views are a later, weaker signal.
                return;
            }

            $this->events->recordFacetSelection($filters);
        } catch (\Throwable $e) {
            // Never let analytics break a category page.
        }
    }
}
