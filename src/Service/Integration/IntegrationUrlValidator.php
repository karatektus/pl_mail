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
use App\Repository\Integration\IntegrationProviderConfig\IntegrationProviderConfigRepository;
use App\Repository\Integration\TrustedHost\TrustedHostRepository;

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
 *   http:// is refused unless an administrator allowed it for that host under
 *   Admin → Integrations (TrustedHost::$allowHttp), or wrote it into a pinned
 *   address. Per host rather than the install-wide INTEGRATIONS_ALLOW_HTTP
 *   switch this replaced (#564): one LAN server without a certificate is no
 *   reason to let every user send an app password in the clear to any server
 *   on the internet.
 *
 *   A host that RESOLVES into loopback, link-local, private or reserved space
 *   is refused outright unless an administrator allowed it under Admin →
 *   Integrations, or it is the host of a server address an admin pinned
 *   (isTrusted()). This is the check that stops http://localhost:5432, the
 *   container names on the compose network, and the cloud metadata endpoint
 *   at 169.254.169.254.
 *
 * Both exemptions used to be environment variables, and a Docker Compose
 * install could not set them: compose.yaml never passed them into the
 * containers, so the error's own advice did nothing. They are rows now
 * (TrustedHostRepository), asked on every check, so adding or removing one
 * takes effect on the next request without a restart.
 *
 * That check runs when the address is saved and gives the user a readable
 * error. It is not what stops DNS rebinding or a redirect into the network:
 * the requests themselves go through {@see UserUrlHttpClient}, which resolves
 * and re-checks on every hop and consults isTrusted() below for the same
 * exemptions — the allowed hosts, and any server address an admin pinned.
 */
final readonly class IntegrationUrlValidator implements TrustedHosts
{
    public function __construct(
        // Both optional so the unit tests can build a validator bare; the
        // container always supplies them. Without them no host is exempt and
        // plain http is refused everywhere — the safe reading of "nobody
        // allowed anything".
        private ?TrustedHostRepository               $trustedHosts = null,
        private ?IntegrationProviderConfigRepository $configs = null,
    ) {
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

        // Credentials in the URL would be logged wherever the URL is, and would
        // silently override the ones on the connection.
        if (true === isset($parts['user']) || true === isset($parts['pass'])) {
            throw new IntegrationException('Server address must not contain a username or password.');
        }

        $host = self::hostOf((string) $parts['host']);

        // isTrusted() rather than the allowed hosts alone, so a pinned server
        // address counts here as it does in UserUrlHttpClient. CalDavClient
        // checks every URL through this method before requesting it, and
        // without the pin a Nextcloud an admin pinned at http://nextcloud
        // served files and refused calendars on the same host.
        //
        // The network before the scheme: a LAN server nobody allowed, typed
        // with http://, is refused for being on the LAN, and that message
        // sends the administrator to the page where plain http is ticked too.
        // The other order told them only to use https, which the server could
        // not do and which would not have let it through either.
        if (false === $this->isTrusted($host)) {
            $this->assertHostNotInternal($host);
        }

        if ('http' === $scheme && false === $this->allowsHttp($host)) {
            throw new IntegrationException('Server address must use https. An administrator can allow plain http for this server under Admin → Integrations if it has no certificate.');
        }
    }

    /**
     * Whether a host may be reached on a private network: allowed under
     * Admin → Integrations, or the host of a server address an admin pinned on
     * a provider.
     *
     * The pin has to count. An administrator pinning `http://nextcloud:80` is
     * the documented way to say "our Nextcloud is the container next door", and
     * before the HTTP client checked addresses itself that simply worked —
     * resolve() never validated a pinned value, because an admin wrote it.
     */
    public function isTrusted(string $host): bool
    {
        $host = self::hostOf($host);

        if (null !== $this->trustedHosts?->findOneByHost($host)) {
            return true;
        }

        return null !== $this->pinnedSchemeFor($host);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Whether plain http may be used with this host: an administrator ticked it
     * for the host, or pinned an http:// address on it — writing the scheme
     * into the pin is as deliberate as the tick, and the pinned Nextcloud next
     * door (`http://nextcloud`) has never had a certificate.
     */
    private function allowsHttp(string $host): bool
    {
        if (true === $this->trustedHosts?->findOneByHost($host)?->allowHttp) {
            return true;
        }

        return 'http' === $this->pinnedSchemeFor($host);
    }

    /**
     * The scheme of the first pinned server address on this host, or null when
     * no provider pins one there.
     */
    private function pinnedSchemeFor(string $host): ?string
    {
        foreach ($this->configs?->findAll() ?? [] as $config) {
            $parts = null === $config->baseUrl ? false : parse_url($config->baseUrl);

            if (true === is_array($parts) && true === isset($parts['host']) && self::hostOf($parts['host']) === $host) {
                return strtolower($parts['scheme'] ?? '');
            }
        }

        return null;
    }

    /**
     * A URL's host in the form the allowed hosts are stored in. Through
     * TrustedHostList::normalise() so the two cannot disagree; a host it
     * cannot read is compared as typed and simply matches nothing.
     */
    private static function hostOf(string $host): string
    {
        return TrustedHostList::normalise($host) ?? strtolower(trim($host, '[]'));
    }

    private function assertHostNotInternal(string $host): void
    {
        if (true === PrivateNetwork::isPrivateHost($host)) {
            throw new IntegrationException('Server address must not point at a private network. An administrator can allow this server under Admin → Integrations.');
        }
    }
}

