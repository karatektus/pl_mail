<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Infrastructure\Http\PrivateNetwork;
use App\Infrastructure\Http\TrustedHosts;
use App\Infrastructure\Http\UserUrlHttpClient;
use App\Repository\Integration\IntegrationProviderConfigRepository;
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
 *   A host that RESOLVES into loopback, link-local, private or reserved space
 *   is refused outright unless it appears in INTEGRATIONS_ALLOWED_HOSTS. This
 *   is the check that stops http://localhost:5432, the container names on the
 *   compose network, and the cloud metadata endpoint at 169.254.169.254.
 *
 * That check runs when the address is saved and gives the user a readable
 * error. It is not what stops DNS rebinding or a redirect into the network:
 * the requests themselves go through {@see UserUrlHttpClient}, which resolves
 * and re-checks on every hop and consults isTrusted() below for the same
 * exemptions — the allow-list, and any server address an admin pinned.
 */
final readonly class IntegrationUrlValidator implements TrustedHosts
{
    /** @var list<string> */
    private array $allowedHosts;

    public function __construct(
        #[Autowire(env: 'bool:INTEGRATIONS_ALLOW_HTTP')]
        private bool $allowHttp = false,
        #[Autowire(env: 'INTEGRATIONS_ALLOWED_HOSTS')]
        string $allowedHosts = '',
        // Optional so the unit tests can build a validator bare; the container
        // always supplies it. Without it only the allow-list exempts a host.
        private ?IntegrationProviderConfigRepository $configs = null,
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

    /**
     * Whether a host may be reached on a private network: allow-listed, or the
     * host of a server address an admin pinned on a provider.
     *
     * The pin has to count. An administrator pinning `http://nextcloud:80` is
     * the documented way to say "our Nextcloud is the container next door", and
     * before the HTTP client checked addresses itself that simply worked —
     * resolve() never validated a pinned value, because an admin wrote it.
     */
    public function isTrusted(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if (true === in_array($host, $this->allowedHosts, true)) {
            return true;
        }

        foreach ($this->configs?->findAll() ?? [] as $config) {
            $pinned = null === $config->baseUrl ? null : parse_url($config->baseUrl, PHP_URL_HOST);

            if (true === is_string($pinned) && strtolower(trim($pinned, '[]')) === $host) {
                return true;
            }
        }

        return false;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function assertHostNotInternal(string $host): void
    {
        if (true === PrivateNetwork::isPrivateHost($host)) {
            throw new IntegrationException('Server address must not point at a private network.');
        }
    }
}

