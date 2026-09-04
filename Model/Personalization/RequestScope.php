<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Personalization;

/**
 * Carries the shopper's identity from the start of the request to the point the search query is
 * built.
 *
 * This exists because of a constraint that is easy to miss and impossible to work around: on a
 * CACHEABLE page, Magento does not resolve the customer session while rendering. Proven by
 * instrumenting both ends of one category request — the pre-dispatch observer sees customer 2,
 * and the search adapter, later in the same request, sees no customer at all. That is deliberate on
 * Magento's part, and correct: a page destined for the full-page cache must not be built from
 * session state, or it would be cached with one shopper's data and served to the next.
 *
 * So identity is resolved once at pre-dispatch, where the session is still authoritative, and
 * carried here for the adapter to read. Nothing about the cache safety changes: the same observer
 * puts a boost signature into the HTTP context, so a personalised page gets its own cache entry
 * regardless.
 *
 * Request-scoped by virtue of Magento's object manager giving one instance per request. It holds an
 * id, never a profile — the repository memoises the profile itself.
 */
class RequestScope
{
    private ?int $customerId = null;

    private ?string $anonId = null;

    /**
     * The personalisation signature for this request.
     *
     * Kept beside the customer id because it is needed in two places that cannot see each other:
     * the pre-dispatch observer that puts it in the page-cache vary, and any BLOCK whose cache key
     * must fork by it. A block cache entry is keyed by the block's own key info and nothing else —
     * it knows nothing about the HTTP context — so a personalised block would otherwise be stored
     * once and handed to every shopper, which is the full-page-cache leak repeated one level down.
     */
    private ?string $signature = null;

    public function setCustomerId(?int $customerId): void
    {
        $this->customerId = $customerId !== null && $customerId > 0 ? $customerId : null;
    }

    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }

    /**
     * The analytics cookie, captured at the same pre-dispatch moment as the customer id and for the
     * same reason: by the time a cacheable page builds its query, the request's cookies are still
     * readable, but capturing both identities in one place keeps "who is this request for" a single
     * question with a single answer.
     *
     * Only meaningful when no customer id is set — a logged-in shopper is served their customer
     * profile and the cookie is merely how their events are stitched.
     */
    public function setAnonId(?string $anonId): void
    {
        $this->anonId = $anonId !== null && $anonId !== '' ? $anonId : null;
    }

    public function getAnonId(): ?string
    {
        return $this->anonId;
    }

    public function setSignature(?string $signature): void
    {
        $this->signature = $signature;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    /** @var array{from: int, size: int}|null */
    private ?array $explorationWindow = null;

    /**
     * The page the shopper actually asked for, remembered across the fetch-window expansion.
     *
     * The exploration slot needs the ranking BELOW the fold to choose from, so the request that
     * goes to OpenSearch asks for more than the page — and the two plugins involved cannot see each
     * other: one rewrites the query body, the other receives the raw response. This is the note
     * passed between them. Consumed exactly once, because a request can run more than one search
     * and only the one that was expanded may be sliced.
     */
    public function setExplorationWindow(int $from, int $size): void
    {
        $this->explorationWindow = ['from' => $from, 'size' => $size];
    }

    /** @return array{from: int, size: int}|null */
    public function takeExplorationWindow(): ?array
    {
        $window = $this->explorationWindow;
        $this->explorationWindow = null;

        return $window;
    }

    private ?int $categoryId = null;

    /** The category being listed, captured at pre-dispatch for the PLP arm. */
    public function setCategoryId(?int $categoryId): void
    {
        $this->categoryId = $categoryId !== null && $categoryId > 0 ? $categoryId : null;
    }

    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }

    private ?array $rerankWindow = null;

    /**
     * The position-aware re-rank the response plugin owes: the page the shopper asked for, the
     * band (positions a maximal boost can move a product) and the strength multiplier.
     */
    public function setRerankWindow(int $from, int $size, int $band, float $strength): void
    {
        $this->rerankWindow = ['from' => $from, 'size' => $size, 'band' => $band, 'strength' => $strength];
    }

    public function peekRerankWindow(): ?array
    {
        return $this->rerankWindow;
    }

    public function takeRerankWindow(): ?array
    {
        $window = $this->rerankWindow;
        $this->rerankWindow = null;
        return $window;
    }
}
