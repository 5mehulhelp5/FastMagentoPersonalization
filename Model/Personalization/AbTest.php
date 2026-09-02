<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Personalization;

/**
 * The A/B split: who gets personalisation, and who is the control that proves it.
 *
 * Everything else this module does shows that personalisation ranks DIFFERENTLY and defensibly.
 * Only a held-out control can show it ranks BETTER — a conversion rate with nothing to compare
 * against is a number, not a result. So with the test on, half of shoppers get the full
 * personalised experience and half get exactly today's storefront, and the dashboard compares what
 * they went on to buy.
 *
 * ASSIGNMENT IS ARITHMETIC, NOT STORAGE. A shopper's arm is a hash of the analytics cookie they
 * already carry, so it is sticky across sessions, needs no table, no lookup, no expiry handling —
 * and it can be recomputed anywhere, including inside an OpenSearch aggregation, which is what
 * lets the dashboard slice months of events by arm without a single stored assignment.
 *
 * The CONTROL ARM IS TRUE CONTROL: no boosts, no facts, no variant preselection — the shopper's
 * profile is still BUILT (collection never stops; that is the point of the independent switches)
 * but nothing reads it at serving time. Their cache signature is therefore '0' and they share the
 * anonymous majority's page-cache entries, which means the test costs the cache nothing.
 *
 * The exploration slot runs for BOTH arms, deliberately. It is a fact about the catalogue, not
 * about the shopper — §5 fairness for new products — and holding it out of the control would fold
 * two experiments into one number. This test measures per-shopper personalisation, cleanly.
 *
 * A shopper with no cookie cannot be assigned, is served the personalised path (they have no
 * profile, so the difference is nil), and is EXCLUDED from the metrics — an unattributable session
 * in either denominator would just be noise wearing a lab coat.
 */
class AbTest
{
    public const ARM_PERSONALIZED = 'personalized';
    public const ARM_CONTROL = 'control';

    public function __construct(
        private readonly PersonalizationConfig $config,
        private readonly RequestScope $requestScope,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Analytics\AnonymousId $anonymousId
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->config->isApplied($storeId) && $this->config->isAbTestEnabled($storeId);
    }

    /**
     * The arm a shopper key belongs to — pure arithmetic, stable forever for a given key.
     */
    public function armFor(string $shopperKey): string
    {
        return crc32($shopperKey) % 2 === 0 ? self::ARM_PERSONALIZED : self::ARM_CONTROL;
    }

    /**
     * Whether the CURRENT request's shopper should be served personalisation.
     *
     * True whenever the test is off — the test is a measurement layer over serving, not a second
     * switch — and true for the unassignable, per the class comment.
     */
    public function shouldPersonalize(?int $storeId = null): bool
    {
        if (!$this->isEnabled($storeId)) {
            return true;
        }

        $key = $this->currentShopperKey();
        if ($key === null) {
            return true;
        }

        return $this->armFor($key) === self::ARM_PERSONALIZED;
    }

    /**
     * The current shopper's arm for STAMPING onto events, or null when unassignable.
     *
     * Stamped even while the test is off, so the day a merchant switches the test on, weeks of
     * already-collected events are already labelled and the dashboard is not starting from zero —
     * the same always-warm principle as profile building.
     */
    public function currentArm(): ?string
    {
        $key = $this->currentShopperKey();

        return $key === null ? null : $this->armFor($key);
    }

    /**
     * The analytics cookie, which both tiers share and which survives login — a shopper must not
     * switch arms mid-experiment by signing in. Customer id is the fallback for cookie-less
     * authenticated clients (headless GraphQL), where it is the only stable key there is.
     */
    private function currentShopperKey(): ?string
    {
        $anonId = $this->requestScope->getAnonId();
        if ($anonId !== null && $anonId !== '') {
            return $anonId;
        }

        // A cookie issued DURING this request — a first-time visitor's first event — was not there
        // when pre-dispatch captured identity, but the issuer memoises it. Read-only: passing true
        // here would have arm assignment minting identities, which is the collector's job.
        $issued = $this->anonymousId->get(false);
        if ($issued !== null && $issued !== '') {
            return $issued;
        }

        $customerId = $this->requestScope->getCustomerId();

        return $customerId !== null && $customerId > 0 ? 'cust:' . $customerId : null;
    }
}
