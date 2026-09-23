<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The HTTP client for every URL a user supplied: integrations, calendars, push.
 *
 * A validated base URL was not enough. The drivers let Symfony follow up to
 * twenty redirects, and none of them was looked at — a Nextcloud "server" that
 * answers 302 to http://database:5432 or 169.254.169.254 turned the connection
 * test into a request from inside the container network. And a hostname is
 * checked once, at save time, while the request resolves it again later.
 *
 * NoPrivateNetworkHttpClient closes both: it resolves the host itself, pins the
 * address it checked, and re-checks every hop including redirects. This wraps it
 * for the one exception an install legitimately needs — a self-hosted service on
 * the LAN — which an administrator grants per host through {@see TrustedHosts}.
 *
 * The exemption follows the FIRST host only. A trusted host's own addresses are
 * allowed for that request, so a LAN Nextcloud may redirect within itself; a
 * public host that redirects to one of those addresses is still refused,
 * because the exemption was never about the address, it was about the name the
 * administrator trusted.
 */
final class UserUrlHttpClient implements HttpClientInterface, ResetInterface
{
    public function __construct(
        private HttpClientInterface $client,
        private readonly TrustedHosts $trustedHosts,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $host = parse_url($url, PHP_URL_HOST);
        $allow = [];

        if (true === is_string($host) && true === $this->trustedHosts->isTrusted($host)) {
            $allow = PrivateNetwork::resolve($host);
        }

        return (new NoPrivateNetworkHttpClient($this->client, null, $allow))->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        // Responses from request() are NoPrivateNetworkHttpClient's own
        // AsyncResponse, which only that class can stream.
        return (new NoPrivateNetworkHttpClient($this->client))->stream($responses, $timeout);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }

    public function reset(): void
    {
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }
}
