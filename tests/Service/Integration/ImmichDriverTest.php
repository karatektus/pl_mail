<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\Enum\Integration\Capability;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration;
use App\Entity\User;
use App\Repository\IntegrationProviderConfigRepository;
use App\Service\Integration\Driver\ImmichDriver;
use App\Service\Integration\IntegrationUrlValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Immich's navigation and the metadata gaps the picker has to survive.
 *
 * The landing view is the whole library, with albums as a sideways jump — that
 * ordering is the point, because opening on albums made the picker look empty
 * for anyone who does not curate. And an asset Immich has not processed reports
 * no size at all, which must read as "unknown" and stay attachable rather than
 * being treated as oversize, which made every photo unselectable.
 */
final class ImmichDriverTest extends TestCase
{
    /** @var list<array{method:string,url:string,options:array<string,mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->requests = [];
    }

    public function testTheLandingViewIsTheWholeLibraryNotTheAlbumList(): void
    {
        // Albums used to be the way in, and that made the picker look empty for
        // anyone who does not file their photos into albums.
        $driver = $this->driver([
            new JsonMockResponse(['assets' => ['items' => [
                ['id' => 'm1', 'originalFileName' => 'beach.jpg', 'originalMimeType' => 'image/jpeg'],
            ]]]),
        ]);

        $listing = $driver->list($this->integration());

        self::assertSame(['beach.jpg'], array_map(static fn ($e) => $e->name, $listing->entries));
        self::assertStringEndsWith('/api/search/metadata', $this->requests[0]['url']);
        self::assertSame(['All photos'], array_map(static fn ($c) => $c->name, $listing->breadcrumb));

        // Albums stay reachable, but as a sideways jump rather than the entrance.
        self::assertSame(['Albums'], array_map(static fn ($s) => $s->name, $listing->shortcuts));
        self::assertSame('albums', $listing->shortcuts[0]->id);
    }

    public function testTheAlbumsShortcutListsAlbumsAsFolders(): void
    {
        $driver = $this->driver([
            new JsonMockResponse([
                ['id' => 'b-2', 'albumName' => 'Trips', 'updatedAt' => '2026-05-01T10:00:00Z'],
                ['id' => 'a-1', 'albumName' => 'Archive'],
            ]),
        ]);

        $listing = $driver->list($this->integration(), 'albums');

        self::assertSame(['Archive', 'Trips'], array_map(static fn ($e) => $e->name, $listing->entries));
        self::assertTrue($listing->entries[0]->isFolder);
        self::assertStringEndsWith('/api/albums', $this->requests[0]['url']);
    }

    public function testAlbumContentsComeFromSearchSoTheyPage(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['assets' => [
                'items' => [
                    [
                        'id'               => 'asset-1',
                        'originalFileName' => 'beach.jpg',
                        'originalMimeType' => 'image/jpeg',
                        'fileCreatedAt'    => '2026-06-11T12:00:00Z',
                        'exifInfo'         => ['fileSizeInByte' => 4200000],
                    ],
                    // No exifInfo yet: Immich has not processed this one.
                    ['id' => 'asset-2', 'originalFileName' => 'clip.mp4', 'originalMimeType' => 'video/mp4'],
                ],
                'nextPage' => '2',
            ]]),
            new JsonMockResponse(['albumName' => 'Trips']),
        ]);

        $listing = $driver->list($this->integration(), 'b-2');

        self::assertCount(2, $listing->entries);
        self::assertSame(4200000, $listing->entries[0]->size);
        // Unknown size means "not known yet". The picker treats that as
        // attachable — calling it oversize made every photo unselectable.
        self::assertNull($listing->entries[1]->size);

        self::assertSame('2', $listing->nextCursor, 'Immich reports the next page itself');
        self::assertSame(['b-2'], $this->jsonBodyOf(0)['albumIds'] ?? null);
        self::assertSame(['Immich', 'Albums', 'Trips'], array_map(static fn ($c) => $c->name, $listing->breadcrumb));
    }

    public function testSearchUsesSmartSearchFirst(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['assets' => ['items' => [
                ['id' => 'm1', 'originalFileName' => 'sunset.jpg'],
            ]]]),
        ]);

        $listing = $driver->search($this->integration(), 'beach at sunset');

        self::assertSame(['sunset.jpg'], array_map(static fn ($e) => $e->name, $listing->entries));
        self::assertStringEndsWith('/api/search/smart', $this->requests[0]['url']);
        self::assertSame('beach at sunset', $this->jsonBodyOf(0)['query'] ?? null);
    }

    public function testSearchFallsBackToFilenameWhenSmartSearchIsUnavailable(): void
    {
        // Immich's ML service is optional, so smart search can be missing on an
        // otherwise healthy server. A dead search box is worse than a dumb one.
        $driver = $this->driver([
            new MockResponse('', ['http_code' => 500]),
            new JsonMockResponse(['assets' => ['items' => [
                ['id' => 'm1', 'originalFileName' => 'sunset.jpg'],
            ]]]),
        ]);

        $listing = $driver->search($this->integration(), 'sunset');

        self::assertCount(1, $listing->entries);
        self::assertStringEndsWith('/api/search/smart', $this->requests[0]['url']);
        self::assertStringEndsWith('/api/search/metadata', $this->requests[1]['url']);
        self::assertSame('sunset', $this->jsonBodyOf(1)['originalFileName'] ?? null);
    }

    public function testAnEmptyQueryFallsBackToTheLibrary(): void
    {
        $driver = $this->driver([new JsonMockResponse(['assets' => ['items' => []]])]);

        $driver->search($this->integration(), '   ');

        // Clearing the box is how a user leaves a search, so it must not go
        // looking for the empty string.
        self::assertStringEndsWith('/api/search/metadata', $this->requests[0]['url']);
        self::assertArrayNotHasKey('query', $this->jsonBodyOf(0));
    }

    public function testDownloadTakesTheFilenameFromContentDisposition(): void
    {
        $driver = $this->driver([
            new MockResponse('binary-jpeg', ['response_headers' => [
                'content-type'        => 'image/jpeg',
                'content-disposition' => 'attachment; filename="beach.jpg"',
            ]]),
        ]);

        $file = $driver->download($this->integration(), 'asset-1');

        self::assertSame('beach.jpg', $file->filename);
        self::assertSame('image/jpeg', $file->mime);
        self::assertSame('binary-jpeg', $file->contents);
    }

    public function testApiKeyTravelsInTheHeader(): void
    {
        $driver = $this->driver([new JsonMockResponse(['id' => 'me'])]);

        $driver->verify($this->integration());

        self::assertSame('x-api-key: immich-key', $this->requests[0]['options']['normalized_headers']['x-api-key'][0] ?? null);
    }

    public function testRejectedKeyBecomesAReadableMessage(): void
    {
        $driver = $this->driver([new MockResponse('', ['http_code' => 401])]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Immich rejected the API key.');

        $driver->verify($this->integration());
    }

    public function testShareLinkIsAlwaysNullAndIsNotAdvertised(): void
    {
        // Immich cannot share a single asset without creating an album, so the
        // provider must not claim the capability — otherwise the picker would
        // offer "insert link" and then hand back nothing.
        self::assertFalse(Provider::Immich->supports(Capability::ShareLink));
        self::assertNull($this->driver([])->shareLink($this->integration(), 'asset-1'));
    }

    public function testUploadDedupesOnContentHash(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'immich');
        self::assertIsString($path);
        file_put_contents($path, 'photo-bytes');

        try {
            $driver = $this->driver([new JsonMockResponse(['id' => 'new-asset', 'status' => 'created'])]);

            $id = $driver->upload($this->integration(), $path, 'beach.jpg', 'image/jpeg');

            self::assertSame('new-asset', $id);

            // Re-uploading identical bytes must present the same deviceAssetId,
            // which is what stops a re-run filter duplicating the library.
            self::assertStringContainsString('plmail-'.hash('sha256', 'photo-bytes'), $this->requests[0]['body']);
        } finally {
            unlink($path);
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * The client normalises an iterable body into a closure yielding chunks
     * until it returns ''.
     */
    /**
     * @return array<string,mixed>
     */
    private function jsonBodyOf(int $index): array
    {
        $decoded = json_decode($this->requests[$index]['body'], true);

        return is_array($decoded) ? $decoded : [];
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

    /**
     * @param list<ResponseInterface> $responses
     */
    private function driver(array $responses): ImmichDriver
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): ResponseInterface {
            // Drain the body here rather than at assertion time: the client
            // consumes a streamed body on its way out, so reading it later
            // always yields an empty string.
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

        return new ImmichDriver($client, new IntegrationUrlValidator(), $configRepository);
    }

    private function integration(): Integration
    {
        $integration = new Integration(new User(), Provider::Immich, 'Photos');
        $integration->baseUrl = 'https://photos.example.com';
        $integration->secret = 'immich-key';

        return $integration;
    }
}
