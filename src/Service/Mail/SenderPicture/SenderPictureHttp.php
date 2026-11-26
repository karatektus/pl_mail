<?php

declare(strict_types=1);

namespace App\Service\Mail\SenderPicture;

use App\Domain\Exception\SenderPictureUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The one way a sender picture lookup reaches the internet (#639).
 *
 * The rules are ImageProxyFetcher's, for its reasons, because the position is
 * the same: a server inside the deployment's network fetching a URL somebody
 * else chose — here, whoever controls a sender's DNS or website.
 *
 *  - https only, on every hop, redirects included.
 *  - Never a private, loopback or link-local address. The client injected
 *    here is `app.http_client.sender_pictures`, a NoPrivateNetworkHttpClient
 *    (config/services.yaml), which resolves the host itself, pins the
 *    connection to what it checked, and checks again on every hop. A BIMI
 *    record pointing at 169.254.169.254 is the cloud-metadata request it is
 *    there to refuse. Injected rather than wrapped here so a test can hand in
 *    a MockHttpClient without the guard resolving real hostnames.
 *  - Redirects followed here, at most three, so each hop is seen.
 *  - A hard size cap, enforced while downloading, not after.
 *  - No Referer, no Cookie, and a User-Agent that says what is asking and
 *    nothing about who it is asking for.
 *
 * Not ImageProxyFetcher itself. That one accepts only images and caches by URL
 * under var/image-proxy for a reader's browser; a lookup also needs a homepage's
 * HTML to find its icon, and stores what it keeps somewhere else entirely.
 *
 * Three answers, the same three SenderPictureLookupInterface has: a body; null
 * when the server said there is nothing (4xx, a type that is not wanted); and
 * SenderPictureUnavailableException when nobody answered properly (network
 * failure, 5xx, 429).
 */
class SenderPictureHttp
{
    private const int MAX_REDIRECTS = 3;

    private const float CONNECT_TIMEOUT = 4.0;

    /** Whole-request budget, as the image proxy's: a slow host must not hold the worker. */
    private const float MAX_DURATION = 10.0;

    private const string USER_AGENT = 'plMail-sender-pictures/1.0';

    public function __construct(
        #[Autowire(service: 'app.http_client.sender_pictures')]
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface     $logger,
    ) {}

    /**
     * @param list<string> $acceptedTypes content-type prefixes this caller wants
     *                                     (`image/`, `text/html`); anything else
     *                                     is refused before its body is read
     *
     * @return array{body: string, contentType: string, url: string}|null
     *
     * @throws SenderPictureUnavailableException
     */
    public function get(string $url, array $acceptedTypes, int $maxBytes): ?array
    {
        $hops = 0;

        while (true) {
            if ('https' !== strtolower((string) parse_url($url, PHP_URL_SCHEME)) || '' === (string) parse_url($url, PHP_URL_HOST)) {
                $this->logger->info('SenderPictureHttp: refused a URL that is not https', ['url' => $url]);

                return null;
            }

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'max_redirects' => 0,
                    'timeout'       => self::CONNECT_TIMEOUT,
                    'max_duration'  => self::MAX_DURATION,
                    'headers'       => [
                        'Accept'     => implode(', ', array_map(static fn (string $type): string => str_ends_with($type, '/') ? $type . '*' : $type, $acceptedTypes)),
                        'User-Agent' => self::USER_AGENT,
                    ],
                    'on_progress'   => static function (int $downloaded, int $size) use ($maxBytes): void {
                        if ($maxBytes < $downloaded || $maxBytes < $size) {
                            throw new TransportException('The answer is larger than a sender picture may be.');
                        }
                    },
                ]);

                $status = $response->getStatusCode();

                if ($status >= 300 && $status < 400) {
                    $location = $response->getHeaders(false)['location'][0] ?? null;
                    $response->cancel();

                    if (null === $location || ++$hops > self::MAX_REDIRECTS) {
                        return null;
                    }

                    $url = self::resolveAgainst($url, $location);

                    continue;
                }

                if (429 === $status || $status >= 500) {
                    $response->cancel();

                    throw new SenderPictureUnavailableException(sprintf('%s answered %d', $url, $status));
                }

                if (200 !== $status) {
                    $response->cancel();

                    return null;
                }

                $contentType = strtolower(trim(explode(';', $response->getHeaders(false)['content-type'][0] ?? '')[0]));

                if (false === self::accepts($contentType, $acceptedTypes)) {
                    $response->cancel();

                    return null;
                }

                $body = $response->getContent(false);
            } catch (SenderPictureUnavailableException $exception) {
                throw $exception;
            } catch (HttpClientException $exception) {
                // An oversize answer comes through here too, as the
                // TransportException on_progress threw. That is the far end
                // answering with something that is not a picture, not the
                // far end being down — but there is no telling them apart by
                // type, and calling it Failed costs one more try tomorrow.
                $this->logger->info('SenderPictureHttp: fetch failed', ['url' => $url, 'error' => $exception->getMessage()]);

                throw new SenderPictureUnavailableException($exception->getMessage(), 0, $exception);
            }

            if (strlen($body) > $maxBytes) {
                return null;
            }

            return ['body' => $body, 'contentType' => $contentType, 'url' => $url];
        }
    }

    /** @param list<string> $acceptedTypes */
    private static function accepts(string $contentType, array $acceptedTypes): bool
    {
        foreach ($acceptedTypes as $accepted) {
            if (true === str_starts_with($contentType, $accepted)) {
                return true;
            }
        }

        return false;
    }

    /** A Location header made absolute against the URL that sent it. */
    public static function resolveAgainst(string $base, string $location): string
    {
        if (1 === preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }

        $parts  = parse_url($base);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host   = (string) ($parts['host'] ?? '');

        if (true === str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        if (true === str_starts_with($location, '/')) {
            return sprintf('%s://%s%s', $scheme, $host, $location);
        }

        $path      = (string) ($parts['path'] ?? '/');
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return sprintf('%s://%s%s%s', $scheme, $host, '' === $directory ? '/' : $directory, $location);
    }
}
