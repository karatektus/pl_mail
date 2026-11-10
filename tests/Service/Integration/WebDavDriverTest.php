<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationProviderConfig\IntegrationProviderConfigRepository;
use App\Service\Integration\Driver\WebDavDriver;
use App\Service\Integration\IntegrationUrlValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * What a plain WebDAV server does differently from Nextcloud (#547): the tree
 * starts at the address itself, the username may be absent, and two failures
 * get their own words. The verbs both share are covered by
 * NextcloudDriverTest. The base address is the FileBrowser Quantum shape from
 * the issue, escaped space included.
 */
final class WebDavDriverTest extends TestCase
{
    use AssertsHttpLimits;

    private const string BASE = 'https://files.example.com:8080/dav/cargobay/Show%20files/';

    /** @var list<array{method:string,url:string,options:array<string,mixed>,body:string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->requests = [];
    }

    public function testItServesWebDavOnly(): void
    {
        $driver = $this->driver([]);

        self::assertTrue($driver->supports(Provider::WebDav));
        self::assertFalse($driver->supports(Provider::Nextcloud));
    }

    /**
     * The hrefs carry the address's own path, decoded or not depending on the
     * server; both come back as paths below it, and the collection itself is
     * not one of its own children.
     */
    public function testListStartsAtTheAddressItself(): void
    {
        $driver = $this->driver([$this->rootListing()]);

        $listing = $driver->list($this->integration());

        self::assertSame(['Season 1', 'notes.txt'], array_map(static fn ($e) => $e->id, $listing->entries));
        self::assertTrue($listing->entries[0]->isFolder);
        self::assertSame(15, $listing->entries[1]->size);
        self::assertSame('WebDAV', $listing->breadcrumb[0]->name);

        self::assertSame('PROPFIND', $this->requests[0]['method']);
        self::assertSame(self::BASE, $this->requests[0]['url']);
        self::assertContains('Depth: 1', $this->requests[0]['options']['headers']);
        self::assertSame(['bob', 'secret'], $this->basicAuth(0));

        self::assertEveryRequestIsACall($this->requests);
    }

    public function testAFolderIsAddressedBelowTheBase(): void
    {
        $driver = $this->driver([new MockResponse(<<<'XML'
            <d:multistatus xmlns:d="DAV:">
              <d:response><d:href>/dav/cargobay/Show%20files/Season%201/</d:href>
                <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>
              <d:response><d:href>/dav/cargobay/Show%20files/Season%201/ep%231.mkv</d:href>
                <d:propstat><d:prop><d:resourcetype/></d:prop></d:propstat></d:response>
            </d:multistatus>
            XML, ['http_code' => 207])]);

        $listing = $driver->list($this->integration(), 'Season 1');

        self::assertSame(['Season 1/ep#1.mkv'], array_map(static fn ($e) => $e->id, $listing->entries));
        self::assertSame(self::BASE.'Season%201/', $this->requests[0]['url']);
        self::assertSame(['WebDAV', 'Season 1'], array_map(static fn ($e) => $e->name, $listing->breadcrumb));
    }

    /**
     * FileBrowser Quantum takes an API token as the password and ignores the
     * username, so a blank one goes out as an empty user rather than stopping
     * the request.
     */
    public function testAMissingUsernameSendsTheTokenAlone(): void
    {
        $integration = $this->integration();
        $integration->username = null;

        $driver = $this->driver([$this->rootListing()]);
        $driver->verify($integration);

        self::assertSame(['', 'secret'], $this->basicAuth(0));
    }

    public function testAMissingSecretStillStopsTheRequest(): void
    {
        $integration = $this->integration();
        $integration->secret = null;

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('missing its username or password or token');

        $this->driver([])->verify($integration);
    }

    public function testDownloadAndUploadUseThePathBelowTheBase(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dav');
        file_put_contents($file, 'uploaded by plmail');

        try {
            $driver = $this->driver([
                new MockResponse('hello', ['response_headers' => ['content-type' => 'text/plain; charset=utf-8']]),
                new MockResponse('', ['http_code' => 405]),
                new MockResponse('', ['http_code' => 201]),
            ]);

            $downloaded = $driver->download($this->integration(), 'Season 1/notes.txt');
            $id = $driver->upload($this->integration(), $file, 'Grüße.txt', 'text/plain', 'Season 1');
        } finally {
            unlink($file);
        }

        self::assertSame('notes.txt', $downloaded->filename);
        self::assertSame('text/plain', $downloaded->mime);
        self::assertSame('hello', $downloaded->contents);
        self::assertSame(self::BASE.'Season%201/notes.txt', $this->requests[0]['url']);

        self::assertSame('Season 1/Grüße.txt', $id);
        self::assertSame(['GET', 'MKCOL', 'PUT'], array_column($this->requests, 'method'));
        self::assertSame(self::BASE.'Season%201/', $this->requests[1]['url'], 'a collection is addressed with its slash');
        self::assertSame(self::BASE.'Season%201/Gr%C3%BC%C3%9Fe.txt', $this->requests[2]['url']);
    }

    /** Only RFC 4918 class 1 methods: FileBrowser Quantum refuses LOCK and PROPPATCH. */
    public function testCreatingAFolderIsOneMkcol(): void
    {
        $driver = $this->driver([new MockResponse('', ['http_code' => 201])]);

        self::assertSame('Season 2', $driver->createDestination($this->integration(), '', 'Season 2'));
        self::assertSame(['MKCOL'], array_column($this->requests, 'method'));
        self::assertSame(self::BASE.'Season%202/', $this->requests[0]['url']);
    }

    public function testNoShareLinkAndNoPreviewAndNoRequestForEither(): void
    {
        $driver = $this->driver([]);

        self::assertNull($driver->shareLink($this->integration(), 'notes.txt'));
        self::assertNull($driver->thumbnail($this->integration(), 'photo.jpg'));
        self::assertSame([], $this->requests);
    }

    public function testARefusedTokenReadsAsExpiredOrRevoked(): void
    {
        $driver = $this->driver([new MockResponse('', ['http_code' => 401])]);

        try {
            $driver->verify($this->integration());
            self::fail('a 401 must not verify');
        } catch (IntegrationException $e) {
            self::assertStringContainsString('may have expired or been revoked', $e->getMessage());
            self::assertTrue($e->isAuthFailure());
        }
    }

    public function testA404OnTheAddressPointsAtThePath(): void
    {
        $driver = $this->driver([new MockResponse('', ['http_code' => 404])]);

        $this->expectExceptionMessage('Check the path, for example the source name in /dav/<source>/.');

        $driver->verify($this->integration());
    }

    /**
     * A 404 further down is a missing file, not a wrong address, and keeps
     * the ordinary message.
     */
    public function testA404BelowTheAddressIsAMissingFile(): void
    {
        $driver = $this->driver([new MockResponse('', ['http_code' => 404])]);

        $this->expectExceptionMessage('WebDAV could not find that folder or file.');

        $driver->list($this->integration(), 'Gone');
    }

    /**
     * A server that answers for a different tree — the address names a
     * folder, the hrefs come back under another — would list nothing,
     * forever. Connecting says so instead.
     */
    public function testVerifyRefusesAnAnswerForSomeOtherTree(): void
    {
        $driver = $this->driver([new MockResponse(<<<'XML'
            <d:multistatus xmlns:d="DAV:">
              <d:response><d:href>/elsewhere/</d:href>
                <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>
            </d:multistatus>
            XML, ['http_code' => 207])]);

        $this->expectExceptionMessage('The WebDAV server answered, but not for that address.');

        $driver->verify($this->integration());
    }

    /** A server at the root of its host has an empty path, and that works too. */
    public function testAServerAtTheHostRootLists(): void
    {
        $integration = $this->integration();
        $integration->baseUrl = 'https://nas.example.com';

        $driver = $this->driver([new MockResponse(<<<'XML'
            <d:multistatus xmlns:d="DAV:">
              <d:response><d:href>https://nas.example.com/</d:href>
                <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>
              <d:response><d:href>https://nas.example.com/photos/</d:href>
                <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>
            </d:multistatus>
            XML, ['http_code' => 207])]);

        $listing = $driver->list($integration);

        self::assertSame(['photos'], array_map(static fn ($e) => $e->id, $listing->entries));
        self::assertSame('https://nas.example.com/', $this->requests[0]['url']);
    }

    private function rootListing(): MockResponse
    {
        // The second href is decoded and the third escaped, as different
        // servers send them.
        return new MockResponse(<<<'XML'
            <d:multistatus xmlns:d="DAV:">
              <d:response><d:href>/dav/cargobay/Show%20files/</d:href>
                <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>
              <d:response><d:href>/dav/cargobay/Show files/notes.txt</d:href>
                <d:propstat><d:prop><d:resourcetype/><d:getcontentlength>15</d:getcontentlength>
                <d:getcontenttype>text/plain</d:getcontenttype></d:prop></d:propstat></d:response>
              <d:response><d:href>/dav/cargobay/Show%20files/Season%201/</d:href>
                <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>
            </d:multistatus>
            XML, ['http_code' => 207]);
    }

    /** @return array{string,string} */
    private function basicAuth(int $request): array
    {
        $headers = $this->requests[$request]['options']['normalized_headers']['authorization'][0] ?? '';
        $decoded = base64_decode(substr($headers, strlen('Authorization: Basic ')));

        return explode(':', $decoded, 2) + ['', ''];
    }

    /**
     * @param list<ResponseInterface> $responses
     */
    private function driver(array $responses): WebDavDriver
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): ResponseInterface {
            $this->requests[] = [
                'method'  => $method,
                'url'     => $url,
                'options' => $options,
                'body'    => $this->drain($options['body'] ?? ''),
            ];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        $configRepository = $this->createStub(IntegrationProviderConfigRepository::class);
        $configRepository->method('findOneByProvider')->willReturn(null);

        return new WebDavDriver($client, new IntegrationUrlValidator(), $configRepository);
    }

    private function integration(): Integration
    {
        $integration = new Integration(new User(), Provider::WebDav, 'Cargo bay');
        $integration->baseUrl = self::BASE;
        $integration->username = 'bob';
        $integration->secret = 'secret';

        return $integration;
    }

    private function drain(mixed $body): string
    {
        if (true === is_string($body)) {
            return $body;
        }

        $buffer = '';

        if ($body instanceof \Closure) {
            while ('' !== ($chunk = $body(16372))) {
                $buffer .= $chunk;
            }
        }

        return $buffer;
    }
}
