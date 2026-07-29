<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Service\Integration\Driver\DropboxDriver;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Dropbox. Three quirks worth pinning, all of which produce wrong behaviour
 * rather than an obvious error when got wrong: the root is the empty string and
 * never '/', the Dropbox-API-Arg header must be pure ASCII, and a file that is
 * already shared answers 409 instead of returning its existing link.
 */
final class DropboxDriverTest extends OAuthDriverTestCase
{
    public function testListsAFolderWithFoldersFirst(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['entries' => [
                ['.tag' => 'file', 'name' => 'report.pdf', 'path_lower' => '/report.pdf', 'size' => 24019, 'server_modified' => '2026-07-20T09:14:00Z'],
                ['.tag' => 'folder', 'name' => 'Photos', 'path_lower' => '/photos'],
            ], 'has_more' => false]),
        ]);

        $listing = $driver->list($this->integration(Provider::Dropbox));

        self::assertSame(['Photos', 'report.pdf'], array_map(static fn ($e) => $e->name, $listing->entries));
        self::assertSame(24019, $listing->entries[1]->size);
        // Dropbox reports no MIME type, so inventing one here would be a lie.
        self::assertNull($listing->entries[1]->mime);
    }

    public function testTheRootIsTheEmptyStringNeverASlash(): void
    {
        $driver = $this->driver([new JsonMockResponse(['entries' => []])]);

        $driver->list($this->integration(Provider::Dropbox), '');

        self::assertSame('', $this->jsonBodyOf(0)['path'] ?? null);
    }

    public function testTraversalSegmentsAreDroppedBeforeReachingTheApi(): void
    {
        $driver = $this->driver([new JsonMockResponse(['entries' => []])]);

        $driver->list($this->integration(Provider::Dropbox), '/photos/../../etc');

        self::assertSame('/photos/etc', $this->jsonBodyOf(0)['path'] ?? null);
    }

    public function testHasMoreYieldsACursorAndContinueUsesIt(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['entries' => [], 'has_more' => true, 'cursor' => 'cur-1']),
            new JsonMockResponse(['entries' => [], 'has_more' => false]),
        ]);

        $first = $driver->list($this->integration(Provider::Dropbox));
        self::assertSame('cur-1', $first->nextCursor);

        $driver->list($this->integration(Provider::Dropbox), '', 'cur-1');
        self::assertStringContainsString('/files/list_folder/continue', $this->requests[1]['url']);
        self::assertSame('cur-1', $this->jsonBodyOf(1)['cursor'] ?? null);
    }

    public function testDownloadPutsArgumentsInTheHeaderBecauseTheBodyIsTheFile(): void
    {
        $driver = $this->driver([
            new MockResponse('pdf-bytes', ['response_headers' => ['content-type' => 'application/pdf']]),
        ]);

        $file = $driver->download($this->integration(Provider::Dropbox), '/report.pdf');

        self::assertSame('report.pdf', $file->filename);
        self::assertSame('pdf-bytes', $file->contents);

        $arg = $this->requests[0]['options']['normalized_headers']['dropbox-api-arg'][0] ?? '';
        self::assertStringContainsString('"path":"/report.pdf"', $arg);
    }

    public function testNonAsciiFilenamesAreEscapedInTheHeader(): void
    {
        $path = $this->tempFile('bytes');

        try {
            $driver = $this->driver([new JsonMockResponse(['path_lower' => '/grüße.pdf'])]);

            $driver->upload($this->integration(Provider::Dropbox), $path, 'Grüße.pdf', 'application/pdf');

            // HTTP headers are ASCII-only; an unescaped umlaut makes Dropbox
            // reject the whole request, so the name must arrive \u-escaped.
            $arg = $this->requests[0]['options']['normalized_headers']['dropbox-api-arg'][0] ?? '';
            self::assertStringNotContainsString('ü', $arg);
            self::assertStringContainsString('Gr\u00fc\u00dfe.pdf', $arg);
        } finally {
            unlink($path);
        }
    }

    public function testUploadRenamesRatherThanOverwrites(): void
    {
        $path = $this->tempFile('bytes');

        try {
            $driver = $this->driver([new JsonMockResponse(['path_lower' => '/folder/report.pdf'])]);

            $id = $driver->upload($this->integration(Provider::Dropbox), $path, 'report.pdf', 'application/pdf', '/folder');

            self::assertSame('/folder/report.pdf', $id);

            // Two attachments with the same name from different mail must not
            // silently destroy one another.
            $arg = $this->requests[0]['options']['normalized_headers']['dropbox-api-arg'][0] ?? '';
            self::assertStringContainsString('"mode":"add"', $arg);
            self::assertStringContainsString('"autorename":true', $arg);
        } finally {
            unlink($path);
        }
    }

    public function testAnAlreadySharedFileFallsBackToItsExistingLink(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(
                ['error_summary' => 'shared_link_already_exists/..'],
                ['http_code' => 409],
            ),
            new JsonMockResponse(['links' => [['url' => 'https://www.dropbox.com/s/abc/report.pdf']]]),
        ]);

        $url = $driver->shareLink($this->integration(Provider::Dropbox), '/report.pdf');

        // 409 here means "already shared", not a failure — returning null would
        // lose the user a perfectly good link.
        self::assertSame('https://www.dropbox.com/s/abc/report.pdf', $url);
    }

    public function testANewShareLinkIsReturnedDirectly(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['url' => 'https://www.dropbox.com/s/new/report.pdf']),
        ]);

        self::assertSame(
            'https://www.dropbox.com/s/new/report.pdf',
            $driver->shareLink($this->integration(Provider::Dropbox), '/report.pdf'),
        );
    }

    public function testThumbnailIsNullForAnUnrenderableType(): void
    {
        // Dropbox answers 409 for anything it cannot render — normal for a zip.
        $driver = $this->driver([new MockResponse('', ['http_code' => 409])]);

        self::assertNull($driver->thumbnail($this->integration(Provider::Dropbox), '/archive.zip'));
    }

    /**
     * @param list<ResponseInterface> $responses
     */
    private function driver(array $responses): DropboxDriver
    {
        return new DropboxDriver($this->client($responses), $this->tokens());
    }
}
