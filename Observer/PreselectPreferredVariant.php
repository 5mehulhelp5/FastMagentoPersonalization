<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagento\Helper\OpenSearchPdpFetcher;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\VariantPreselector;

/**
 * Open a configurable product on the variant this shopper usually buys.
 *
 * Uses Magento's own supported mechanism rather than anything bespoke: setting preconfigured values
 * on the product makes `ConfigurableAttributeData::getAttributeConfigValue()` return them, which
 * puts them in the block's `defaultValues`, which the theme applies on load. That means it works
 * with the stock configurable block and with Hyvä's swatch component without either knowing this
 * exists, and a theme that ignores `defaultValues` simply gets today's behaviour.
 *
 * Deliberately does NOT overwrite an existing preconfiguration. A shopper arriving from a wishlist,
 * a reorder, or a link carrying its own selection has made an explicit choice, and an inferred
 * preference must never beat a stated one.
 */
class PreselectPreferredVariant implements ObserverInterface
{
    public function __construct(
        private readonly VariantPreselector $preselector,
        private readonly OpenSearchPdpFetcher $fetcher,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $product = $observer->getEvent()->getProduct();
            if (!$product || $product->getTypeId() !== 'configurable') {
                return;
            }

            // Stated beats inferred, always.
            if ($product->hasPreconfiguredValues()) {
                return;
            }

            $doc = $this->fetcher->fetchPdpById((int) $product->getId());
            if (!$doc) {
                return;
            }
            $selection = $this->preselector->selectionFor(
                $doc,
                (int) $this->storeManager->getStore()->getId()
            );
            if (!$selection) {
                return;
            }
            $product->setPreconfiguredValues(new DataObject(['super_attribute' => $selection]));
        } catch (\Throwable $e) {
            // A product page must never fail to render because of a preference.
        }
    }
}
