<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration;
use App\Entity\User;
use App\Repository\IntegrationProviderConfigRepository;
use App\Service\Integration\Driver\NextcloudDriver;
use App\Service\Integration\IntegrationUrlValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The WebDAV half of the driver, which is where the risk is: a multistatus
 * body is XML with namespaces, the folder itself comes back as the first
 * entry, and hrefs are percent-encoded and may be absolute or root-relative.
 * All four have to be got right or the picker silently shows the wrong thing.
 */
final class NextcloudDriverTest extends TestCase
{
    /** @var list<array{method:string,url:string,options:array<string,mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->requests = [];
    }

    public function testListParsesMultistatusAndSkipsTheFolderItself(): void
    {
        $driver = $this->driver([$this->multistatus()]);

        $listing = $driver->list($this->integration(), 'Documents');

        // Four <d:response> elements, the first being /Documents itself.
        self::assertCount(3, $listing->entries);

        // Folders first, then files, each run sorted naturally.
        self::assertSame(['Holiday Photos', 'notes.txt', 'report.pdf'], array_map(
            static fn ($entry) => $entry->name,
            $listing->entries,
        ));

        $folder = $listing->entries[0];
        self::assertTrue($folder->isFolder);
        self::assertSame('Documents/Holiday Photos', $folder->id, 'ids are paths relative to the files root, percent-decoded');

        $report = $listing->entries[2];
        self::assertFalse($report->isFolder);
        self::assertSame(24019, $report->size);
        self::assertSame('application/pdf', $report->mime);
        self::assertSame('2026-07-20', $report->modifiedAt?->format('Y-m-d'));
    }

    public function testListBuildsABreadcrumbFromTheFolderPath(): void
    {
        $driver = $this->driver([$this->multistatus()]);

        $listing = $driver->list($this->integration(), 'Documents/Trips');

        self::assertSame(['Nextcloud', 'Documents', 'Trips'], array_map(
            static fn ($crumb) => $crumb->name,
            $listing->breadcrumb,
        ));
        self::assertSame(['', 'Documents', 'Documents/Trips'], array_map(
            static fn ($crumb) => $crumb->id,
            $listing->breadcrumb,
        ));
    }

    public function testPathTraversalIsStrippedBeforeItReachesTheUrl(): void
    {
        $driver = $this->driver([$this->multistatus()]);

        $driver->list($this->integration(), '../../etc/passwd');

        // '..' segments are dropped rather than escaped, so the request stays
        // inside the user's own files root.
        self::assertStringEndsWith('/remote.php/dav/files/alice/etc/passwd', $this->requests[0]['url']);
    }

    public function testDownloadReturnsBytesAndABareMimeType(): void
    {
        $driver = $this->driver([
            new MockResponse('%PDF-1.7 ...', ['response_headers' => ['content-type' => 'application/pdf; charset=binary']]),
        ]);

        $file = $driver->download($this->integration(), 'Documents/report.pdf');

        self::assertSame('report.pdf', $file->filename);
        self::assertSame('application/pdf', $file->mime, 'the charset suffix is stripped');
        self::assertSame('%PDF-1.7 ...', $file->contents);
    }

    public function testShareLinkReadsTheOcsEnvelope(): void
    {
        $driver = $this->driver([
            new JsonMockResponse([
                'ocs' => [
                    'meta' => ['statuscode' => 200],
                    'data' => ['url' => 'https://cloud.example.com/s/AbCdEf'],
                ],
            ]),
        ]);

        $url = $driver->shareLink($this->integration(), 'Documents/report.pdf');

        self::assertSame('https://cloud.example.com/s/AbCdEf', $url);
        self::assertSame('OCS-APIRequest: true', $this->requests[0]['options']['normalized_headers']['ocs-apirequest'][0] ?? null);
    }

    public function testShareLinkFailsLoudlyOnAnOcsErrorDespiteHttp200(): void
    {
        // OCS reports failure in the body with a 200 status, so trusting the
        // status code alone would hand the user a null link and no reason.
        $driver = $this->driver([
            new JsonMockResponse([
                'ocs' => ['meta' => ['statuscode' => 403, 'message' => 'Public upload disabled']],
            ]),
        ]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessageMatches('/Public upload disabled/');

        $driver->shareLink($this->integration(), 'Documents/report.pdf');
    }

    public function testBadCredentialsBecomeAReadableMessage(): void
    {
        $driver = $this->driver([new MockResponse('', ['http_code' => 401])]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Nextcloud rejected the username or app password.');

        $driver->verify($this->integration());
    }

    public function testThumbnailIsNullForFileTypesWithNoPreview(): void
    {
        $driver = $this->driver([new MockResponse('', ['http_code' => 404])]);

        self::assertNull($driver->thumbnail($this->integration(), 'Documents/archive.zip'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * A realistic multistatus body: root-relative percent-encoded hrefs, the
     * requested collection first, and a mix of collections and files.
     */
    private function multistatus(): MockResponse
    {
        $xml = <<<'XML'
            <?xml version="1.0"?>
            <d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
              <d:response>
                <d:href>/remote.php/dav/files/alice/Documents/</d:href>
                <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
              </d:response>
              <d:response>
                <d:href>/remote.php/dav/files/alice/Documents/report.pdf</d:href>
                <d:propstat><d:prop>
                  <d:resourcetype/>
                  <d:getcontentlength>24019</d:getcontentlength>
                  <d:getcontenttype>application/pdf</d:getcontenttype>
                  <d:getlastmodified>Mon, 20 Jul 2026 09:14:00 GMT</d:getlastmodified>
                </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
              </d:response>
              <d:response>
                <d:href>/remote.php/dav/files/alice/Documents/Holiday%20Photos/</d:href>
                <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
              </d:response>
              <d:response>
                <d:href>/remote.php/dav/files/alice/Documents/notes.txt</d:href>
                <d:propstat><d:prop>
                  <d:resourcetype/>
                  <d:getcontentlength>12</d:getcontentlength>
                  <d:getcontenttype>text/plain</d:getcontenttype>
                </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
              </d:response>
            </d:multistatus>
            XML;

        return new MockResponse($xml, ['http_code' => 207]);
    }

    /**
     * @param list<ResponseInterface> $responses
     */
    private function driver(array $responses): NextcloudDriver
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): ResponseInterface {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        $configRepository = $this->createStub(IntegrationProviderConfigRepository::class);
        $configRepository->method('findOneByProvider')->willReturn(null);

        return new NextcloudDriver($client, new IntegrationUrlValidator(), $configRepository);
    }

    private function integration(): Integration
    {
        $integration = new Integration(new User(), Provider::Nextcloud, 'Home');
        $integration->baseUrl = 'https://cloud.example.com';
        $integration->username = 'alice';
        $integration->secret = 'app-password';

        return $integration;
    }
}
