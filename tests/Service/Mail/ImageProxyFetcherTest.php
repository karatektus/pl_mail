<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\ImageProxyFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The bug this exists to prevent: an image proxy is a service that connects
 * wherever a stranger's email tells it to, from inside the deployment's own
 * network. That is the definition of SSRF, and the only thing standing between
 * the two is the validation this checks.
 *
 * Every case below asserts the SAME thing in the end — that no HTTP request was
 * attempted at all. The mock client throws if anybody touches it, so a refusal
 * that happens after a connection would fail here rather than pass quietly.
 */
final class ImageProxyFetcherTest extends TestCase
{
    private ImageProxyFetcher $fetcher;

    protected function setUp(): void
    {
        $this->fetcher = new ImageProxyFetcher(
            // Any request at all is a failure: these targets must be refused
            // before the client is ever asked to open a socket.
            new MockHttpClient(static function (): never {
                self::fail('The proxy attempted a request to a target it should have refused.');
            }),
            new NullLogger(),
            sys_get_temp_dir() . '/plmail-image-proxy-test',
        );
    }

    #[DataProvider('refusedTargets')]
    public function testItRefusesWithoutConnecting(string $url): void
    {
        self::assertNull($this->fetcher->fetch($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedTargets(): iterable
    {
        // Rule 1 — https only. An http fetch is a cleartext request our server
        // makes on a schedule the sender chooses.
        yield 'plain http' => ['http://example.com/pixel.png'];
        yield 'a scheme that is not http at all' => ['file:///etc/passwd'];
        yield 'gopher, the classic SSRF pivot' => ['gopher://127.0.0.1:6379/_INFO'];

        // Rule 2 — port 443 only. A URL is a fine way to ask a server to
        // port-scan its own network.
        yield 'an https URL on an unusual port' => ['https://example.com:8080/pixel.png'];
        yield 'https pointed at redis' => ['https://example.com:6379/pixel.png'];

        // Rule 3 — the address must be public. Literals never reach a resolver
        // but never skip the range check either.
        yield 'loopback' => ['https://127.0.0.1/pixel.png'];
        yield 'loopback by another spelling' => ['https://127.1/pixel.png'];
        yield 'RFC1918 ten-net' => ['https://10.0.0.5/pixel.png'];
        yield 'RFC1918 one-seven-two' => ['https://172.16.0.5/pixel.png'];
        yield 'RFC1918 one-nine-two' => ['https://192.168.1.1/pixel.png'];
        yield 'link-local' => ['https://169.254.1.1/pixel.png'];

        // The one that matters most in a cloud deployment: the metadata
        // endpoint, which hands out credentials to anything that asks.
        yield 'the cloud metadata endpoint' => ['https://169.254.169.254/latest/meta-data/'];

        // Carrier-grade NAT — neither private nor reserved as far as PHP's own
        // filter is concerned, and routed to the provider's infrastructure.
        yield 'shared address space' => ['https://100.64.0.1/pixel.png'];

        // IPv6, including the spelling that gets past a check which only looked
        // at the address as an IPv6 one.
        yield 'IPv6 loopback' => ['https://[::1]/pixel.png'];
        yield 'IPv6 unique-local' => ['https://[fd00::1]/pixel.png'];
        yield 'IPv4-mapped IPv6 loopback' => ['https://[::ffff:127.0.0.1]/pixel.png'];

        // Names that never point anywhere a mail image lives. The bare label is
        // the interesting one: that is how services are named on a container
        // network — `database`, `mercure`, `app`.
        yield 'localhost by name' => ['https://localhost/pixel.png'];
        yield 'a localhost subdomain' => ['https://app.localhost/pixel.png'];
        yield 'a bare container hostname' => ['https://database/pixel.png'];
        yield 'a .internal name' => ['https://metadata.internal/pixel.png'];
        yield 'an mDNS .local name' => ['https://printer.local/pixel.png'];

        // Not a URL at all.
        yield 'nonsense' => ['not a url'];
        yield 'empty' => [''];
    }

    /**
     * The signature is checked in the controller, but a URL that survives it
     * still has to survive this. Stated as a test because the two are easy to
     * confuse: the MAC proves the parameter was not typed by hand, and proves
     * nothing whatsoever about where it points — anyone with an account can
     * mail themselves a link and be handed a valid signature for it.
     */
    public function testASignedUrlIsStillSubjectToEveryRule(): void
    {
        self::assertNull($this->fetcher->fetch('https://169.254.169.254/latest/meta-data/'));
    }

    /**
     * A public target that passes every rule must come back as its real bytes.
     *
     * This is the regression guard for the defect where the fetch options
     * included `extra.curl => [CURLOPT_REFERER => null]`: Symfony's real curl
     * client reserves that option for its own `headers` handling and throws
     * InvalidArgumentException on every request that tries to set it through
     * `extra.curl`. The exception was swallowed into a null return, so the proxy
     * served its 1×1 placeholder for EVERY image and never fetched one. The two
     * assertions below pin the fix from both ends: the request must reach the
     * client at all (real bytes returned), and it must not carry that forbidden
     * option — the thing a MockHttpClient does not police but the real one does.
     *
     * A literal public IP is used as the host so validate() connects without a
     * DNS lookup, keeping the test free of the network entirely.
     */
    public function testAPublicImageComesBackAsItsRealBytes(): void
    {
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMEAQA/nHJ7AAAAAElFTkSuQmCC',
            true,
        );

        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($png): MockResponse {
            self::assertSame('GET', $method);

            // The exact regression: the real curl client rejects CURLOPT_REFERER
            // set through extra.curl. It must not be there.
            $curlExtra = $options['extra']['curl'] ?? [];
            self::assertArrayNotHasKey(
                \CURLOPT_REFERER,
                $curlExtra,
                'CURLOPT_REFERER via extra.curl makes the real client throw on every request',
            );

            return new MockResponse($png, ['response_headers' => ['content-type' => 'image/png']]);
        });

        $fetcher = new ImageProxyFetcher(
            $client,
            new NullLogger(),
            sys_get_temp_dir() . '/plmail-image-proxy-test-' . bin2hex(random_bytes(4)),
        );

        // Literal public IP: passes the range check, needs no resolver.
        $result = $fetcher->fetch('https://93.184.216.34/pixel.png');

        self::assertNotNull($result, 'a public image must fetch, not fall back to the placeholder');
        self::assertSame('image/png', $result['contentType']);
        self::assertSame($png, (string) file_get_contents($result['path']));
    }

    /**
     * A host that refuses the image says so in the log.
     *
     * This path returned null and wrote nothing at all. The reader saw a
     * transparent placeholder — indistinguishable from a spacer GIF — and an
     * expired signed URL, a host blocking an unfamiliar user agent and a
     * mistyped link were the same silent gap with no evidence anywhere. Which
     * is exactly the position a real bug report about a missing barcode left
     * everyone in.
     */
    public function testAHostRefusingTheImageIsRecordedWithItsStatus(): void
    {
        $logger = new CollectingLogger();

        $fetcher = new ImageProxyFetcher(
            new MockHttpClient(static fn (): MockResponse => new MockResponse('nope', ['http_code' => 403])),
            $logger,
            sys_get_temp_dir() . '/plmail-image-proxy-test-' . bin2hex(random_bytes(4)),
        );

        self::assertNull($fetcher->fetch('https://93.184.216.34/barcode.png'));

        $line = $logger->firstContaining('refused by the host');

        self::assertNotNull($line, 'a refusal has to leave a trace or nobody can answer "why is it blank"');
        self::assertSame(403, $line['context']['status']);
        self::assertSame('https://93.184.216.34/barcode.png', $line['context']['url']);
    }

    /**
     * An error page served with a 200 is the shape that most deserves a line:
     * the fetch "worked", the type check correctly refused it, and without a
     * log the refusal looks identical to never having asked.
     */
    public function testAnErrorPageServedAsHtmlIsRecordedWithItsContentType(): void
    {
        $logger = new CollectingLogger();

        $fetcher = new ImageProxyFetcher(
            new MockHttpClient(static fn (): MockResponse => new MockResponse(
                '<html>Access denied</html>',
                ['response_headers' => ['content-type' => 'text/html; charset=utf-8']],
            )),
            $logger,
            sys_get_temp_dir() . '/plmail-image-proxy-test-' . bin2hex(random_bytes(4)),
        );

        self::assertNull($fetcher->fetch('https://93.184.216.34/barcode.png'));

        $line = $logger->firstContaining('not an image');

        self::assertNotNull($line);
        self::assertSame('text/html', $line['context']['contentType'], 'the parameters are stripped, the type is kept');
    }
}

/**
 * A logger that keeps what it was told, so a test can assert on it.
 *
 * NullLogger is right for the tests above, which are about bytes. These two are
 * about the log line being written at all, and there is nothing to assert
 * against a logger that discards.
 */
final class CollectingLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string,mixed>}> */
    public array $lines = [];

    /**
     * @param array<string,mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->lines[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return array{level: mixed, message: string, context: array<string,mixed>}|null
     */
    public function firstContaining(string $needle): ?array
    {
        foreach ($this->lines as $line) {
            if (true === str_contains($line['message'], $needle)) {
                return $line;
            }
        }

        return null;
    }
}
