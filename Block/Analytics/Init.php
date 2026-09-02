<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Block\Analytics;

use Magento\Framework\View\Element\Template;
use ParkkTech\FastMagentoPersonalization\Model\Analytics\CaptureMode;
use ParkkTech\FastMagentoPersonalization\Model\Analytics\FacetSelectionReader;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\PersonalizationConfig;

/**
 * Boot data for the storefront's analytics collector.
 *
 * Everything here is STORE-level, never shopper-level, which is what makes it safe to render into a
 * page the full-page cache will hand to everyone: an endpoint URL, a list of parameter names the
 * merchant configured as facets, and a boolean saying whether the browser is the one responsible
 * for reporting a selection. Nothing about who is looking.
 *
 * The facet list is an OPTIMISATION and nothing more — it stops the browser posting on every
 * paginated or sorted listing view. The list that decides is the one the controller reads from
 * config on receipt, because a value that reached the browser is a value someone can edit.
 */
class Init extends Template
{
    public function __construct(
        Template\Context $context,
        private readonly PersonalizationConfig $personalizationConfig,
        private readonly FacetSelectionReader $facets,
        private readonly CaptureMode $captureMode,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnalyticsConfig(): array
    {
        return [
            'collectUrl' => $this->getUrl('fastmagento/event/collect'),
            'collect' => $this->personalizationConfig->isCollectingEvents(),
            // Whether the browser should report facet selections at all. False on a store with no
            // full-page cache, where PHP sees every request and reports them itself — having both
            // report would count the same click twice.
            'captureFacets' => $this->captureMode->isBrowserOwned(),
            'facetParams' => $this->facets->facetCodes(),
        ];
    }
}
