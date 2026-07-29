<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\Enum\Integration\Capability;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Service\Integration\Driver\GooglePhotosDriver;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Google Photos. Two things need pinning: the two-step upload, whose per-item
 * status hides inside a 200 response, and the fact that bytes come from a
 * short-lived baseUrl that must NOT carry our bearer token.
 */
final class GooglePhotosDriverTest extends OAuthDriverTestCase
{
    public function testRootListsAlbumsAsFolders(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['albums' => [
                ['id' => 'a1', 'title' => 'Trips'],
                ['id' => 'a2', 'title' => 'Archive'],
            ]]),
        ]);

        $listing = $driver->list($this->integration(Provider::GooglePhotos));

        self::assertSame(['Trips', 'Archive'], array_map(static fn ($e) => $e->name, $listing->entries));
        self::assertTrue($listing->entries[0]->isFolder);
    }

    public function testAlbumItemsAreSearchedByPostAndReportUnknownSize(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['mediaItems' => [
                ['id' => 'm1', 'filename' => 'beach.jpg', 'mimeType' => 'image/jpeg', 'mediaMetadata' => ['creationTime' => '2026-06-11T12:00:00Z']],
            ]]),
            new JsonMockResponse(['title' => 'Trips']),
        ]);

        $listing = $driver->list($this->integration(Provider::GooglePhotos), 'a1');

        self::assertSame('beach.jpg', $listing->entries[0]->name);
        // The API reports no byte size at all, so the picker must treat every
        // photo as possibly-too-big rather than assuming it fits.
        self::assertNull($listing->entries[0]->size);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('a1', $this->jsonBodyOf(0)['albumId'] ?? null);
        self::assertSame(['Google Photos', 'Trips'], array_map(static fn ($c) => $c->name, $listing->breadcrumb));
    }

    public function testDownloadUsesBaseUrlWithoutSendingOurToken(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['id' => 'm1', 'filename' => 'beach.jpg', 'mimeType' => 'image/jpeg', 'baseUrl' => 'https://lh3.googleusercontent.com/xyz']),
            new MockResponse('jpeg-bytes'),
        ]);

        $file = $driver->download($this->integration(Provider::GooglePhotos), 'm1');

        self::assertSame('beach.jpg', $file->filename);
        self::assertSame('jpeg-bytes', $file->contents);

        // =d asks for the original rather than a resized rendition.
        self::assertSame('https://lh3.googleusercontent.com/xyz=d', $this->requests[1]['url']);
        // baseUrl carries its own credential; Google rejects ours alongside it.
        self::assertSame('access-token', $this->bearerOf(0));
        self::assertNull($this->bearerOf(1));
    }

    public function testUploadPostsBytesThenCommitsTheToken(): void
    {
        $path = $this->tempFile('photo-bytes');

        try {
            $driver = $this->driver([
                new MockResponse('upload-token-123'),
                new JsonMockResponse(['newMediaItemResults' => [
                    ['status' => ['message' => 'Success'], 'mediaItem' => ['id' => 'm9']],
                ]]),
            ]);

            $id = $driver->upload($this->integration(Provider::GooglePhotos), $path, 'beach.jpg', 'image/jpeg', 'a1');

            self::assertSame('m9', $id);

            $commit = $this->jsonBodyOf(1);
            self::assertSame('a1', $commit['albumId'] ?? null);
            self::assertSame(
                'upload-token-123',
                $commit['newMediaItems'][0]['simpleMediaItem']['uploadToken'] ?? null,
            );
        } finally {
            unlink($path);
        }
    }

    public function testARejectedCommitFailsEvenThoughTheHttpCallSucceeded(): void
    {
        $path = $this->tempFile('photo-bytes');

        try {
            // batchCreate answers 200 with the real outcome per item, so
            // trusting the status code would report a phantom success.
            $driver = $this->driver([
                new MockResponse('upload-token-123'),
                new JsonMockResponse(['newMediaItemResults' => [
                    ['status' => ['message' => 'Invalid media item']],
                ]]),
            ]);

            $this->expectException(IntegrationException::class);
            $this->expectExceptionMessageMatches('/Invalid media item/');

            $driver->upload($this->integration(Provider::GooglePhotos), $path, 'beach.jpg', 'image/jpeg');
        } finally {
            unlink($path);
        }
    }

    public function testShareLinkIsNeitherOfferedNorReturned(): void
    {
        self::assertFalse(Provider::GooglePhotos->supports(Capability::ShareLink));
        self::assertNull($this->driver([])->shareLink($this->integration(Provider::GooglePhotos), 'm1'));
    }

    /**
     * @param list<ResponseInterface> $responses
     */
    private function driver(array $responses): GooglePhotosDriver
    {
        return new GooglePhotosDriver($this->client($responses), $this->tokens());
    }
}
