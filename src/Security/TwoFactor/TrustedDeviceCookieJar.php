<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use App\Entity\User\TrustedDevice;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Holds the trusted-device cookie between the moment it is decided and the
 * moment there is a Response to put it on.
 *
 * TrustedDeviceManagerInterface::addTrustedDevice() is handed a user and a
 * firewall name and nothing else — it runs deep inside authentication, where no
 * Response exists yet. scheb solves this for its own JWT cookie with a token
 * storage plus a response listener; this is the same shape, minus the JWT,
 * because the grant itself lives in the database.
 *
 * Request-scoped: reset between requests so a cookie decided for one user
 * cannot be written onto the next response.
 */
final class TrustedDeviceCookieJar
{
    private ?Cookie $pending = null;

    /**
     * @param bool|null $cookieSecure null is scheb's normalised form of the
     *                                `auto` setting — follow the request
     */
    public function __construct(
        private readonly string $cookieName,
        private readonly ?bool $cookieSecure,
        private readonly ?string $cookieSameSite,
        private readonly string $cookiePath,
        private readonly ?string $cookieDomain,
    ) {
    }

    public function name(): string
    {
        return $this->cookieName;
    }

    /** The secret presented by this request, if any. */
    public function read(Request $request): ?string
    {
        $value = $request->cookies->get($this->cookieName);

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * The presented secret as it is stored — the only form worth comparing.
     *
     * The database never holds the secret itself, so every caller that wants to
     * know "is this row the browser I am talking to?" has to hash first. Doing
     * that here means the hashing and the cookie name are decided together, and
     * a caller comparing the raw cookie against a stored digest would simply
     * never match anything.
     */
    public function currentHash(Request $request): ?string
    {
        $secret = $this->read($request);

        return null === $secret ? null : TrustedDevice::hash($secret);
    }

    public function issue(string $secret, DateTimeImmutable $expiresAt, Request $request): void
    {
        $this->pending = Cookie::create(
            $this->cookieName,
            $secret,
            $expiresAt,
            $this->cookiePath,
            $this->cookieDomain,
            $this->cookieSecure ?? $request->isSecure(),
            true,
            false,
            $this->cookieSameSite,
        );
    }

    /**
     * Queue removal, for a device revoking itself. The row is what actually
     * withdraws the grant; clearing the cookie only saves the browser from
     * presenting a secret that will never be honoured again.
     */
    public function clear(Request $request): void
    {
        $this->pending = Cookie::create(
            $this->cookieName,
            null,
            1,
            $this->cookiePath,
            $this->cookieDomain,
            $this->cookieSecure ?? $request->isSecure(),
            true,
            false,
            $this->cookieSameSite,
        );
    }

    public function takePending(): ?Cookie
    {
        $cookie = $this->pending;
        $this->pending = null;

        return $cookie;
    }

    /** kernel.reset — see the note about request scope on the class. */
    public function reset(): void
    {
        $this->pending = null;
    }
}
