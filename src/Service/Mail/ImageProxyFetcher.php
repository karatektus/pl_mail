<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\InlineDisposition;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a remote image on the reader's behalf, so that the reader's browser
 * never does.
 *
 * This is the half of the remote-image defence that has to be paranoid. The
 * blocker decides WHETHER a load happens; this decides what our own server is
 * willing to connect to when one does — and "our own server" is inside the
 * deployment's network, which is exactly the position an SSRF wants.
 *
 * THE RULES, in the order they are applied:
 *
 *  1. https only. Not a hardening flourish: an http fetch is a cleartext
 *     request our server makes on a schedule an attacker chooses, and every
 *     image host worth loading from has had TLS for a decade.
 *  2. Port 443 only. A URL is a fine way to ask a server to port-scan its own
 *     network; pinning the port to the one images live on removes the whole
 *     class rather than blacklisting the interesting ports.
 *  3. The host is resolved HERE, every address it answers with is checked
 *     against the private/reserved ranges, and the connection is then PINNED to
 *     a checked address via the client's `resolve` option. Checking and then
 *     letting the client resolve again is the DNS-rebinding hole: the name is
 *     allowed to answer differently the second time, and the second time is the
 *     one that connects.
 *  4. Redirects are followed by hand, at most three, with every hop going
 *     through rules 1–3 again. `max_redirects` in the client would follow them
 *     inside the connection, past every check above — a public host redirecting
 *     to 169.254.169.254 is the standard cloud-metadata exfiltration, and it is
 *     invisible to a check that only looked at the first URL.
 *  5. The response must be an image, and must stop at MAX_BYTES. A proxy with
 *     no size limit is a memory-exhaustion primitive that anyone who can send
 *     mail may fire.
 *
 * Referer and Cookie are never sent — there is nothing to send, since the
 * request is built here from scratch, which is the point of a proxy over an
 * `img referrerpolicy`. The User-Agent is a fixed, contentless string: the
 * reader's real one is one of the things being protected.
 */
