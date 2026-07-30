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

        // Albums and people stay reachable, but as sideways jumps rather than
        // the entrance — both are slices of the same library, so neither is a
        // parent of the other.
        self::assertSame(['Albums', 'People'], array_map(static fn ($s) => $s->name, $listing->shortcuts));
        self::assertSame(['albums', 'people'], array_map(static fn ($s) => $s->id, $listing->shortcuts));
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

        $listing = $driver->search($this->integration(), 'beach at sunset', null);

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

        $listing = $driver->search($this->integration(), 'sunset', null);

        self::assertCount(1, $listing->entries);
        self::assertStringEndsWith('/api/search/smart', $this->requests[0]['url']);
        self::assertStringEndsWith('/api/search/metadata', $this->requests[1]['url']);
        self::assertSame('sunset', $this->jsonBodyOf(1)['originalFileName'] ?? null);
    }

    public function testAnEmptyQueryFallsBackToTheLibrary(): void
    {
        $driver = $this->driver([new JsonMockResponse(['assets' => ['items' => []]])]);

        $driver->search($this->integration(), '   ', null);

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

    public function testPeopleAreEntriesOfTheirOwnKindNotFolders(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['people' => [
                ['id' => 'p1', 'name' => 'Ada'],
                // An unnamed face is still worth browsing.
                ['id' => 'p2', 'name' => ''],
            ]]),
        ]);

        $listing = $driver->list($this->integration(), 'people');

        self::assertSame(['Ada', 'Unnamed'], array_map(static fn ($e) => $e->name, $listing->entries));
        // Kind is what makes the picker render a portrait with the name always
        // shown rather than a folder row.
        self::assertSame('person', $listing->entries[0]->kind()->value);
        self::assertTrue($listing->entries[0]->isFolder, 'a person is navigable');
        // Prefixed, because album ids are UUIDs too and would otherwise be
        // indistinguishable.
        self::assertSame('person:p1', $listing->entries[0]->id);
    }

    public function testSearchingFromThePeopleViewFiltersFacesRatherThanPhotos(): void
    {
        $driver = $this->driver([
            new JsonMockResponse([['id' => 'p1', 'name' => 'Ada Lovelace']]),
        ]);

        $listing = $driver->search($this->integration(), 'ada', 'people');

        self::assertSame(['Ada Lovelace'], array_map(static fn ($e) => $e->name, $listing->entries));
        self::assertStringContainsString('/api/search/person', $this->requests[0]['url']);
        self::assertStringContainsString('name=ada', $this->requests[0]['url']);
    }

    public function testAPersonsPhotosComeFromSearchByPersonId(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['assets' => ['items' => [
                ['id' => 'm1', 'originalFileName' => 'ada.jpg'],
            ]]]),
            new JsonMockResponse(['name' => 'Ada']),
        ]);

        $listing = $driver->list($this->integration(), 'person:p1');

        self::assertSame(['ada.jpg'], array_map(static fn ($e) => $e->name, $listing->entries));
        self::assertSame(['p1'], $this->jsonBodyOf(0)['personIds'] ?? null);
        self::assertSame(['Immich', 'People', 'Ada'], array_map(static fn ($c) => $c->name, $listing->breadcrumb));
    }

    public function testTimelineBucketsCarryTheCursorThatLandsOnThem(): void
    {
        $driver = $this->driver([
            new JsonMockResponse([
                ['timeBucket' => '2026-07-01T00:00:00.000Z', 'count' => 120],
                ['timeBucket' => '2026-06-01T00:00:00.000Z', 'count' => 4],
                // Empty buckets would render as invisible slivers.
                ['timeBucket' => '2026-05-01T00:00:00.000Z', 'count' => 0],
            ]),
        ]);

        $buckets = $driver->timelineBuckets($this->integration());

        self::assertCount(2, $buckets);
        self::assertSame('July 2026', $buckets[0]->title);
        self::assertSame('2026', $buckets[0]->label, 'the bar is too narrow for a month');
        self::assertSame(120, $buckets[0]->count);
        self::assertSame('2026-07-01@1', $buckets[0]->cursor);
    }

    public function testAMissingBucketsEndpointCostsTheScrubberAndNothingElse(): void
    {
        // A scrubber is cosmetic; taking the whole picker down for it would
        // trade the main feature for a small one.
        $driver = $this->driver([new MockResponse('', ['http_code' => 404])]);

        self::assertSame([], $driver->timelineBuckets($this->integration()));
    }

    public function testAJumpKeepsItsAnchorOnTheFollowingPage(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['assets' => ['items' => [], 'nextPage' => '2']]),
        ]);

        $listing = $driver->list($this->integration(), 'timeline', '2019-08-01@1');

        // takenBefore is what makes the jump instant — the query starts there
        // rather than paging forward until 2019 appears.
        self::assertSame('2019-08-01T23:59:59.999Z', $this->jsonBodyOf(0)['takenBefore'] ?? null);
        // And the anchor rides along, or page two would silently come from the
        // top of the library.
        self::assertSame('2019-08-01@2', $listing->nextCursor);
    }

    public function testAHandEditedCursorFallsBackToTheStartInsteadOfFailing(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['assets' => ['items' => []]]),
        ]);

        $driver->list($this->integration(), 'timeline', 'not-a-date@9');

        $body = $this->jsonBodyOf(0);
        self::assertArrayNotHasKey('takenBefore', $body);
        self::assertSame(1, $body['page'] ?? null);
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
