<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Service\Integration\Driver\OpenCloudDriver;
use App\Service\Integration\IntegrationUrlValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * What OpenCloud does differently from Nextcloud (#476). The WebDAV half both
 * share is covered by NextcloudDriverTest; the bodies here are the shapes
 * OpenCloud 7.2.4 answered with in a container, trimmed.
 */
final class OpenCloudDriverTest extends TestCase
{
    use AssertsHttpLimits;

    private const string DRIVE = '681b0dfd-c984-46dd-8f92-f6b555514d0b$5f7ddbca-7b85-4422-a250-35bad2a4b0d8';
    private const string PROJECT = '681b0dfd-c984-46dd-8f92-f6b555514d0b$61d1a76f-09f5-4f9f-84f1-7eacf6d49f3b';
    private const string MOUNT = 'a0ca6a90$a0ca6a90!681b0dfd:cbae1193:a7880601';

    /** @var list<array{method:string,url:string,options:array<string,mixed>,body:string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->requests = [];
    }

    public function testItServesOpenCloudOnly(): void
    {
        $driver = $this->driver([]);

        self::assertTrue($driver->supports(Provider::OpenCloud));
        self::assertFalse($driver->supports(Provider::Nextcloud));
    }

    public function testListUsesTheFilesTreeAndNamesOpenCloudInTheBreadcrumb(): void
    {
        $driver = $this->driver([new MockResponse(<<<'XML'
            <d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
              <d:response><d:href>/remote.php/dav/files/alice/</d:href>
                <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>
              <d:response><d:href>/remote.php/dav/files/alice/Docs%20A/</d:href>
                <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>
              <d:response><d:href>/remote.php/dav/files/alice/report.txt</d:href>
                <d:propstat><d:prop><d:resourcetype/><d:getcontentlength>15</d:getcontentlength>
                <d:getcontenttype>text/plain</d:getcontenttype></d:prop></d:propstat></d:response>
            </d:multistatus>
            XML, ['http_code' => 207]), $this->myDrives()]);

        $listing = $driver->list($this->integration());

        // Project spaces first, then the personal files as on Nextcloud.
        self::assertSame(['space:'.self::PROJECT, 'Docs A', 'report.txt'], array_map(static fn ($e) => $e->id, $listing->entries));
        self::assertSame('Team Docs', $listing->entries[0]->name);
        self::assertTrue($listing->entries[0]->isFolder);
        self::assertSame('OpenCloud', $listing->breadcrumb[0]->name);
        self::assertSame('PROPFIND', $this->requests[0]['method']);
        self::assertSame('https://cloud.example.com/remote.php/dav/files/alice/', $this->requests[0]['url']);
        self::assertSame(['alice', 'app-token'], $this->basicAuth(0));
    }

    /**
     * The search service answers under the spaces tree, for every space the
     * user can reach. Each hit becomes the id it would have in a listing:
     * a path for the personal space, Shares/… for a share, space:… for a
     * project space. A space the drive list does not know is dropped.
     */
    public function testSearchMapsHitsFromEverySpace(): void
    {
        $driver = $this->driver([$this->searchHits(), $this->myDrives()]);

        $listing = $driver->search($this->integration(), 'rep');

        self::assertSame([
            'Shares/Bob Shared',
            'space:'.self::PROJECT,
            'Shares/Bob Shared/plan bob.txt',
            'Docs A/report one.txt',
            'report root.txt',
            'space:'.self::PROJECT.'/team report.txt',
        ], array_map(static fn ($e) => $e->id, $listing->entries));
        self::assertSame('Team Docs', $listing->entries[1]->name, 'the top of a space is named after it');
        self::assertSame('text/plain', $listing->entries[3]->mime, 'Docs A/report one.txt keeps its type');

        self::assertSame('REPORT', $this->requests[0]['method']);
        self::assertSame('https://cloud.example.com/remote.php/dav/files/alice/', $this->requests[0]['url']);
        self::assertStringContainsString('<oc:pattern>name:"*rep*"</oc:pattern>', $this->requests[0]['body']);
        self::assertSame('https://cloud.example.com/graph/v1.0/me/drives', $this->requests[1]['url']);

        self::assertEveryRequestIsACall($this->requests);
    }

    /** The server cannot scope a search to a folder, so the driver does. */
    public function testSearchInsideAFolderKeepsOnlyItsHits(): void
    {
        $driver = $this->driver([$this->searchHits(), $this->myDrives()]);

        $listing = $driver->search($this->integration(), 'rep', 'Docs A');

        self::assertSame(['Docs A/report one.txt'], array_map(static fn ($e) => $e->id, $listing->entries));
    }

    /** KQL has no escape inside a quoted phrase; the characters go instead. */
    public function testSearchDropsQuotesAndWildcardsFromTheTerm(): void
    {
        $driver = $this->driver([$this->searchHits(), $this->myDrives()]);

        $driver->search($this->integration(), 'a"b*c');

        self::assertStringContainsString('name:"*a b c*"', $this->requests[0]['body']);
    }

    public function testASearchOfOnlyWildcardsIsAListing(): void
    {
        $driver = $this->driver([new MockResponse('<d:multistatus xmlns:d="DAV:"/>', ['http_code' => 207]), $this->myDrives()]);

        $driver->search($this->integration(), '"*"');

        self::assertSame('PROPFIND', $this->requests[0]['method']);
    }

    public function testThumbnailComesFromTheDavPathWithPreviewParameters(): void
    {
        $driver = $this->driver([new MockResponse('png-bytes', ['response_headers' => ['content-type' => 'image/png']])]);

        $thumbnail = $driver->thumbnail($this->integration(), 'Docs A/red.png');

        self::assertSame('image/png', $thumbnail?->mime);
        self::assertSame('png-bytes', $thumbnail->contents);
        self::assertStringStartsWith('https://cloud.example.com/remote.php/dav/files/alice/Docs%20A/red.png?', $this->requests[0]['url']);
        self::assertStringContainsString('preview=1', $this->requests[0]['url']);
    }

    /** Previews switched off, or a type OpenCloud cannot render. */
    public function testThumbnailIsNullOnA404OrForAnythingNotAnImage(): void
    {
        $driver = $this->driver([
            new MockResponse('<error/>', ['http_code' => 404]),
            new MockResponse('plain text', ['response_headers' => ['content-type' => 'text/plain']]),
        ]);

        self::assertNull($driver->thumbnail($this->integration(), 'a.zip'));
        self::assertNull($driver->thumbnail($this->integration(), 'b.txt'));
    }

    public function testShareLinkAsksForJsonByName(): void
    {
        $driver = $this->driver([new JsonMockResponse([
            'ocs' => ['meta' => ['statuscode' => 200], 'data' => ['url' => 'https://cloud.example.com/s/abc']],
        ])]);

        self::assertSame('https://cloud.example.com/s/abc', $driver->shareLink($this->integration(), 'Docs A/red.png'));
        self::assertStringContainsString('format=json', $this->requests[0]['url']);
        self::assertStringContainsString('path=%2FDocs+A%2Fred.png', $this->requests[0]['body']);
    }

    /** OpenCloud's default policy; the message says what to change. */
    public function testShareLinkExplainsAPasswordPolicy(): void
    {
        $driver = $this->driver([new JsonMockResponse(
            ['ocs' => ['meta' => ['status' => 'error', 'statuscode' => 400, 'message' => 'missing required password']]],
            ['http_code' => 400],
        )]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('OpenCloud requires a password on public links');

        $driver->shareLink($this->integration(), 'Docs A/red.png');
    }

    public function testShareLinkPassesOnAnyOtherRefusal(): void
    {
        $driver = $this->driver([new JsonMockResponse(
            ['ocs' => ['meta' => ['status' => 'failure', 'statuscode' => 404, 'message' => 'Wrong path, file/folder doesn\'t exist']]],
            ['http_code' => 404],
        )]);

        $this->expectExceptionMessage("OpenCloud refused to create a share link: Wrong path, file/folder doesn't exist");

        $driver->shareLink($this->integration(), 'nope.txt');
    }

    public function testARefusedTokenIsCalledAnAppToken(): void
    {
        $driver = $this->driver([new MockResponse('', ['http_code' => 401])]);

        $this->expectExceptionMessage('OpenCloud rejected the username or app token.');

        $driver->verify($this->integration());
    }

    /**
     * OpenCloud sizes an upload from Content-Length before reading it and
     * answers a chunked PUT with a 400; Nextcloud took either.
     */
    public function testUploadStatesItsLength(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'oc');
        file_put_contents($file, 'uploaded by plmail');

        try {
            $driver = $this->driver([new MockResponse('', ['http_code' => 201])]);

            self::assertSame('note.txt', $driver->upload($this->integration(), $file, 'note.txt', 'text/plain'));
        } finally {
            unlink($file);
        }

        self::assertSame('PUT', $this->requests[0]['method']);
        self::assertContains('Content-Length: 18', $this->requests[0]['options']['headers']);
    }

    public function testAProjectSpaceIsListedFromTheSpacesTreeWithItsNameInTheBreadcrumb(): void
    {
        $project = self::PROJECT;
        $driver = $this->driver([new MockResponse(<<<XML
            <d:multistatus xmlns:d="DAV:">
              <d:response><d:href>/remote.php/dav/spaces/{$project}/Sub/</d:href>
                <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>
              <d:response><d:href>/remote.php/dav/spaces/{$project}/Sub/red.png</d:href>
                <d:propstat><d:prop><d:resourcetype/><d:getcontenttype>image/png</d:getcontenttype></d:prop></d:propstat></d:response>
            </d:multistatus>
            XML, ['http_code' => 207]), $this->myDrives()]);

        $listing = $driver->list($this->integration(), 'space:'.self::PROJECT.'/Sub');

        self::assertSame(['space:'.self::PROJECT.'/Sub/red.png'], array_map(static fn ($e) => $e->id, $listing->entries));
        self::assertSame('red.png', $listing->entries[0]->name);
        self::assertSame(['OpenCloud', 'Team Docs', 'Sub'], array_map(static fn ($e) => $e->name, $listing->breadcrumb));
        self::assertSame(['', 'space:'.self::PROJECT, 'space:'.self::PROJECT.'/Sub'], array_map(static fn ($e) => $e->id, $listing->breadcrumb));
        self::assertSame('https://cloud.example.com/remote.php/dav/spaces/'.rawurlencode(self::PROJECT).'/Sub', $this->requests[0]['url']);
    }

    public function testTheRootStillListsWhenTheSpacesCannotBeAskedFor(): void
    {
        $driver = $this->driver([
            new MockResponse('<d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/alice/a.txt</d:href></d:response></d:multistatus>', ['http_code' => 207]),
            new MockResponse('', ['http_code' => 403]),
        ]);

        self::assertSame(['a.txt'], array_map(static fn ($e) => $e->id, $driver->list($this->integration())->entries));
    }

    /** The top of a space exists by itself; only the folders below it are made. */
    public function testUploadingIntoASpaceMakesFoldersBelowItsTopOnly(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'oc');
        file_put_contents($file, 'x');

        try {
            $driver = $this->driver([
                new MockResponse('', ['http_code' => 201]),
                new MockResponse('', ['http_code' => 201]),
            ]);

            $id = $driver->upload($this->integration(), $file, 'a.txt', 'text/plain', 'space:'.self::PROJECT.'/New');
        } finally {
            unlink($file);
        }

        self::assertSame('space:'.self::PROJECT.'/New/a.txt', $id);
        self::assertSame(['MKCOL', 'PUT'], array_column($this->requests, 'method'));
        self::assertStringEndsWith('/remote.php/dav/spaces/'.rawurlencode(self::PROJECT).'/New', $this->requests[0]['url']);
    }

    public function testAShareLinkInASpaceNamesTheSpace(): void
    {
        $driver = $this->driver([new JsonMockResponse([
            'ocs' => ['meta' => ['statuscode' => 200], 'data' => ['url' => 'https://cloud.example.com/s/xyz']],
        ])]);

        $driver->shareLink($this->integration(), 'space:'.self::PROJECT.'/Sub/red.png');

        parse_str($this->requests[0]['body'], $body);
        self::assertSame('/Sub/red.png', $body['path']);
        self::assertSame(self::PROJECT, $body['space_ref']);
    }

    /** A dot segment as a space would name the spaces tree's parent. */
    public function testASpaceIdThatIsADotSegmentIsRefused(): void
    {
        $driver = $this->driver([]);

        $this->expectException(IntegrationException::class);

        try {
            $driver->list($this->integration(), 'space:../etc');
        } finally {
            self::assertSame([], $this->requests);
        }
    }

    private function searchHits(): MockResponse
    {
        $drive = self::DRIVE;
        $project = self::PROJECT;

        return new MockResponse(<<<XML
            <d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
              <d:response><d:href>/remote.php/dav/spaces/{$drive}/Docs%20A/report%20one.txt</d:href>
                <d:propstat><d:prop><d:getcontenttype>text/plain</d:getcontenttype><d:resourcetype></d:resourcetype>
                <d:getcontentlength>15</d:getcontentlength></d:prop></d:propstat></d:response>
              <d:response><d:href>/remote.php/dav/spaces/{$drive}/report%20root.txt</d:href>
                <d:propstat><d:prop><d:resourcetype></d:resourcetype></d:prop></d:propstat></d:response>
              <d:response><d:href>/remote.php/dav/spaces/unknown-space\$abc/report%20of%20the%20team.txt</d:href>
                <d:propstat><d:prop><d:resourcetype></d:resourcetype></d:prop></d:propstat></d:response>
              <d:response><d:href>/remote.php/dav/spaces/{$project}/team%20report.txt</d:href>
                <d:propstat><d:prop><d:resourcetype></d:resourcetype></d:prop></d:propstat></d:response>
              <d:response><d:href>/remote.php/dav/spaces/{$project}</d:href>
                <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>
              <d:response><d:href>/remote.php/dav/spaces/a0ca6a90%21681b0dfd:cbae1193:a7880601/plan%20bob.txt</d:href>
                <d:propstat><d:prop><d:resourcetype></d:resourcetype></d:prop></d:propstat></d:response>
              <d:response><d:href>/remote.php/dav/spaces/a0ca6a90%21681b0dfd:cbae1193:a7880601/</d:href>
                <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>
            </d:multistatus>
            XML, ['http_code' => 207]);
    }

    private function myDrives(): JsonMockResponse
    {
        return new JsonMockResponse(['value' => [
            ['id' => self::DRIVE, 'driveType' => 'personal', 'name' => 'Alice'],
            ['id' => self::PROJECT, 'driveType' => 'project', 'name' => 'Team Docs'],
            ['id' => self::MOUNT, 'driveType' => 'mountpoint', 'name' => 'Bob Shared'],
            ['id' => 'a0ca6a90$a0ca6a90', 'driveType' => 'virtual', 'name' => 'Shares'],
            // Disabled: listed by Graph, not openable.
            ['id' => 'x$gone', 'driveType' => 'project', 'name' => 'Old', 'root' => ['deleted' => ['state' => 'trashed']]],
        ]]);
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
    private function driver(array $responses): OpenCloudDriver
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

        return new OpenCloudDriver($client, new IntegrationUrlValidator(), $configRepository);
    }

    private function integration(): Integration
    {
        $integration = new Integration(new User(), Provider::OpenCloud, 'Home');
        $integration->baseUrl = 'https://cloud.example.com';
        $integration->username = 'alice';
        $integration->secret = 'app-token';

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

            return $buffer;
        }

        if (true === is_iterable($body)) {
            foreach ($body as $chunk) {
                $buffer .= $chunk;
            }
        }

        return $buffer;
    }
}
