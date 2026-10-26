<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\DTO\Crypto\KeyFetch;
use App\Domain\Exception\ContactKeyRefused;
use App\Entity\User\User;
use App\Infrastructure\Http\PrivateNetwork;
use Closure;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * One request for somebody's public key that leaves the server, under the
 * rules every such request is held to — whether it goes to the recipient's
 * own domain (WkdLookup) or to a public key server (KeyServerLookup).
 *
 * Both fetch a URL derived from an address a person typed, which is the shape
 * of every SSRF. The URL is always built by the caller in a fixed shape, and
 * the request itself is held here to:
 *
 *   - HTTPS only, no redirects at all — neither a WKD server nor a key
 *     server has a reason to send a key from anywhere else, and following
 *     one is how a hostile host would point the server at its own network;
 *   - the host's addresses resolved first and refused if any is private or
 *     reserved, then pinned, and NoPrivateNetworkHttpClient checking the
 *     address actually connected to (no DNS rebinding in between);
 *   - a short timeout and a size limit, enforced while the body streams in;
 *   - answers cached, found or not, so a busy thread does not ask again;
 *   - one per-person rate limit (`wkd_lookup`) on the lookups that do go
 *     out, wherever they go: the budget is for "the server knocking on
 *     somebody's door for this person", and a second budget per kind of door
 *     would only double it.
 *
 * Taken out of WkdLookup when the key servers arrived (phase 6f of #28)
 * rather than copied: two copies of an SSRF guard are two chances for one of
 * them to fall behind. Publishing the person's own key (phase 6g,
 * KeyPublishing) goes through it too, as a POST under the same rules.
 */
final readonly class KeyFetcher
{
    public const int MAX_BYTES = PublicKeyReader::MAX_BYTES;

    private const int FOUND_TTL     = 86400;
    private const int NOT_FOUND_TTL = 3600;

    /** @var Closure(string): list<string> */
    private Closure $resolve;

    /**
     * @param (Closure(string): list<string>)|null $resolve how a host name
     *        becomes addresses; PrivateNetwork::resolve() unless a test says
     *        otherwise, since a test has no business asking real DNS
     */
    public function __construct(
        private HttpClientInterface         $httpClient,
        private CacheInterface              $cache,
        private RateLimiterFactoryInterface $wkdLookupLimiter,
        private LoggerInterface             $logger,
        ?Closure                            $resolve = null,
    ) {
        $this->resolve = $resolve ?? PrivateNetwork::resolve(...);
    }

    /**
     * What $fetch brings back, from the cache when it has been asked before:
     * a found answer for a day, nothing found for an hour. Null for nothing.
     *
     * @param Closure(): ?string $fetch the requests to make, through get()
     * @param bool               $fresh ask again even if an answer is cached
     *
     * @throws ContactKeyRefused when the person has used up their lookups
     */
    public function cached(User $user, string $cacheKey, Closure $fetch, bool $fresh = false): ?string
    {
        if (true === $fresh) {
            $this->cache->delete($cacheKey);
        }

        $cached = $this->cache->get($cacheKey, function (ItemInterface $item) use ($user, $fetch): string {
            // Counted only here, on the way out: an answer from the cache costs
            // nobody anything, and a thread of twenty signed messages from one
            // sender should not spend twenty lookups.
            $this->spend($user);

            $body = $fetch();
            $item->expiresAfter(null === $body ? self::NOT_FOUND_TTL : self::FOUND_TTL);

            return null === $body ? '' : base64_encode($body);
        });

        return '' === $cached ? null : (string) base64_decode($cached, true);
    }

    /** Drops a cached answer, so the next cached() asks again. */
    public function forget(string $cacheKey): void
    {
        $this->cache->delete($cacheKey);
    }

    /**
     * Counts one request leaving the server for this person against the
     * shared budget (`wkd_lookup`). For the requests that are not cached:
     * a person checking their own Web Key Directory, publishing their key.
     *
     * @throws ContactKeyRefused when the person has used up their lookups
     */
    public function spend(User $user): void
    {
        if (false === $this->wkdLookupLimiter->create((string) $user->id)->consume()->isAccepted()) {
            throw new ContactKeyRefused('contact_keys.lookup.rate_limited');
        }
    }

    /**
     * One GET, under every rule in the class docblock. Null for anything but
     * a 200 with a body of a sensible size.
     */
    public function get(string $url, string $accept): ?string
    {
        return $this->probe($url, $accept)->found();
    }

    /**
     * The same GET, saying what came back: whether the host was reached and
     * with which status, for whoever has to tell "not reachable" from "not
     * there" (WkdLookup::check()).
     */
    public function probe(string $url, string $accept): KeyFetch
    {
        return $this->request('GET', $url, ['headers' => ['Accept' => $accept]]);
    }

    /**
     * One POST of a JSON body, under the same rules — for publishing to a key
     * server's API. The body comes back whatever the status: an API says why
     * it refused in the answer to a 4xx.
     *
     * @param array<string, mixed> $json
     */
    public function post(string $url, array $json, string $accept = 'application/json'): KeyFetch
    {
        return $this->request('POST', $url, ['json' => $json, 'headers' => ['Accept' => $accept]]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $options the method's own: headers, body
     */
    private function request(string $method, string $url, array $options): KeyFetch
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ('https' !== parse_url($url, PHP_URL_SCHEME) || '' === $host) {
            return KeyFetch::unreached();
        }

        $addresses = ($this->resolve)($host);

        if ([] === $addresses) {
            return KeyFetch::unreached();
        }

        foreach ($addresses as $address) {
            if (true === IpUtils::isPrivateIp($address)) {
                $this->logger->notice('Key lookup host {host} resolves to a private address; not asked.', ['host' => $host]);

                return KeyFetch::unreached();
            }
        }

        try {
            $response = new NoPrivateNetworkHttpClient($this->httpClient)->request($method, $url, $options + [
                'timeout'       => 4,
                'max_duration'  => 8,
                'max_redirects' => 0,
                'resolve'       => [$host => $addresses[0]],
                'on_progress'   => static function (int $downloaded, int $size): void {
                    if (self::MAX_BYTES < $downloaded || self::MAX_BYTES < $size) {
                        throw new TransportException('The key lookup answer is too large.');
                    }
                },
            ]);

            $status = $response->getStatusCode();
            $body   = $response->getContent(false);
        } catch (Throwable $exception) {
            $this->logger->info('Key lookup at {url} failed: {error}', ['url' => $url, 'error' => $exception->getMessage()]);

            return KeyFetch::unreached();
        }

        if (self::MAX_BYTES < strlen($body)) {
            return KeyFetch::unreached();
        }

        return new KeyFetch(true, $status, '' === $body ? null : $body);
    }
}
