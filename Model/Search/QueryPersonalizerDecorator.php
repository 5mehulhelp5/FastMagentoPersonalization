<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Search;

use ParkkTech\FastMagento\Model\Search\QueryDecoratorInterface;

/**
 * Plugs QueryPersonalizer into core's InstantSearch through the QueryDecoratorInterface seam.
 */
class QueryPersonalizerDecorator implements QueryDecoratorInterface
{
    public function __construct(
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Personalization\QueryPersonalizer $personalizer
    ) {
    }

    public function decorate(
        array $query,
        string $surface,
        string $target,
        ?int $storeId = null,
        ?int $customerId = null
    ): array {
        return $this->personalizer->decorate($query, $surface, $target, $storeId, $customerId);
    }
}
