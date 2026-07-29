<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration;
use App\Entity\IntegrationProviderConfig;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decides which server address a driver is allowed to talk to.
 *
 * Self-hosted providers let the user name their own server, which means an
 * authenticated user can aim our outbound HTTP client wherever they like —
 * a classic SSRF surface, and a real one here because the app runs in a
 * container network alongside Postgres, Mercure and the messenger workers.
 *
 * Three defences, in order of strength:
 *
 *   An admin who pins baseUrl on the provider config removes the surface
 *   entirely. resolve() ignores the user's value when one is pinned, so a
 *   stale row from before the pin cannot keep reaching elsewhere.
 *
 *   http:// is refused unless INTEGRATIONS_ALLOW_HTTP is on. Self-hosting on
 *   a LAN is the normal case for Nextcloud and Immich, so this flag will often
 *   be set — the point is that plaintext credentials over the wire become a
 *   deliberate admin decision instead of a silent default.
 *
 *   Loopback, link-local and private ranges are refused outright unless the
 *   host appears in INTEGRATIONS_ALLOWED_HOSTS. This is the check that stops
 *   http://localhost:5432 and the cloud metadata endpoint at 169.254.169.254.
 *
 * Deliberately not a full DNS-rebinding defence: a hostname resolving to a
 * private address at connect time still gets through. Closing that needs
 * pinning the resolved IP into the HTTP client, which Symfony's client does
 * not expose. The allow-list is the honest mitigation, and admins pinning
 * baseUrl sidestep the question.
 */
final readonly class IntegrationUrlValidator
{
    /** Ranges no user-supplied host may resolve into without being allow-listed. */
    private const array BLOCKED_RANGES = [
        '127.0.0.0/8',      // loopback
        '10.0.0.0/8',       // RFC1918
        '172.16.0.0/12',    // RFC1918
        '192.168.0.0/16',   // RFC1918
        '169.254.0.0/16',   // link-local, incl. cloud metadata at 169.254.169.254
        '100.64.0.0/10',    // carrier-grade NAT
        '0.0.0.0/8',        // "this network"
    ];

    /** @var list<string> */
    private array $allowedHosts;

    public function __construct(
        #[Autowire(env: 'bool:INTEGRATIONS_ALLOW_HTTP')]
        private bool $allowHttp = false,
        #[Autowire(env: 'INTEGRATIONS_ALLOWED_HOSTS')]
        string $allowedHosts = '',
    ) {
        $this->allowedHosts = array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', $allowedHosts),
        )));
    }

    /**
     * The base URL a driver should use for this connection, admin pin winning
     * over the user's own value.
     *
     * @throws IntegrationException if neither side supplied a usable address
     */
    public function resolve(Integration $integration, ?IntegrationProviderConfig $config): string
    {
        $pinned = $config?->baseUrl;

        if (null !== $pinned && '' !== $pinned) {
            return rtrim($pinned, '/');
        }

        $own = $integration->baseUrl;

        if (null === $own || '' === $own) {
            throw new IntegrationException(sprintf(
                'No server address configured for %s.',
                $integration->provider->label(),
            ));
        }

        $this->assertAllowed($own);

        return rtrim($own, '/');
    }

    /**
     * Whether a user may still edit the address, i.e. the provider is
     * self-hosted and no admin has pinned one. Drives both the form field and
     * the fact that we only validate what a user could actually have set.
     */
    public function isUserEditable(Provider $provider, ?IntegrationProviderConfig $config): bool
    {
        if (false === $provider->needsBaseUrl()) {
            return false;
        }

        return null === $config?->baseUrl || '' === $config->baseUrl;
    }

    /**
     * @throws IntegrationException if the URL is malformed or points somewhere
     *                              a user must not be able to reach
     */
    public function assertAllowed(string $url): void
    {
        $parts = parse_url($url);

        if (false === is_array($parts) || false === isset($parts['scheme'], $parts['host'])) {
            throw new IntegrationException('Server address must be a full URL, for example https://cloud.example.com.');
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (false === in_array($scheme, ['http', 'https'], true)) {
            throw new IntegrationException('Server address must use http or https.');
        }

        if ('http' === $scheme && false === $this->allowHttp) {
            throw new IntegrationException('Server address must use https. Ask your administrator to allow plain http if this server has no certificate.');
        }

        // Credentials in the URL would be logged wherever the URL is, and would
        // silently override the ones on the connection.
        if (true === isset($parts['user']) || true === isset($parts['pass'])) {
            throw new IntegrationException('Server address must not contain a username or password.');
        }

        $host = strtolower((string) $parts['host']);

        if (true === in_array($host, $this->allowedHosts, true)) {
            return;
        }

        $this->assertHostNotInternal($host);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function assertHostNotInternal(string $host): void
    {
        // Bracketed IPv6 literal, e.g. [::1].
        $bare = trim($host, '[]');

        if ('localhost' === $host || true === str_ends_with($host, '.localhost')) {
            throw new IntegrationException('Server address must not point at this machine.');
        }

        if (false !== filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // ::1 loopback, fc00::/7 unique-local, fe80::/10 link-local.
            $packed = inet_pton($bare);

            if (false !== $packed && (
                "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\1" === $packed
                || 0xFC === (ord($packed[0]) & 0xFE)
                || (0xFE === ord($packed[0]) && 0x80 === (ord($packed[1]) & 0xC0))
            )) {
                throw new IntegrationException('Server address must not point at a private network.');
            }

            return;
        }

        if (false === filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // A hostname. Resolving it here would only buy a check an attacker
            // can invalidate between now and the request, so we let it through
            // and rely on the allow-list and on admins pinning baseUrl.
            return;
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if (true === $this->inRange($bare, $range)) {
                throw new IntegrationException('Server address must not point at a private network.');
            }
        }
    }

    private function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if (false === $ipLong || false === $subnetLong) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
