<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Controller\Event;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use ParkkTech\FastMagentoPersonalization\Model\Analytics\EventCollector;

/**
 * Where the browser reports what the server could not see.
 *
 * Views and dwell are the reason this path exists: a product page is served from the full-page
 * cache without ever reaching PHP, so the server genuinely does not know it was viewed, let alone
 * for how long. Impressions and hover join them for the same reason.
 *
 * FACET SELECTIONS ARRIVE HERE TOO, on any store with a full-page cache, and the correction is
 * worth recording. They were collected server-side on the argument that they arrive as request
 * parameters and PHP is already holding them — true, and true only on a cache MISS. Measured: the
 * first request for a filtered listing ran PHP and recorded; the next two were served from cache in
 * a fortieth of the time and recorded nothing. See {@see CaptureMode}, which decides which side
 * reports, so that exactly one of them does.
 *
 * Searches need no such treatment on this module, because the results grid is fetched from an
 * uncacheable endpoint that records as it serves — the browser asking for results IS the signal.
 *
 * CSRF is opted out of deliberately, and the reasoning matters because opting out is usually wrong.
 * This endpoint performs no action on the shopper's behalf — it appends an analytics row attributed
 * to whoever the request already is. There is no state a forged request could change that the
 * forger could not change by simply visiting the page themselves. Requiring a form key would mean
 * embedding one in a cached page, which is precisely the session-varying content the full-page
 * cache forbids, and `navigator.sendBeacon` cannot negotiate for one on unload. The input is
 * treated as hostile regardless: a product id is an integer, dwell is clamped, and nothing here
 * trusts a string.
 */
class Collect implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** One page cannot report more than this; a loop or a bot should not become a profile. */
    private const MAX_EVENTS_PER_REQUEST = 20;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly EventCollector $events,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Analytics\FacetSelectionReader $facets,
        private readonly \ParkkTech\FastMagentoPersonalization\Model\Analytics\CaptureMode $captureMode
    ) {
    }

    public function execute()
    {
        $accepted = 0;

        try {
            $payload = json_decode((string) $this->request->getContent(), true);
            $rows = is_array($payload) ? ($payload['events'] ?? []) : [];

            if (is_array($rows)) {
                foreach (array_slice($rows, 0, self::MAX_EVENTS_PER_REQUEST) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    // Count what was actually stored, not what was offered. A bounce below the
                    // dwell floor is discarded, and reporting it as accepted would make the
                    // endpoint lie about its own behaviour.
                    $stored = match ($row['type'] ?? '') {
                        'view' => $this->events->recordView(
                            (int) ($row['product_id'] ?? 0),
                            (int) ($row['dwell_seconds'] ?? 0)
                        ),
                        'impression' => $this->events->recordImpressions(
                            is_array($row['product_ids'] ?? null) ? $row['product_ids'] : []
                        ),
                        'hover' => $this->events->recordHover(
                            (int) ($row['product_id'] ?? 0),
                            (int) ($row['hover_ms'] ?? 0)
                        ),
                        // What the browser sends is a raw query string, never a list of filters.
                        // The merchant's facet allowlist is applied HERE, so a page that is stale,
                        // forged or simply from another site cannot invent a preference out of a
                        // parameter the merchant never configured as a facet.
                        'facet' => $this->recordFacet((string) ($row['query'] ?? '')),
                        default => false,
                    };

                    if ($stored) {
                        $accepted++;
                    }
                }
            }
        } catch (\Throwable $e) {
            // A beacon has nobody to report an error to, and the shopper has already left the page.
        }

        // 204-shaped: the browser is not waiting for this and must never be blocked by it.
        return $this->jsonFactory->create()->setData(['accepted' => $accepted]);
    }

    /**
     * A browser-reported facet selection, accepted only where the browser is the one responsible
     * for reporting it.
     *
     * The mode check is not belt-and-braces. A merchant who turns the full-page cache OFF hands
     * responsibility back to PHP, and a page still open in somebody's tab would otherwise keep
     * posting selections that the observer is now also recording — the same click counted twice,
     * for as long as that tab lives.
     */
    private function recordFacet(string $query): bool
    {
        if (!$this->captureMode->isBrowserOwned()) {
            return false;
        }

        $filters = $this->facets->fromQueryString($query);

        return $filters ? $this->events->recordFacetSelection($filters) : false;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
