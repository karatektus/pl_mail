<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\DTO\Crypto\PublicKeyInfo;
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
 * Asks a recipient's own domain for their key: the OpenPGP Web Key Directory
 * (draft-koch-openpgp-webkey-service), on the browser's behalf.
 *
 * On the server because the page may only talk to its own origin
 * (`connect-src 'self'`), which is also what keeps a person's contacts from
 * learning their IP address from a lookup. That makes this an endpoint that
 * fetches URLs derived from user input — the shape of every SSRF — so the
 * URL is never the user's. It is built here, in one of exactly two shapes,
 * from an address that has passed validation:
 *
 *     https://openpgpkey.<domain>/.well-known/openpgpkey/<domain>/hu/<hash>?l=<local>   (advanced)
 *     https://<domain>/.well-known/openpgpkey/hu/<hash>?l=<local>                       (direct)
 *
 * and the request is held to the rest of the rules here:
 *
 *   - HTTPS only, no redirects at all — a WKD server has no reason to send a
 *     key from anywhere else, and following one is how an attacker's domain
 *     would point the server at its own network;
 *   - the host's addresses resolved first and refused if any is private or
 *     reserved, then pinned, and NoPrivateNetworkHttpClient checking the
 *     address actually connected to (no DNS rebinding in between);
 *   - a short timeout and a size limit, enforced while the body streams in;
 *   - answers cached, found or not, so a busy thread does not ask again;
 *   - a per-person rate limit on the lookups that do go out.
 *
 * Only keys with a user ID for the very address asked about are returned. A
 * domain that serves somebody else's key under an address's hash is serving
 * the wrong key, whatever its reason.
 */
final readonly class WkdLookup
{
    /** Z-Base-32, as WKD hashes local parts with it (RFC 6189 §5.1.6). */
    private const string ZBASE32 = 'ybndrfg8ejkmcpqxot1uwisza345h769';

    private const int MAX_BYTES = PublicKeyReader::MAX_BYTES;

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
        private PublicKeyReader             $reader,
        private CacheInterface              $cache,
        private RateLimiterFactoryInterface $wkdLookupLimiter,
        private LoggerInterface             $logger,
        ?Closure                            $resolve = null,
    ) {
        $this->resolve = $resolve ?? PrivateNetwork::resolve(...);
    }

    /**
     * The two URLs to ask, in order, or null for an address that is not one
     * WKD can be asked about.
     *
     * @return array{advanced: string, direct: string}|null
     */
    public static function urls(string $email): ?array
    {
        $at = strrpos($email, '@');

        if (false === $at) {
            return null;
        }

        $local  = substr($email, 0, $at);
        $domain = self::domain(substr($email, $at + 1));

        if (null === $domain || '' === $local || 64 < strlen($local) || 1 === preg_match('/[\x00-\x20\x7f]/', $local)) {
            return null;
        }

        $hash  = self::zbase32(sha1(strtolower($local), true));
        $query = '?l=' . rawurlencode($local);

        return [
            'advanced' => sprintf('https://openpgpkey.%s/.well-known/openpgpkey/%s/hu/%s%s', $domain, $domain, $hash, $query),
            'direct'   => sprintf('https://%s/.well-known/openpgpkey/hu/%s%s', $domain, $hash, $query),
        ];
    }

    /**
     * The usable keys the address's domain publishes for it, if any.
     *
     * @return list<PublicKeyInfo>
     */
    public function find(User $user, string $email): array
    {
        $email = strtolower(trim($email));
        $urls  = self::urls($email);

        if (null === $urls || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ContactKeyRefused('contact_keys.lookup.invalid_address');
        }

        $cached = $this->cache->get('wkd.' . sha1($email), function (ItemInterface $item) use ($user, $urls): string {
            // Counted only here, on the way out: an answer from the cache costs
            // nobody anything, and a thread of twenty signed messages from one
            // sender should not spend twenty lookups.
            if (false === $this->wkdLookupLimiter->create((string) $user->id)->consume()->isAccepted()) {
                throw new ContactKeyRefused('contact_keys.lookup.rate_limited');
            }

            $body = $this->fetch($urls['advanced']) ?? $this->fetch($urls['direct']);
            $item->expiresAfter(null === $body ? self::NOT_FOUND_TTL : self::FOUND_TTL);

            return null === $body ? '' : base64_encode($body);
        });

        if ('' === $cached) {
            return [];
        }

        return array_values(array_filter(
            $this->reader->read((string) base64_decode($cached, true)),
            static fn (PublicKeyInfo $key): bool => $key->usableFor($email),
        ));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * One GET, under every rule in the class docblock. Null for anything but
     * a 200 with a body of a sensible size.
     */
    private function fetch(string $url): ?string
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
                $this->logger->notice('WKD host {host} resolves to a private address; not asked.', ['host' => $host]);

                return null;
            }
        }

        try {
            $response = new NoPrivateNetworkHttpClient($this->httpClient)->request('GET', $url, [
                'timeout'       => 4,
                'max_duration'  => 8,
                'max_redirects' => 0,
                'resolve'       => [$host => $addresses[0]],
                'headers'       => ['Accept' => 'application/octet-stream, application/pgp-keys'],
                'on_progress'   => static function (int $downloaded, int $size): void {
                    if (self::MAX_BYTES < $downloaded || self::MAX_BYTES < $size) {
                        throw new TransportException('The WKD answer is too large.');
                    }
                },
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $body = $response->getContent();
        } catch (Throwable $exception) {
            $this->logger->info('WKD lookup at {url} failed: {error}', ['url' => $url, 'error' => $exception->getMessage()]);

            return null;
        }

        return '' === $body || self::MAX_BYTES < strlen($body) ? null : $body;
    }

    /**
     * Lower-case, IDNA-encoded, and shaped like a public DNS name with at
     * least two labels — not an IP literal, not `localhost`, not a bare
     * container name.
     */
    private static function domain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        $ascii  = '' === $domain ? false : idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        if (false === $ascii || 253 < strlen($ascii)) {
            return null;
        }

        if (1 !== preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $ascii)) {
            return null;
        }

        return $ascii;
    }

    private static function zbase32(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ZBASE32[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }
}
