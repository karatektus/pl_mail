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
 * Immich's two-level browse (albums, then their assets) and the metadata gaps
 * the picker has to survive — an asset whose EXIF has not been extracted has
 * no size at all, which must read as "unknown" rather than "zero bytes".
 */
final class ImmichDriverTest extends TestCase
{
    /** @var list<array{method:string,url:string,options:array<string,mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->requests = [];
    }

    public function testRootListsAlbumsAsFolders(): void
    {
        $driver = $this->driver([
            new JsonMockResponse([
                ['id' => 'b-2', 'albumName' => 'Trips', 'updatedAt' => '2026-05-01T10:00:00Z'],
                ['id' => 'a-1', 'albumName' => 'Archive'],
            ]),
        ]);

        $listing = $driver->list($this->integration());

        self::assertSame(['Archive', 'Trips'], array_map(static fn ($e) => $e->name, $listing->entries));
        self::assertTrue($listing->entries[0]->isFolder);
        self::assertStringEndsWith('/api/albums', $this->requests[0]['url']);
    }

    public function testAlbumListsAssetsWithMetadata(): void
    {
        $driver = $this->driver([
            new JsonMockResponse([
                'id'        => 'b-2',
                'albumName' => 'Trips',
                'assets'    => [
                    [
                        'id'               => 'asset-1',
                        'originalFileName' => 'beach.jpg',
                        'originalMimeType' => 'image/jpeg',
                        'fileCreatedAt'    => '2026-06-11T12:00:00Z',
                        'exifInfo'         => ['fileSizeInByte' => 4_200_000],
                    ],
                    [
                        // No exifInfo at all — Immich has not processed it yet.
                        'id'               => 'asset-2',
                        'originalFileName' => 'clip.mp4',
                        'originalMimeType' => 'video/mp4',
                    ],
                ],
            ]),
        ]);

        $listing = $driver->list($this->integration(), 'b-2');

        self::assertCount(2, $listing->entries);
        self::assertSame(4_200_000, $listing->entries[0]->size);
        self::assertSame('image/jpeg', $listing->entries[0]->mime);
        self::assertNull($listing->entries[1]->size, 'a missing size is unknown, not zero');
        self::assertFalse($listing->entries[0]->isFolder);

        self::assertSame(['Immich', 'Trips'], array_map(static fn ($c) => $c->name, $listing->breadcrumb));
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
