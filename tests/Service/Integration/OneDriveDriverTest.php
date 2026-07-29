<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Service\Integration\Driver\OneDriveDriver;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * OneDrive. The upload split is what matters here: Graph documents simple PUT
 * only up to 4 MB, so anything larger has to go through an upload session and
 * be chunked — and attachments routinely straddle that line.
 */
final class OneDriveDriverTest extends OAuthDriverTestCase
{
    public function testListsRootChildren(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['value' => [
                ['id' => 'i1', 'name' => 'Documents', 'folder' => ['childCount' => 3]],
                ['id' => 'i2', 'name' => 'report.pdf', 'size' => 24019, 'file' => ['mimeType' => 'application/pdf'], 'lastModifiedDateTime' => '2026-07-20T09:14:00Z'],
            ]]),
        ]);

        $listing = $driver->list($this->integration(Provider::OneDrive));

        self::assertTrue($listing->entries[0]->isFolder);
        self::assertNull($listing->entries[0]->size, 'a folder has no size');
        self::assertSame(24019, $listing->entries[1]->size);
        self::assertSame('application/pdf', $listing->entries[1]->mime);
        self::assertStringContainsString('/me/drive/root/children', $this->requests[0]['url']);
    }

    public function testPaginationFollowsGraphsNextLinkVerbatim(): void
    {
        // Graph hands back a whole URL rather than a token, so it must be
        // followed as-is instead of being rebuilt from parameters.
        $next = 'https://graph.microsoft.com/v1.0/me/drive/root/children?$skiptoken=abc';

        $driver = $this->driver([
            new JsonMockResponse(['value' => [], '@odata.nextLink' => $next]),
            new JsonMockResponse(['value' => []]),
        ]);

        $first = $driver->list($this->integration(Provider::OneDrive));
        self::assertSame($next, $first->nextCursor);

        $driver->list($this->integration(Provider::OneDrive), null, $next);
        self::assertSame($next, $this->requests[1]['url']);
    }

    public function testSmallUploadUsesASinglePut(): void
    {
        $path = $this->tempFile('small');

        try {
            $driver = $this->driver([new JsonMockResponse(['id' => 'new1'])]);

            $id = $driver->upload($this->integration(Provider::OneDrive), $path, 'note.txt', 'text/plain', 'i1');

            self::assertSame('new1', $id);
            self::assertSame('PUT', $this->requests[0]['method']);
            // Graph's colon syntax addresses a new child by name under a parent.
            self::assertStringContainsString('/items/i1:/note.txt:/content', $this->requests[0]['url']);
            self::assertCount(1, $this->requests, 'no session should be created for a small file');
        } finally {
            unlink($path);
        }
    }

    public function testLargeUploadOpensASessionAndSendsChunksWithoutOurToken(): void
    {
        // Just over Graph's 4 MB simple-upload ceiling.
        $path = $this->tempFile(str_repeat('x', 4 * 1024 * 1024 + 10));

        try {
            $driver = $this->driver([
                new JsonMockResponse(['uploadUrl' => 'https://upload.example.com/session/1']),
                new JsonMockResponse(['id' => 'big1'], ['http_code' => 201]),
            ]);

            $id = $driver->upload($this->integration(Provider::OneDrive), $path, 'big.bin', 'application/octet-stream');

            self::assertSame('big1', $id);
            self::assertStringContainsString('createUploadSession', $this->requests[0]['url']);
            self::assertSame('https://upload.example.com/session/1', $this->requests[1]['url']);

            // The session URL is pre-authorised; Graph rejects a bearer token.
            self::assertNull($this->bearerOf(1));

            $range = $this->requests[1]['options']['normalized_headers']['content-range'][0] ?? '';
            self::assertStringContainsString('bytes 0-', $range);
        } finally {
            unlink($path);
        }
    }

    public function testShareLinkAsksForAnAnonymousViewLink(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['link' => ['webUrl' => 'https://1drv.ms/x/abc']]),
        ]);

        $url = $driver->shareLink($this->integration(Provider::OneDrive), 'i2');

        self::assertSame('https://1drv.ms/x/abc', $url);
        self::assertSame(['type' => 'view', 'scope' => 'anonymous'], $this->jsonBodyOf(0));
    }

    public function testThumbnailIsNullForAnUnrenderableType(): void
    {
        $driver = $this->driver([new MockResponse('', ['http_code' => 404])]);

        self::assertNull($driver->thumbnail($this->integration(Provider::OneDrive), 'i9'));
    }

    /**
     * @param list<ResponseInterface> $responses
     */
    private function driver(array $responses): OneDriveDriver
    {
        return new OneDriveDriver($this->client($responses), $this->tokens());
    }
}
