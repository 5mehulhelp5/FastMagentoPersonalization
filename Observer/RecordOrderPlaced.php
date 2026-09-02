<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use ParkkTech\FastMagentoPersonalization\Model\Analytics\EventCollector;

/**
 * Record the moment an order is placed, attributed to the arm that produced it.
 *
 * Registered GLOBALLY, not per area, and the reason is this store's own architecture: Hyvä routes
 * checkout through Luma, whose payment step submits over webapi_rest, and GraphQL checkouts exist
 * too — an observer in etc/frontend/events.xml would count only the checkouts that happen to run
 * in the frontend area, which is a biased numerator wearing the costume of a conversion rate. The
 * analytics cookie rides the checkout requests like any other same-origin request, so attribution
 * works in all three areas.
 *
 * `sales_order_place_after` rather than a success-page observer, because the success page is
 * exactly the kind of request that gets lost — closed tabs, redirect wallets, one-page checkouts
 * that confirm inline. The order PLACING is the conversion; whether the shopper ever saw the
 * thank-you page is not.
 */
class RecordOrderPlaced implements ObserverInterface
{
    public function __construct(
        private readonly EventCollector $events
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getData('order');
            if (!$order || !$order->getEntityId()) {
                return;
            }

            $this->events->recordOrder(
                (int) $order->getEntityId(),
                (float) $order->getGrandTotal(),
                (int) $order->getTotalItemCount()
            );
        } catch (\Throwable $e) {
            // An order must never fail to place because analytics hiccuped.
        }
    }
}
