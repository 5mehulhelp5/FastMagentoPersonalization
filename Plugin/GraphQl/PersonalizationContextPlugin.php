<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Plugin\GraphQl;

use Magento\GraphQl\Model\Query\ContextFactory;
use Magento\GraphQl\Model\Query\ContextInterface;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\RequestScope;

/**
 * Give GraphQL requests the same shopper identity the storefront gets.
 *
 * GraphQL does not authenticate through the customer session — it carries a bearer token, and the
 * resolved customer lives on the query context instead. So the pre-dispatch observer that captures
 * identity for a normal page finds nobody here, and without this a token-authenticated shopper
 * would silently receive the anonymous ordering while every switch said personalisation was on.
 *
 * Capturing it at the context factory means one place, before any resolver runs, and it reuses the
 * same request-scoped holder the rest of the module reads from — so personalisation cannot mean one
 * thing over HTML and another over GraphQL.
 *
 * A guest query with the analytics cookie gets the guest tier — the storefront's own GraphQL
 * requests are same-origin and carry it. A headless client with no cookie personalises nothing,
 * which is the correct fallback.
 */
class PersonalizationContextPlugin
{
    public function __construct(
        private readonly RequestScope $requestScope,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Analytics\AnonymousId $anonymousId
    ) {
    }

    public function afterCreate(ContextFactory $subject, ContextInterface $result): ContextInterface
    {
        try {
            $this->requestScope->setCustomerId($result->getUserId());
            // Read, never issued — a GraphQL response is often cached and often headless, and
            // neither should be minting identities.
            $this->requestScope->setAnonId($this->anonymousId->get(false));
        } catch (\Throwable $e) {
            // A GraphQL query must not fail because personalisation could not identify anyone.
        }

        return $result;
    }
}
