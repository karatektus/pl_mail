<?php

declare(strict_types=1);

namespace App\Tests\Support\Crypto;

use App\Command\Test\SeedKeyServerCommand;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The public key servers, as the test environment sees them: an HTTP client
 * that answers keys.openpgp.org's and keys.mailvelope.com's APIs from the
 * files app:test:seed-key-server writes, and a resolver that gives their host
 * names a public address without asking DNS.
 *
 * Wired in config/services.yaml under `when@test` as what KeyServerLookup
 * fetches through, so neither the browser suite nor PHPUnit ever reaches the
 * internet, and both exercise the real request rules (KeyFetcher) and the
 * real API readers. Each server answers in its own shape — the armored key
 * for VKS, JSON with `publicKeyArmored` for Mailvelope, a 404 for nothing —
 * because reading those shapes is part of what is under test.
 */
final class KeyServerStub extends MockHttpClient
{
    /** A public address, never connected to: MockHttpClient makes no connection. */
    public const string ADDRESS = '93.184.215.14';

    public function __construct(
        private readonly string $directory,
    ) {
        parent::__construct(fn (string $method, string $url): MockResponse => $this->answer($url));
    }

    /**
     * As KeyFetcher resolves a host.
     *
     * @return list<string>
     */
    public function __invoke(string $host): array
    {
        return [self::ADDRESS];
    }

    private function answer(string $url): MockResponse
    {
        $host  = (string) parse_url($url, PHP_URL_HOST);
        $path  = (string) parse_url($url, PHP_URL_PATH);
        $query = [];

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if ('keys.openpgp.org' === $host && str_starts_with($path, '/vks/v1/by-email/')) {
            $key = $this->key($host, rawurldecode(substr($path, strlen('/vks/v1/by-email/'))));

            return null === $key
                ? new MockResponse('Not found', ['http_code' => 404])
                : new MockResponse($key, ['response_headers' => ['Content-Type' => 'application/pgp-keys']]);
        }

        if ('keys.mailvelope.com' === $host && '/api/v1/key' === $path && is_string($query['email'] ?? null)) {
            $key = $this->key($host, $query['email']);

            return null === $key
                ? new MockResponse('{"message":"Key not found"}', ['http_code' => 404])
                : new MockResponse((string) json_encode(['keyId' => 'stub', 'publicKeyArmored' => $key]), ['response_headers' => ['Content-Type' => 'application/json']]);
        }

        return new MockResponse('', ['http_code' => 404]);
    }

    private function key(string $host, string $email): ?string
    {
        $path = SeedKeyServerCommand::path($this->directory, $host, $email);

        return true === is_file($path) ? (string) file_get_contents($path) : null;
    }
}
