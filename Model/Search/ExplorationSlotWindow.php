<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Search;

use ParkkTech\FastMagento\Model\Search\ExplorationWindowInterface;

/**
 * Plugs ExplorationSlot into core's InstantSearch through the ExplorationWindowInterface seam.
 */
class ExplorationSlotWindow implements ExplorationWindowInterface
{
    public function __construct(
        private readonly \ParkkTech\FastMagento\Model\Personalization\ExplorationSlot $exploration
    ) {
    }

    public function isActive(?int $storeId = null): bool
    {
        return $this->exploration->isActive($storeId);
    }

    public function windowSize(int $pageSize): int
    {
        return $this->exploration->windowSize($pageSize);
    }

    public function permute(array $ids, int $pageSize, ?int $storeId = null): array
    {
        return $this->exploration->permute($ids, $pageSize, $storeId);
    }
}
