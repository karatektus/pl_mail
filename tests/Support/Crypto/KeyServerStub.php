<?php

declare(strict_types=1);

namespace App\Tests\Support\Crypto;

use App\Command\Test\SeedKeyServerCommand;
use App\Service\Crypto\PublicKeyReader;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Everywhere plMail asks for a key outside itself, as the test environment
 * sees it: keys.openpgp.org's and keys.mailvelope.com's lookup APIs, the
 * upload API of keys.openpgp.org, and the Web Key Directory of any domain —
 * all answered from the files app:test:seed-key-server writes, with a resolver
 * that gives a host a public address without asking DNS.
 *
 * Wired in config/services.yaml under `when@test` as what KeyServerLookup,
 * WkdLookup and KeyPublishing fetch through, so neither the browser suite nor
 * PHPUnit ever reaches the internet or asks real DNS, and both exercise the
 * real request rules (KeyFetcher) and the real API readers. Each answers in
 * its own shape — the armored key for VKS, JSON with `publicKeyArmored` for
 * Mailvelope, the binary key for WKD, a 404 for nothing — because reading
 * those shapes is part of what is under test.
 *
 * WKD hosts resolve only once something was seeded for them: a domain nobody
 * set up is "not reachable", as `.test` is with real DNS, and seeding one
 * method of a domain leaves the other unreachable — which is how the check
 * from outside is shown to tell the two methods apart (phase 6g of #28).
 *
 * The upload API keeps what it was told per key and address under
 * `vks/`, and every POST it took in `vks/requests.jsonl`, for a browser spec
 * to read back. An address becomes `published` only when a test says the
 * person clicked the link (app:test:seed-key-server --confirm), which also
 * makes the key findable by address, as on the real server.
 */
final class KeyServerStub extends MockHttpClient
{
    /** A public address, never connected to: MockHttpClient makes no connection. */
    public const string ADDRESS = '93.184.215.14';

    /** The hosts that are always there. */
    private const array SERVERS = ['keys.openpgp.org', 'keys.mailvelope.com'];

    public function __construct(
        private readonly string $directory,
    ) {
        parent::__construct(fn (string $method, string $url, array $options): MockResponse => $this->answer($method, $url, $options));
    }

    /**
     * As KeyFetcher resolves a host.
     *
     * @return list<string>
     */
    public function __invoke(string $host): array
    {
        $host = strtolower($host);

        return true === in_array($host, self::SERVERS, true) || true === is_dir(SeedKeyServerCommand::wkdDirectory($this->directory, $host))
            ? [self::ADDRESS]
            : [];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function answer(string $method, string $url, array $options): MockResponse
    {
        $host  = (string) parse_url($url, PHP_URL_HOST);
        $path  = (string) parse_url($url, PHP_URL_PATH);
        $query = [];

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if ('POST' === $method && 'keys.openpgp.org' === $host) {
            return $this->vks($path, json_decode(is_string($options['body'] ?? null) ? $options['body'] : '', true));
        }

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

        if (1 === preg_match('#/\.well-known/openpgpkey/(?:[^/]+/)?hu/([a-z0-9]+)$#', $path, $match)) {
            $file = SeedKeyServerCommand::wkdDirectory($this->directory, $host) . '/' . $match[1];

            return true === is_file($file)
                ? new MockResponse((string) file_get_contents($file), ['response_headers' => ['Content-Type' => 'application/octet-stream']])
                : new MockResponse('', ['http_code' => 404]);
        }

        return new MockResponse('', ['http_code' => 404]);
    }

    /**
     * keys.openpgp.org's VKS upload API: `upload` takes a key and says each
     * address's state, `request-verify` marks the addresses asked for as
     * pending — the real server mails each a link at that point.
     */
    private function vks(string $path, mixed $body): MockResponse
    {
        $body = is_array($body) ? $body : [];

        if ('/vks/v1/upload' === $path && is_string($body['keytext'] ?? null)) {
            $keys = new PublicKeyReader()->read($body['keytext']);

            if ([] === $keys) {
                return new MockResponse('{"error":"Parsing of key data failed."}', ['http_code' => 400]);
            }

            $key   = $keys[0];
            $state = SeedKeyServerCommand::vksState($this->directory, $key->fingerprint);

            foreach ($key->addresses as $email) {
                $state[$email] = true === $key->revoked ? 'revoked' : ($state[$email] ?? 'unpublished');
            }

            if (true === $key->revoked) {
                foreach (array_keys($state) as $email) {
                    $state[$email] = 'revoked';
                }
            }

            SeedKeyServerCommand::saveVks($this->directory, $key->fingerprint, $state, $key->armored);
            $this->record(['call' => 'upload', 'fingerprint' => $key->fingerprint, 'revoked' => $key->revoked]);

            return $this->vksAnswer($key->fingerprint, $state);
        }

        if ('/vks/v1/request-verify' === $path && is_string($body['token'] ?? null) && is_array($body['addresses'] ?? null)) {
            $fingerprint = strtolower((string) base64_decode($body['token'], true));
            $state       = SeedKeyServerCommand::vksState($this->directory, $fingerprint);

            if ([] === $state) {
                return new MockResponse('{"error":"Upload session not found."}', ['http_code' => 400]);
            }

            foreach ($body['addresses'] as $email) {
                if (is_string($email) && 'unpublished' === ($state[$email] ?? null)) {
                    $state[$email] = 'pending';
                }
            }

            SeedKeyServerCommand::saveVks($this->directory, $fingerprint, $state, null);
            $this->record(['call' => 'request-verify', 'fingerprint' => $fingerprint, 'addresses' => array_values($body['addresses'])]);

            return $this->vksAnswer($fingerprint, $state);
        }

        return new MockResponse('{"error":"Not found."}', ['http_code' => 404]);
    }

    /**
     * @param array<string, string> $state
     */
    private function vksAnswer(string $fingerprint, array $state): MockResponse
    {
        return new MockResponse((string) json_encode([
            'key_fpr' => strtoupper($fingerprint),
            'status'  => (object) $state,
            'token'   => base64_encode(strtolower($fingerprint)),
        ]), ['response_headers' => ['Content-Type' => 'application/json']]);
    }

    /**
     * @param array<string, mixed> $call
     */
    private function record(array $call): void
    {
        $file = SeedKeyServerCommand::vksDirectory($this->directory) . '/requests.jsonl';

        if (false === is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }

        file_put_contents($file, json_encode($call) . "\n", FILE_APPEND);
    }

    private function key(string $host, string $email): ?string
    {
        $path = SeedKeyServerCommand::path($this->directory, $host, $email);

        return true === is_file($path) ? (string) file_get_contents($path) : null;
    }
}
