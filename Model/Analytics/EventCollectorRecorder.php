<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Analytics;

use ParkkTech\FastMagento\Model\Analytics\EventRecorderInterface;

/**
 * Plugs EventCollector into core's instant-search controller through the EventRecorderInterface seam.
 */
class EventCollectorRecorder implements EventRecorderInterface
{
    public function __construct(
        private readonly \ParkkTech\FastMagento\Model\Analytics\EventCollector $events
    ) {
    }

    public function recordSearch(string $query, array $filters = [], int $resultCount = 0): void
    {
        $this->events->recordSearch($query, $filters, $resultCount);
    }

    public function recordFacetSelection(array $filters, ?string $query = null): bool
    {
        return $this->events->recordFacetSelection($filters, $query);
    }
}
