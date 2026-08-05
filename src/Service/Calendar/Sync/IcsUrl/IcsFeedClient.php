<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\IcsUrl;

use App\Domain\DTO\Calendar\IcsFeedResponse;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\CalendarSyncThrottledException;
use App\Domain\Exception\IntegrationException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * One GET of a published calendar, made conditional and made safe.
 *
 * The whole network surface of the ICS URL driver, in one class for the reason
 * CalDavClient gives: two of the things it does are security decisions, and a
 * security decision with two implementations has one that is wrong.
 *
 *   **Every URL is validated, including the ones the server chooses.**
 *   Redirects are followed by hand rather than by the HTTP client, three hops
 *   at most, and every hop goes back through IcsUrlNormaliser. Symfony's client
 *   would follow a Location to anywhere — so a perfectly public feed host that
 *   answers `302 Location: http://169.254.169.254/latest/meta-data/` turns the
 *   scheduled poll into a read of the cloud metadata endpoint. Validating the
 *   first URL and letting the client chase the rest is the exact shape of that
 *   hole, which is why max_redirects is 0 here as it is in CalDavClient.
 *
 *   **The body is bounded while it arrives, not after.** A calendar feed is a
 *   file at an address nobody here controls, and `getContent()` on a response
 *   with no Content-Length will happily buffer whatever the far end sends until
 *   the worker dies. The response is streamed and abandoned the moment it
 *   passes MAX_BYTES, so a hostile or broken feed costs one refused sync
 *   instead of an out-of-memory in a Messenger worker that then retries.
 *
 * ── Conditional GET is the point of the whole class ───────────────────────
 *
 * A feed has no delta mechanism, no change token and no per-event ids: reading
 * it means downloading all of it. What HTTP does offer is validators, so the
 * ETag and Last-Modified of the last successful read go back out as
 * If-None-Match and If-Modified-Since, and an unchanged calendar answers 304
 * with no body. That is the answer to almost every poll of a holiday calendar,
 * and it is what makes polling one every fifteen minutes reasonable.
 *
 * Both validators are sent when both are known. RFC 9110 §13.2.2 says a server
 * that gets both evaluates If-None-Match and ignores the date, so this cannot
 * be less accurate than sending either alone — and sending both covers the
 * servers that support only one of them.
 *
 * ── Failure ───────────────────────────────────────────────────────────────
 *
 * Classified the way CalDavClient classifies, and the same rule decides: a
 * permanent classification is a decision never to try again, so only the
 * statuses that genuinely mean "this address will not work" get one. A 5xx from
 * a fixture-list site is a restart far more often than a refusal, and a feed
 * behind a CDN answers 403 for a rate limit as readily as for a revoked
 * address — which is why 403 is NOT permanent here although CalDAV makes it so.
 * There, 403 comes from a server that authenticated us and refused; here there
 * is no credential to have been rejected.
 */
