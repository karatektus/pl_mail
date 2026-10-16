<?php

declare(strict_types=1);

namespace App\Service\Crypto;

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
 * them to fall behind.
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
            if (false === $this->wkdLookupLimiter->create((string) $user->id)->consume()->isAccepted()) {
                throw new ContactKeyRefused('contact_keys.lookup.rate_limited');
            }

            $body = $fetch();
            $item->expiresAfter(null === $body ? self::NOT_FOUND_TTL : self::FOUND_TTL);

            return null === $body ? '' : base64_encode($body);
        });

        return '' === $cached ? null : (string) base64_decode($cached, true);
    }

    /**
     * One GET, under every rule in the class docblock. Null for anything but
     * a 200 with a body of a sensible size.
     */
    public function get(string $url, string $accept): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ('https' !== parse_url($url, PHP_URL_SCHEME) || '' === $host) {
            return null;
        }

        $addresses = ($this->resolve)($host);

        if ([] === $addresses) {
            return null;
        }

        foreach ($addresses as $address) {
            if (true === IpUtils::isPrivateIp($address)) {
                $this->logger->notice('Key lookup host {host} resolves to a private address; not asked.', ['host' => $host]);

                return null;
            }
        }

        try {
            $response = new NoPrivateNetworkHttpClient($this->httpClient)->request('GET', $url, [
                'timeout'       => 4,
                'max_duration'  => 8,
                'max_redirects' => 0,
                'resolve'       => [$host => $addresses[0]],
                'headers'       => ['Accept' => $accept],
                'on_progress'   => static function (int $downloaded, int $size): void {
                    if (self::MAX_BYTES < $downloaded || self::MAX_BYTES < $size) {
                        throw new TransportException('The key lookup answer is too large.');
                    }
                },
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $body = $response->getContent();
        } catch (Throwable $exception) {
            $this->logger->info('Key lookup at {url} failed: {error}', ['url' => $url, 'error' => $exception->getMessage()]);

            return null;
        }

        return '' === $body || self::MAX_BYTES < strlen($body) ? null : $body;
    }
}
