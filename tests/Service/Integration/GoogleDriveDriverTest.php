<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Service\Integration\Driver\GoogleDriveDriver;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Google Drive. The interesting parts are the two Drive behaviours that would
 * otherwise produce attachments that fail after the user picked them: trashed
 * files still list unless excluded, and native Google formats have no bytes to
 * download at all.
 */
final class GoogleDriveDriverTest extends OAuthDriverTestCase
{
    public function testListsChildrenAndExcludesTrash(): void
    {
        $driver = $this->driver([
            new JsonMockResponse([
                'files' => [
                    ['id' => 'f1', 'name' => 'Invoices', 'mimeType' => 'application/vnd.google-apps.folder'],
                    ['id' => 'd1', 'name' => 'report.pdf', 'mimeType' => 'application/pdf', 'size' => '24019', 'modifiedTime' => '2026-07-20T09:14:00Z'],
                ],
            ]),
        ]);

        $listing = $driver->list($this->integration(Provider::GoogleDrive));

        self::assertCount(2, $listing->entries);
        self::assertTrue($listing->entries[0]->isFolder);
        self::assertSame(24019, $listing->entries[1]->size);
        self::assertSame('application/pdf', $listing->entries[1]->mime);

        // Trashed files would otherwise be pickable and then 404 on download.
        self::assertStringContainsString('trashed%20%3D%20false', $this->requests[0]['url']);
        // Root is Drive's own alias, since Drive has no path addressing.
        self::assertStringContainsString('%27root%27%20in%20parents', $this->requests[0]['url']);
        self::assertSame('access-token', $this->bearerOf(0));
    }

    public function testNativeGoogleFormatsReportNoSizeSoThePickerTreatsThemAsUnattachable(): void
    {
        $driver = $this->driver([
            new JsonMockResponse([
                'files' => [
                    ['id' => 'doc1', 'name' => 'Notes', 'mimeType' => 'application/vnd.google-apps.document'],
                ],
            ]),
        ]);

        $listing = $driver->list($this->integration(Provider::GoogleDrive));

        self::assertNull($listing->entries[0]->size);
    }

    public function testDownloadingANativeGoogleFormatIsRefusedWithAReason(): void
    {
        // Exporting would mean silently choosing a format on the user's behalf.
        $driver = $this->driver([
            new JsonMockResponse(['id' => 'doc1', 'name' => 'Notes', 'mimeType' => 'application/vnd.google-apps.document']),
        ]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessageMatches('/Google document/');

        $driver->download($this->integration(Provider::GoogleDrive), 'doc1');
    }

    public function testDownloadFetchesMetadataThenBytes(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['id' => 'd1', 'name' => 'report.pdf', 'mimeType' => 'application/pdf', 'size' => '11']),
            new MockResponse('%PDF-1.7 ..'),
        ]);

        $file = $driver->download($this->integration(Provider::GoogleDrive), 'd1');

        self::assertSame('report.pdf', $file->filename);
        self::assertSame('application/pdf', $file->mime);
        self::assertSame('%PDF-1.7 ..', $file->contents);
        self::assertStringContainsString('alt=media', $this->requests[1]['url']);
    }

    public function testUploadSendsRelatedMultipartWithMetadataFirst(): void
    {
        $path = $this->tempFile('bytes');

        try {
            $driver = $this->driver([new JsonMockResponse(['id' => 'new1'])]);

            $id = $driver->upload($this->integration(Provider::GoogleDrive), $path, 'beach.jpg', 'image/jpeg', 'f1');

            self::assertSame('new1', $id);

            $body = $this->requests[0]['body'];
            self::assertStringContainsString('"name":"beach.jpg"', $body);
            self::assertStringContainsString('"parents":["f1"]', $body);

            // multipart/related, not form-data — Drive rejects the latter.
            $contentType = $this->requests[0]['options']['normalized_headers']['content-type'][0] ?? '';
            self::assertStringContainsString('multipart/related', $contentType);
            self::assertStringContainsString('boundary=', $contentType);
        } finally {
            unlink($path);
        }
    }

    public function testShareLinkGrantsAnyoneThenReadsBackTheUrl(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['id' => 'perm1']),
            new JsonMockResponse(['webViewLink' => 'https://drive.google.com/file/d/d1/view']),
        ]);

        $url = $driver->shareLink($this->integration(Provider::GoogleDrive), 'd1');

        self::assertSame('https://drive.google.com/file/d/d1/view', $url);
        self::assertSame(['role' => 'reader', 'type' => 'anyone'], $this->jsonBodyOf(0));
    }

    public function testAnExpiredConsentAsksForAReconnectRatherThanAPasswordCheck(): void
    {
        $driver = $this->driver([new MockResponse('', ['http_code' => 401])]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessageMatches('/reconnect/i');

        $driver->verify($this->integration(Provider::GoogleDrive));
    }

    public function testSearchQueriesByNameAndStillExcludesTrash(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['files' => [
                ['id' => 'd1', 'name' => 'report.pdf', 'mimeType' => 'application/pdf', 'size' => '10'],
            ]]),
        ]);

        $listing = $driver->search($this->integration(Provider::GoogleDrive), 'report', null);

        self::assertSame(['report.pdf'], array_map(static fn ($e) => $e->name, $listing->entries));
        self::assertStringContainsString('name%20contains', $this->requests[0]['url']);
        self::assertStringContainsString('trashed%20%3D%20false', $this->requests[0]['url']);
    }

    public function testSearchStripsQuotesThatWouldEscapeTheQueryClause(): void
    {
        $driver = $this->driver([new JsonMockResponse(['files' => []])]);

        $driver->search($this->integration(Provider::GoogleDrive), "it's a trap'", null);

        // Drive's query language is string-delimited, so a stray quote would
        // break out of the clause.
        self::assertStringNotContainsString('%27s', $this->requests[0]['url']);
    }

    /**
     * @param list<ResponseInterface> $responses
     */
    private function driver(array $responses): GoogleDriveDriver
    {
        return new GoogleDriveDriver($this->client($responses), $this->tokens());
    }
}
