<?php

declare(strict_types=1);

namespace ParkkTech\FastMagentoPersonalization\Model\Analytics;

use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * A stable, first-party id for a shopper who is not logged in.
 *
 * PERSISTENT BY DECISION, including through logout. A returning guest is the same person as the
 * guest who visited last week, and rotating the id would throw that away for no privacy gain — the
 * cookie is first-party and holds nothing but a random string. What DOES stop at logout is
 * attribution to a customer account; the anonymous thread continues. See PERSONALIZATION.md §3,
 * which settles this and is the reason it is not a per-session value.
 *
 * Deliberately not the Magento session id: that rotates on login, on logout, and on expiry, which
 * is exactly the continuity this exists to preserve.
 */
class AnonymousId
{
    public const COOKIE_NAME = 'fm_aid';

    /** A year. Long enough to span a considered purchase, short enough to be a real expiry. */
    private const LIFETIME_SECONDS = 31536000;

    private ?string $memo = null;

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory
    ) {
    }

    /**
     * The current visitor's anonymous id, issuing one if they do not have it yet.
     *
     * Returns null rather than throwing when cookies cannot be written — a crawler, a client that
     * refuses them, or a response already sent. Callers treat null as "do not record", which is the
     * correct outcome: an event we cannot attribute to anyone is noise.
     */
    public function get(bool $issueIfMissing = true): ?string
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $existing = $this->cookieManager->getCookie(self::COOKIE_NAME);
        if (is_string($existing) && preg_match('/^[a-f0-9]{32}$/', $existing)) {
            return $this->memo = $existing;
        }

        if (!$issueIfMissing) {
            return null;
        }

        try {
            // random_bytes() rather than Magento's Random: getRandomBytes() does not reliably return
            // RAW bytes across builds — on this one it hands back a base64 string, so bin2hex made a
            // 48-character value that the 32-character check below rejected, and a fresh id was
            // issued on every single request. A rotating "persistent" id is worse than none: it
            // silently destroys the guest continuity this class exists to provide, while looking
            // like it works.
            $id = bin2hex(random_bytes(16));
            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                ->setDuration(self::LIFETIME_SECONDS)
                ->setPath('/')
                ->setHttpOnly(true)
                ->setSameSite('Lax');
            $this->cookieManager->setPublicCookie(self::COOKIE_NAME, $id, $metadata);

            return $this->memo = $id;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