final readonly class ImageProxyFetcher
{
    private const int MAX_BYTES = 8 * 1024 * 1024;

    private const int MAX_REDIRECTS = 3;

    /** Whole-request budget. A slow tracker must not hold a PHP worker open. */
    private const float MAX_DURATION = 10.0;

    private const float CONNECT_TIMEOUT = 4.0;

    private const string USER_AGENT = 'plMail-image-proxy/1.0';

    /** Cached bytes are immutable for this long before we ask again. */
    private const int CACHE_TTL = 604800;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface     $logger,
        private string              $projectDir,
    ) {
    }

    /**
     * The fetched image, or null when anything at all went wrong — a refusal
     * and a network failure are the same answer to the caller, which serves a
     * placeholder either way. Reasons go to the log, never to the response:
     * "connection refused" versus "timed out" against an internal address is
     * itself the port-scan result rule 2 exists to deny.
     *
     * @return array{path: string, contentType: string}|null
     */
    public function fetch(string $url): ?array
    {
        $cacheKey  = hash('sha256', $url);
        $cacheDir  = $this->projectDir . '/var/image-proxy';
        $bytesPath = sprintf('%s/%s.bin', $cacheDir, $cacheKey);
        $typePath  = sprintf('%s/%s.type', $cacheDir, $cacheKey);

        if (true === is_file($bytesPath) && true === is_file($typePath)
            && (time() - (int) filemtime($bytesPath)) < self::CACHE_TTL
        ) {
            return [
                'path'        => $bytesPath,
                'contentType' => (string) file_get_contents($typePath),
            ];
        }

        try {
            $fetched = $this->request($url);
        } catch (HttpClientException $exception) {
            // ROUTINE. The far end did not answer, or answered badly: DNS that
            // does not resolve, a TLS handshake that fails, a host that times
            // out. Nothing here is wrong with plMail, it happens constantly on
            // any mailbox with newsletters in it, and info is the right volume.
            $this->logger->info('ImageProxyFetcher: fetch failed', [
                'url'       => $url,
                'exception' => $exception,
            ]);

            return null;
        } catch (\Throwable $exception) {
            // NOT ROUTINE, and this branch exists because the single
            // catch(Throwable) it replaces hid a real one. A typo'd method name
            // in this class threw an Error, was caught as though the network
            // had hiccuped, and was written at info — one level below what is
            // stored by default. An outright fatal reported itself as a fetch
            // problem and then did not appear at all.
            //
            // Anything reaching here is a bug in this code, so it is recorded
            // at error, where it is stored without anyone lowering a threshold
            // first, and with the exception ITSELF rather than its message so
            // the handler keeps the class, the line and the trace.
            //
            // Still swallowed rather than rethrown, deliberately. This is one
            // <img> among dozens on a page; turning a bug in it into a 500 per
            // image would break the reading pane over something now perfectly
            // visible in the log.
            $this->logger->error('ImageProxyFetcher: unexpected failure while fetching', [
                'url'       => $url,
                'exception' => $exception,
            ]);

            return null;
        }

        if (null === $fetched) {
            return null;
        }

        if (false === is_dir($cacheDir) && false === @mkdir($cacheDir, 0775, true) && false === is_dir($cacheDir)) {
            $this->logger->warning('ImageProxyFetcher: cache directory unavailable', ['dir' => $cacheDir]);

            return null;
        }

        // Write-then-rename, so a concurrent reader never sees a half-written
        // image — two readers opening the same newsletter is the normal case.
        $temporary = $bytesPath . '.' . bin2hex(random_bytes(4));

        file_put_contents($temporary, $fetched['body']);
        rename($temporary, $bytesPath);
        file_put_contents($typePath, $fetched['contentType']);

        return ['path' => $bytesPath, 'contentType' => $fetched['contentType']];
    }

    /**
     * @return array{body: string, contentType: string}|null
     */
    private function request(string $url): ?array
    {
        $hops = 0;

        while (true) {
            $target = $this->validate($url);

            if (null === $target) {
                return null;
            }

            $response = $this->httpClient->request('GET', $url, [
                // The pin. Rule 3: the address this connects to is the address
                // that was checked, not whatever DNS says a moment later.
                'resolve'         => [$target['host'] => $target['ip']],
                'max_redirects'   => 0,
                'timeout'         => self::CONNECT_TIMEOUT,
                'max_duration'    => self::MAX_DURATION,
                'headers'         => [
                    'Accept'     => 'image/*',
                    'User-Agent' => self::USER_AGENT,
                ],
                // No Referer: the client sends none unless one is set, and none
                // is. It must NOT be forced off through `extra.curl` — Symfony's
                // HttpClient reserves CURLOPT_REFERER for its own `headers`
                // handling and throws on every request that tries, which turned
                // this whole proxy into a placeholder generator. Leaving it out
                // is what keeps the request refererless.
            ]);

            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400) {
                $location = $response->getHeaders(false)['location'][0] ?? null;
                $response->cancel();

                if (null === $location || ++$hops > self::MAX_REDIRECTS) {
                    return null;
                }

                // Relative Location headers are legal and common.
                $url = self::resolveAgainst($url, $location);

                continue;
            }

            if (200 !== $status) {
                $response->cancel();

                // Logged, because the alternative is what this used to be: a
                // remote image becomes a transparent placeholder and NOTHING
                // anywhere says why. An expired signed URL, a host refusing an
                // unknown user agent and a mistyped link are all the same
                // silent grey box to the person reading the mail, and were the
                // same absence of evidence to whoever they asked about it.
                $this->logger->info('ImageProxyFetcher: refused by the host', [
                    'url'    => $url,
                    'status' => $status,
                ]);

                return null;
            }

            $contentType = strtolower(trim(explode(';', $response->getHeaders(false)['content-type'][0] ?? '')[0]));

            // A declared type that is neither an image nor "I don't know" is
            // refused here, before a byte of body is read — that is the error
            // page served with a cheerful 200, and there is no reason to
            // download it.
            if (
                false === str_starts_with($contentType, 'image/')
                && false === self::isUnlabelled($contentType)
            ) {
                $response->cancel();

                $this->logger->info('ImageProxyFetcher: response was not an image', [
                    'url'         => $url,
                    'contentType' => '' === $contentType ? '(none)' : $contentType,
                ]);

                return null;
            }

            $body  = '';
            $bytes = 0;

            foreach ($this->httpClient->stream($response) as $chunk) {
                $body  .= $chunk->getContent();
                $bytes += strlen($chunk->getContent());

                if ($bytes > self::MAX_BYTES) {
                    $response->cancel();

                    $this->logger->info('ImageProxyFetcher: image over size limit', ['url' => $url]);

                    return null;
                }
            }

            // Unlabelled: decide from the bytes, and serve what the bytes
            // actually are rather than what the sender claimed.
            if (true === self::isUnlabelled($contentType)) {
                $sniffed = self::sniff($body);

                if (null === $sniffed) {
                    $this->logger->info('ImageProxyFetcher: unlabelled response is not an image', [
                        'url'         => $url,
                        'contentType' => '' === $contentType ? '(none)' : $contentType,
                    ]);

                    return null;
                }

                $contentType = $sniffed;
            }

            return ['body' => $body, 'contentType' => $contentType];
        }
    }

    /**
     * Did the sender decline to say what this is?
     *
     * S3 hands back `application/octet-stream` for any object uploaded without
     * a content type, and a great deal of the web's images live in exactly
     * that state. Amazon's return-label QR codes are one: a real 8KB PNG,
     * served unlabelled, refused by this proxy for years while the reader saw
     * a blank space where their barcode should be.
     *
     * Treated as "unknown", not as "not an image" — the difference between the
     * two is the whole bug.
     */
    private static function isUnlabelled(string $contentType): bool
    {
        return '' === $contentType
            || 'application/octet-stream' === $contentType
            || 'binary/octet-stream' === $contentType;
    }

    /**
     * What the bytes actually are, or null if they are not an image we serve.
     *
     * THIS IS NOT TRUSTING THE SENDER — it is the opposite. The declared type
     * is discarded and the content is identified from its own magic bytes,
     * then checked against InlineDisposition's list, which is the same
     * deliberate allow-list attachments are held to and which excludes SVG.
     * SVG matters here: it is the one image format that can carry script, and
     * a proxy that served one would be serving script from our own origin.
     *
     * So an unlabelled response can only ever be served as one of a fixed set
     * of raster types, whatever the sender said or did not say.
     */
    private static function sniff(string $body): ?string
    {
        if ('' === $body) {
            return null;
        }

        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);

        if (false === $sniffed) {
            return null;
        }

        $sniffed = strtolower(trim(explode(';', $sniffed)[0]));

        return true === InlineDisposition::allows($sniffed) ? $sniffed : null;
    }

    /**
     * Rules 1–3. Returns the host and the ONE address the connection may use.
     *
     * @return array{host: string, ip: string}|null
     */
    private function validate(string $url): ?array
    {
        $parts = parse_url($url);

        if (false === is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = (string) ($parts['host'] ?? '');
        $port   = (int) ($parts['port'] ?? 443);

        if ('https' !== $scheme || '' === $host || 443 !== $port) {
            return null;
        }

        // A literal address skips DNS but not the range check.
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return true === self::isPublicAddress($host) ? ['host' => $host, 'ip' => $host] : null;
        }

        if (false === self::isResolvableName($host)) {
            return null;
        }

        $addresses = self::resolve($host);

        if (0 === count($addresses)) {
            return null;
        }

        // EVERY answer must be public, not merely the one we would pick. A name
        // that answers with one public and one internal address is a rebinding
        // attempt spelled out in the zone file, and picking the good one this
        // time proves nothing about next time.
        foreach ($addresses as $address) {
            if (false === self::isPublicAddress($address)) {
                $this->logger->info('ImageProxyFetcher: host resolves to a non-public address', [
                    'host'    => $host,
                    'address' => $address,
                ]);

                return null;
            }
        }

        return ['host' => $host, 'ip' => $addresses[0]];
    }

    /**
     * Rejects the names that never point anywhere a mail image lives, before
     * any resolver is asked. `localhost` is the obvious one; a bare label with
     * no dot is the interesting one, because it is how an internal service is
     * named on a container network — `database`, `mercure`, `app`.
     */
    private static function isResolvableName(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));

        if ('localhost' === $host || true === str_ends_with($host, '.localhost')) {
            return false;
        }

        if (true === str_ends_with($host, '.internal') || true === str_ends_with($host, '.local')) {
            return false;
        }

        return true === str_contains($host, '.');
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $addresses = [];

        $v4 = @gethostbynamel($host);

        if (false !== $v4) {
            $addresses = $v4;
        }

        $v6 = @dns_get_record($host, DNS_AAAA);

        if (false !== $v6) {
            foreach ($v6 as $record) {
                if (true === isset($record['ipv6'])) {
                    $addresses[] = (string) $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Public unicast only.
     *
     * FILTER_FLAG_NO_PRIV_RANGE and NO_RES_RANGE between them cover RFC1918,
     * loopback, link-local (including 169.254.169.254, the cloud metadata
     * endpoint), the IPv6 unique-local and link-local blocks, and the reserved
     * space. The explicit checks after them are the ones PHP's filter does not
     * make: carrier-grade NAT, and IPv4-mapped IPv6, which is how `::ffff:127.0.0.1`
     * gets past a check that only looked at it as an IPv6 address.
     */
    private static function isPublicAddress(string $address): bool
    {
        if (false !== strpos($address, ':')) {
            $mapped = self::unmapIpv4($address);

            if (null !== $mapped) {
                return self::isPublicAddress($mapped);
            }
        }

        if (false === filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        )) {
            return false;
        }

        // 100.64.0.0/10 — shared address space (RFC 6598). Neither private nor
        // reserved as far as PHP's filter is concerned, and routed to the
        // provider's own infrastructure.
        if (false !== filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($address);

            if (false !== $long && ($long & 0xFFC00000) === (int) ip2long('100.64.0.0')) {
                return false;
            }
        }

        return true;
    }

    private static function unmapIpv4(string $address): ?string
    {
        $packed = @inet_pton($address);

        if (false === $packed || 16 !== strlen($packed)) {
            return null;
        }

        // ::ffff:0:0/96
        if (str_repeat("\0", 10) . "\xFF\xFF" !== substr($packed, 0, 12)) {
            return null;
        }

        return inet_ntop(substr($packed, 12, 4)) ?: null;
    }

    private static function resolveAgainst(string $base, string $location): string
    {
        if (null !== parse_url($location, PHP_URL_SCHEME)) {
            return $location;
        }

        $parts  = parse_url($base);
        $origin = sprintf('%s://%s', $parts['scheme'] ?? 'https', $parts['host'] ?? '');

        if (true === str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $location;
        }

        if (true === str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = (string) ($parts['path'] ?? '/');

        return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
    }
}