final readonly class IcsFeedClient
{
    /**
     * Named so a feed's access log says who is polling it. Publishers rate-limit
     * on this, and an anonymous client is the one that gets blocked first.
     */
    private const string USER_AGENT = 'plMail-ICS/1.0';

    /**
     * How much of a feed will be read before it is refused.
     *
     * Eight mebibytes is roughly a decade of a busy calendar in text — the
     * German public holiday feed is under 60 KiB, a corporate room calendar
     * with fifteen years of history is a few hundred. The cap is not a guess at
     * what is reasonable; it is the point past which a feed is no longer
     * plausibly a calendar, and it exists so a worker cannot be killed by an
     * address a user typed.
     */
    private const int MAX_BYTES = 8388608;

    /**
     * How many redirects are followed.
     *
     * Three, which covers the shapes that actually occur — http to https, an
     * apex to a www host, a short link — and stops well short of a loop. Every
     * hop is revalidated, so this is a bound on work rather than on risk.
     */
    private const int MAX_REDIRECTS = 3;

    /** Statuses that carry a Location and mean "ask over there instead". */
    private const array REDIRECTS = [301, 302, 303, 307, 308];

    public function __construct(
        private HttpClientInterface $httpClient,
        private IcsUrlNormaliser    $normaliser,
    ) {
    }

    /**
     * Fetch the feed, presenting whatever the last read learned about it.
     *
     * @param string|null $etag         the ETag stored from the last successful
     *                                  read, sent back as If-None-Match
     * @param string|null $lastModified the Last-Modified stored from the last
     *                                  successful read, sent back as
     *                                  If-Modified-Since
     *
     * @throws CalendarSyncException
     */
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): IcsFeedResponse
    {
        $target = $this->reachable($url);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $response = $this->get($target, $etag, $lastModified);

            if (304 === $response['status']) {
                return IcsFeedResponse::unchanged();
            }

            if (true === in_array($response['status'], self::REDIRECTS, true)) {
                // Revalidated before it is fetched, not after — see the class
                // docblock. A Location that points inside the network is
                // refused here with the validator's own sentence.
                $target = $this->reachable($this->locationOf($response, $target));

                continue;
            }

            if ($response['status'] < 200 || $response['status'] > 299) {
                throw $this->classify($response['status'], $response['headers']);
            }

            return new IcsFeedResponse(
                isUnchanged:  false,
                body:         $response['body'],
                etag:         $this->header($response['headers'], 'etag'),
                lastModified: $this->header($response['headers'], 'last-modified'),
            );
        }

        throw new CalendarSyncPermanentException(
            'This calendar address keeps redirecting somewhere else and never arrives at a calendar.',
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @return array{status:int,body:string,headers:array<string,list<string>>}
     *
     * @throws CalendarSyncException
     */
    private function get(string $url, ?string $etag, ?string $lastModified): array
    {
        $sent = [
            'User-Agent' => self::USER_AGENT,
            // Said explicitly although nothing branches on the answer: several
            // publishers serve an HTML landing page to a client that asks for
            // anything, and naming the type is what gets the .ics instead.
            'Accept'     => 'text/calendar, text/plain;q=0.9, */*;q=0.1',
        ];

        if (null !== $etag && '' !== $etag) {
            $sent['If-None-Match'] = $etag;
        }

        if (null !== $lastModified && '' !== $lastModified) {
            $sent['If-Modified-Since'] = $lastModified;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers'       => $sent,
                'max_redirects' => 0,
            ]);

            // `false` throughout, so a 4xx reaches the classifier below rather
            // than being turned into a transport exception by getStatusCode().
            $status  = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body    = $this->boundedBody($status, $response);
        } catch (HttpExceptionInterface $e) {
            // DNS failure, refused connection, TLS mismatch, timeout. Not
            // classified: a publisher that is down comes back, and writing the
            // subscription off permanently would need a person to add a feed
            // that healed itself.
            throw new CalendarSyncException(
                'Could not reach this calendar address. Check that it is correct and that the site is up.',
                0,
                $e,
            );
        }

        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    /**
     * The body, read in chunks and abandoned past the cap.
     *
     * Only for a 2xx: a 304 has no body by definition and a redirect's is a
     * courtesy page, so streaming either would be a second round of I/O for
     * bytes nothing reads.
     *
     * @throws CalendarSyncException
     */
    private function boundedBody(int $status, ResponseInterface $response): string
    {
        if ($status < 200 || $status > 299) {
            return '';
        }

        $body = '';

        foreach ($this->httpClient->stream($response) as $chunk) {
            $body .= $chunk->getContent();

            if (strlen($body) > self::MAX_BYTES) {
                throw new CalendarSyncPermanentException(sprintf(
                    'This calendar is larger than %d MB, which is more than plMail will download.',
                    intdiv(self::MAX_BYTES, 1048576),
                ));
            }
        }

        return $body;
    }

    /**
     * @param array{status:int,body:string,headers:array<string,list<string>>} $response
     *
     * @throws CalendarSyncPermanentException when the redirect names nowhere
     */
    private function locationOf(array $response, string $context): string
    {
        $location = trim($this->header($response['headers'], 'location') ?? '');

        if ('' === $location) {
            throw new CalendarSyncPermanentException(
                'This calendar address redirects somewhere without saying where.',
            );
        }

        if (1 === preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }

        // A relative Location is legal (RFC 9110 §10.2.2) and common on the
        // http-to-https hop. Resolved against the URL that was asked rather
        // than against the original, so a chain of relative hops lands where
        // the server meant.
        $parts  = parse_url($context);
        $scheme = true === is_array($parts) ? (string) ($parts['scheme'] ?? 'https') : 'https';
        $host   = true === is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $port   = true === is_array($parts) && true === isset($parts['port']) ? ':' . $parts['port'] : '';
        $origin = sprintf('%s://%s%s', $scheme, $host, $port);

        if (true === str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = true === is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';

        return $origin . rtrim(substr($path, 0, (int) strrpos($path, '/') + 1), '/') . '/' . $location;
    }

    /**
     * The URL, or the validator's own refusal wearing the calendar hierarchy's
     * clothes.
     *
     * Permanent, because no amount of retrying makes a private address public
     * — the same translation CalDavClient::assertReachable() makes, and the
     * message is carried across verbatim because it is already phrased for a
     * person.
     *
     * @throws CalendarSyncPermanentException
     */
    private function reachable(string $url): string
    {
        try {
            return $this->normaliser->normalise($url);
        } catch (IntegrationException $e) {
            throw new CalendarSyncPermanentException($e->getMessage(), $e->getStatus(), $e);
        }
    }

    /**
     * @param array<string,list<string>> $headers
     */
    private function classify(int $status, array $headers): CalendarSyncException
    {
        return match (true) {
            401 === $status => new CalendarSyncPermanentException(
                'This calendar address needs a sign-in, which plMail cannot do for a feed. Use the secret or public address instead.',
                $status,
            ),
            404 === $status || 410 === $status => new CalendarSyncPermanentException(
                'There is no calendar at this address any more. It may have been republished under a new one.',
                $status,
            ),
            429 === $status || 503 === $status => new CalendarSyncThrottledException(
                'The site publishing this calendar asked us to slow down.',
                $status,
                $this->retryAfter($headers),
            ),
            // 403 is deliberately not permanent, unlike in CalDavClient. There
            // is no credential here to have been rejected, and a CDN in front
            // of a feed answers 403 for a rate limit, for a geo rule and for a
            // bot filter — all of which pass. Writing the subscription off for
            // one would need a person to add it again by hand.
            default => new CalendarSyncException(
                sprintf('The site publishing this calendar returned an unexpected response (%d).', $status),
                $status,
            ),
        };
    }

    /**
     * Seconds, and only when the header says seconds — the same rule and the
     * same reason as CalDavClient::retryAfter(): Retry-After may be an HTTP
     * date, and parsing one against our clock is how a delay becomes negative.
     *
     * @param array<string,list<string>> $headers
     */
    private function retryAfter(array $headers): ?int
    {
        $value = trim($this->header($headers, 'retry-after') ?? '');

        if (1 !== preg_match('/^\d+$/', $value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string,list<string>> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        $value = $headers[$name][0] ?? null;

        return true === is_string($value) && '' !== $value ? $value : null;
    }
}
