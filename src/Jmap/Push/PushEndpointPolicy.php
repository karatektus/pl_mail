<?php

declare(strict_types=1);

namespace App\Jmap\Push;

use App\Infrastructure\Http\PrivateNetwork;
use App\Infrastructure\Http\TrustedHosts;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which push endpoint URLs a client may register.
 *
 * A push subscription is a URL the server will POST to on every change, for as
 * long as the row lives — so an unchecked one is a standing SSRF: register
 * `http://database:5432/` or `http://169.254.169.254/` through the web app or
 * PushSubscription/set, and every new mail sends a request into the container
 * network. Real push services are on the public internet; the one exception is
 * a self-hosted ntfy, which an administrator names in PUSH_ALLOWED_HOSTS.
 *
 * Checked at registration for a readable refusal. The send itself goes through
 * the guarded push client as well, which re-checks at connect time and follows
 * no redirects — a name that resolved publicly today may not tomorrow.
 */
final readonly class PushEndpointPolicy
{
    public function __construct(
        #[Autowire(service: 'app.push_trusted_hosts')]
        private TrustedHosts $trustedHosts,
    ) {
    }

    public function isAllowed(string $url): bool
    {
        $parts = parse_url($url);

        if (false === is_array($parts) || false === isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (false === in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        if (true === isset($parts['user']) || true === isset($parts['pass'])) {
            return false;
        }

        if (true === $this->trustedHosts->isTrusted($parts['host'])) {
            return true;
        }

        return false === PrivateNetwork::isPrivateHost($parts['host']);
    }
}
