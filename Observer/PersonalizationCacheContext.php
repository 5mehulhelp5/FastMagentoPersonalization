<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Observer;

use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\PersonalizationConfig;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\QueryPersonalizer;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\RequestScope;
use ParkkTech\FastMagentoPersonalization\Model\Personalization\ValueDiscrimination;

/**
 * Make the full-page cache aware that a listing may have been re-ordered for this shopper.
 *
 * Without this the PLP arm is not merely imperfect, it is wrong, and it was measured to be wrong
 * rather than assumed: two different logged-in customers on this store produce the IDENTICAL
 * `X-Magento-Vary` value, because Magento varies its page cache by customer GROUP and not by
 * customer. So the first personalised listing to be rendered would be cached and then served,
 * verbatim, to every other shopper in that group — the wrong order for them, and a disclosure of
 * the first shopper's taste to everyone else.
 *
 * The fix is the segment-keyed route rather than the per-shopper one. The cache key gains a short
 * signature of the BOOSTS THIS REQUEST WOULD EMIT, not of the shopper's identity. Two shoppers who
 * would receive an identical re-ordering share one cache entry, which is correct — the page really
 * is the same — and shoppers whose boosts differ can no longer collide. A shopper with no
 * actionable, discriminating affinity signs as '0' and shares the anonymous entry, so the
 * un-personalised majority costs the cache nothing.
 *
 * Cardinality is the thing to watch, and is why the signature deliberately drops detail: only the
 * FIELD and TERM of each boost, sorted, with no weights. Weights vary continuously and would make
 * nearly every shopper unique, which is the per-shopper cache variant that is the wrong answer at
 * any real traffic level. Two Black-buyers with different purchase depth get the same entry; their
 * pages differ only in the size of an effect that is already ordered the same way.
 */
class PersonalizationCacheContext implements ObserverInterface
{
    /** Appears in the vary hash; the value is the boost signature, or '0' for "not personalised". */
    public const CONTEXT_KEY = 'fastmagento_personalization';

    private const NOT_PERSONALISED = '0';

    public function __construct(
        private readonly HttpContext $httpContext,
        private readonly QueryPersonalizer $personalizer,
        private readonly PersonalizationConfig $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly \Magento\Customer\Model\Session $customerSession,
        private readonly RequestScope $requestScope,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Personalization\ProfileRepository $profileRepository,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Analytics\AnonymousId $anonymousId
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();

            // Capture identity HERE, where the session is still authoritative. By the time the
            // search query is built for a cacheable page, it no longer is.
            $this->requestScope->setCustomerId((int) $this->customerSession->getCustomerId());

            // The guest identity too — READ, never issued. Issuing belongs to the collection
            // paths: a first-time visitor has no history to serve anyway, and a pre-dispatch
            // observer that writes cookies would do it on every request including the ones a
            // crawler makes.
            $this->requestScope->setAnonId($this->anonymousId->get(false));

            // The category being listed, so the PLP arm can prefer what the shopper buys in it and
            // the signature below forks the page cache per category-scoped boost set.
            $request = $observer->getEvent()->getRequest();
            if ($request !== null && method_exists($request, 'getFullActionName')
                && $request->getFullActionName() === 'catalog_category_view') {
                $this->requestScope->setCategoryId((int) $request->getParam('id'));
            }

            if (!$this->config->isApplied($storeId)) {
                // Off. Set nothing at all — an untouched context is what every page produced
                // before this class existed, which is what "byte-identical when off" requires of
                // the cache key as much as of the query.
                return;
            }

            $signature = $this->signature($storeId);
            // Shared with block-level cache keys, which cannot see the HTTP context.
            $this->requestScope->setSignature($signature);

            // The third argument is the DEFAULT. Magento omits a value equal to its default from
            // the vary hash, so un-personalised shoppers keep the same cache key they have today
            // and only genuinely personalised requests fork the cache.
            $this->httpContext->setValue(self::CONTEXT_KEY, $signature, self::NOT_PERSONALISED);
        } catch (\Throwable $e) {
            // Never let this break a page render; worst case the request is not personalised.
        }
    }

    /**
     * A short, deliberately coarse fingerprint of the boosts this shopper would receive.
     */
    private function signature(int $storeId): string
    {
        $terms = [];

        foreach ([PersonalizationConfig::SURFACE_PLP, PersonalizationConfig::SURFACE_SEARCH] as $surface) {
            $categoryId = $surface === PersonalizationConfig::SURFACE_PLP ? $this->requestScope->getCategoryId() : null;
            foreach ($this->personalizer->explain($surface, ValueDiscrimination::TARGET_NATIVE, $storeId, null, $categoryId) as $fn) {
                $term = $fn['filter']['term'] ?? [];
                $field = (string) array_key_first($term);
                if ($field === '') {
                    continue;
                }
                // Field and term only. No weight — see the note on cardinality above.
                $terms[] = $surface . ':' . $field . '=' . (string) ($term[$field] ?? '');
            }
        }

        // Variant preselection changes the rendered page too, and it deliberately uses signals the
        // ranking gates reject — size is refused on a listing and is exactly right on a swatch. If
        // it were left out of the signature, two shoppers could share a cache entry and be served
        // each other's preselected variant, which is the same leak the signature exists to stop.
        foreach ($this->preselectionTerms($storeId) as $term) {
            $terms[] = 'variant:' . $term;
        }

        if (!$terms) {
            return self::NOT_PERSONALISED;
        }

        sort($terms);

        return substr(hash('sha256', implode('|', $terms)), 0, 12);
    }

    /**
     * The shopper's preselection-relevant preferences, product-independent.
     *
     * Product-independent on purpose: the signature keys a CACHE, and two shoppers with the same
     * top size and colour preselect identically on every product, so they can safely share every
     * page. Keying on the chosen variant instead would need the product, which pre-dispatch does
     * not yet know, and would fork the cache far harder for no benefit.
     *
     * @return string[]
     */
    private function preselectionTerms(int $storeId): array
    {
        if (!$this->config->isPreselectingVariants($storeId)) {
            return [];
        }

        $profile = $this->profileForCurrentShopper();
        if ($profile === null) {
            return [];
        }

        $terms = [];
        foreach ($profile['affinities'] ?? [] as $code => $affinity) {
            if (empty($affinity['actionable'])) {
                continue;
            }
            $valueIds = $affinity['value_ids'] ?? [];
            $topLabel = (string) array_key_first($affinity['values'] ?? []);
            if ($topLabel !== '' && isset($valueIds[$topLabel])) {
                $terms[] = $code . '=' . (string) $valueIds[$topLabel];
            }
        }

        return $terms;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function profileForCurrentShopper(): ?array
    {
        $customerId = (int) $this->customerSession->getCustomerId();
        if ($customerId > 0) {
            return $this->profileRepository->get(
                \ParkkTech\FastMagentoPersonalization\Model\Personalization\ProfileRepository::idForCustomer($customerId)
            );
        }

        // The guest tier — same fallback as serving, because this signature exists to describe
        // exactly what serving will do. A guest whose profile would preselect a variant must fork
        // the cache the same way a customer's would, or two guests share a page and one of them
        // gets the other's swatch.
        $anonId = $this->requestScope->getAnonId();
        if ($anonId === null || $anonId === '') {
            return null;
        }

        return $this->profileRepository->get(
            \ParkkTech\FastMagentoPersonalization\Model\Personalization\ProfileRepository::idForAnonymous($anonId)
        );
    }
}
