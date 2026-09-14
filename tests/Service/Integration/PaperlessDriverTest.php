<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\Enum\Integration\Capability;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Service\Integration\Driver\PaperlessDriver;
use App\Service\Integration\IntegrationUrlValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The two things about Paperless that the picker cannot simply assume.
 *
 * It has no folders, so tags stand in as the container layer — and that
 * substitution has to hold in three places at once: the root lists them, a tag
 * lists its documents under a trail back to the root, and a save files under
 * one. Get any of those wrong and the archive is a single flat list again.
 *
 * And an upload does not produce a document. post_document/ is asynchronous and
 * answers with a consumer *task* id, so the value upload() contractually returns
 * is not a document id and must never be mistaken for one. That is asserted from
 * both ends: what upload() hands back, and what download() does when handed it.
 */
final class PaperlessDriverTest extends TestCase
{
    /** @var list<array{method:string,url:string,options:array<string,mixed>,body:string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->requests = [];
    }

    // ── Credentials ───────────────────────────────────────────────────────────

    public function testTheTokenTravelsAsADrfTokenHeader(): void
    {
        $driver = $this->driver([new JsonMockResponse(['count' => 1, 'results' => []])]);

        $driver->verify($this->integration());

        // "Token", not "Bearer": Paperless uses DRF token auth, which refuses a
        // Bearer scheme outright and would 401 a perfectly good token.
        self::assertSame(
            'Authorization: Token paperless-token',
            $this->requests[0]['options']['normalized_headers']['authorization'][0] ?? null,
        );
        // The cheapest call that proves the address and the token at once, and
        // one that every Paperless version has — unlike /api/profile/.
        self::assertStringContainsString('/api/documents/', $this->requests[0]['url']);
        self::assertStringContainsString('page_size=1', $this->requests[0]['url']);
    }

    public function testARejectedTokenBecomesAReadableMessage(): void
    {
        $driver = $this->driver([new MockResponse('', ['http_code' => 401])]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Paperless rejected the API token.');

        $driver->verify($this->integration());
    }

    // ── Browsing ──────────────────────────────────────────────────────────────

    /**
     * Paperless replaced folders with tags, so the root offers the tags as
     * containers and the newest documents under them. A flat list of every
     * document would be a scroll bar rather than a selector.
     */
    public function testTheRootListsTagsAsFoldersAheadOfTheNewestDocuments(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['results' => [
                ['id' => 4, 'name' => 'Taxes'],
                ['id' => 2, 'name' => 'invoices'],
            ]]),
            new JsonMockResponse(['count' => 1, 'next' => null, 'results' => [
                [
                    'id'                 => 91,
                    'title'              => 'Electricity bill',
                    'created'            => '2026-04-02T00:00:00Z',
                    'archived_file_name' => '2026-04-02 Electricity bill.pdf',
                ],
            ]]),
        ]);

        $listing = $driver->list($this->integration());

        // Sorted naturally and case-insensitively, the way every other driver
        // sorts its folders, so a tag list and a folder list read alike.
        self::assertSame(
            ['invoices', 'Taxes', 'Electricity bill'],
            array_map(static fn ($entry) => $entry->name, $listing->entries),
        );
        self::assertSame([true, true, false], array_map(
            static fn ($entry) => $entry->isFolder,
            $listing->entries,
        ));
        self::assertSame(['2', '4', '91'], array_map(
            static fn ($entry) => $entry->id,
            $listing->entries,
        ));

        // The title is the row's name, because that is what Paperless shows and
        // what a person recognises; the mime still comes off the filename so the
        // row gets its PDF icon on a server too old to report mime_type.
        self::assertSame('application/pdf', $listing->entries[2]->mime);
        // No size is reported on a document listing at all. Null means "not
        // known", which keeps the row attachable — the attach endpoint re-checks
        // once it holds the bytes.
        self::assertNull($listing->entries[2]->size);
        self::assertSame('2026-04-02', $listing->entries[2]->modifiedAt?->format('Y-m-d'));

        self::assertSame(['Paperless'], array_map(static fn ($crumb) => $crumb->name, $listing->breadcrumb));
        self::assertStringContainsString('/api/tags/', $this->requests[0]['url']);
        self::assertStringContainsString('ordering=-created', $this->requests[1]['url']);
    }

    /**
     * The tags ride on the first page only. They are the same set on every page,
     * and the picker appends pages rather than replacing them, so repeating them
     * mid-scroll would read as duplicates.
     */
    public function testASecondPageOfTheRootCarriesNoTags(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['next' => null, 'results' => []]),
        ]);

        $listing = $driver->list($this->integration(), null, '2');

        self::assertSame([], $listing->entries);
        self::assertCount(1, $this->requests, 'the tag list is not fetched again');
        self::assertStringContainsString('page=2', $this->requests[0]['url']);
    }

    public function testATagListsItsOwnDocumentsUnderATrailBackToTheRoot(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['id' => 2, 'name' => 'invoices']),
            new JsonMockResponse(['next' => 'https://paperless.example.com/api/documents/?page=2', 'results' => [
                ['id' => 91, 'title' => 'Electricity bill', 'original_file_name' => 'bill.pdf'],
            ]]),
        ]);

        $listing = $driver->list($this->integration(), '2');

        self::assertSame(['Electricity bill'], array_map(static fn ($e) => $e->name, $listing->entries));
        self::assertSame(['Paperless', 'invoices'], array_map(static fn ($c) => $c->name, $listing->breadcrumb));
        self::assertSame(['', '2'], array_map(static fn ($c) => $c->id, $listing->breadcrumb));
        self::assertStringContainsString('tags__id__all=2', $this->requests[1]['url']);

        // The cursor is a page number, not Paperless's own `next` URL: behind a
        // reverse proxy that URL is routinely the internal address, and every
        // request has to go back through IntegrationUrlValidator regardless.
        self::assertSame('2', $listing->nextCursor);
    }

    public function testAHandEditedFolderIdFallsBackToTheRootInsteadOfFailing(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['results' => []]),
            new JsonMockResponse(['results' => []]),
        ]);

        $driver->list($this->integration(), '../../etc/passwd');

        // Nothing that is not an integer reaches a URL, and a typed URL is not
        // worth a 500 — so it reads as the root, which is always safe to show.
        self::assertStringContainsString('/api/tags/', $this->requests[0]['url']);
        self::assertStringNotContainsString('passwd', $this->requests[1]['url']);
        self::assertStringNotContainsString('tags__id__all', $this->requests[1]['url']);
    }

    // ── Search ────────────────────────────────────────────────────────────────

    /**
     * Paperless's full-text index reads the OCR layer, which is the whole reason
     * this integration exists: a scanned invoice is findable by a line item
     * nobody ever typed, without opening Paperless first.
     */
    public function testSearchGoesThroughTheFullTextQueryAndNamesItselfInTheTrail(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['next' => null, 'results' => [
                ['id' => 91, 'title' => 'Electricity bill'],
            ]]),
        ]);

        $listing = $driver->search($this->integration(), '  kilowatt  ');

        self::assertSame(['Electricity bill'], array_map(static fn ($e) => $e->name, $listing->entries));
        self::assertStringContainsString('query=kilowatt', $this->requests[0]['url']);
        // Relevance comes from the index, so no ordering is imposed on top of it.
        self::assertStringNotContainsString('ordering', $this->requests[0]['url']);
        self::assertSame(['Paperless', '“kilowatt”'], array_map(
            static fn ($c) => $c->name,
            $listing->breadcrumb,
        ));
    }

    public function testASearchLaunchedInsideATagStaysInsideIt(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['id' => 2, 'name' => 'invoices']),
            new JsonMockResponse(['next' => null, 'results' => []]),
        ]);

        $listing = $driver->search($this->integration(), 'kilowatt', '2');

        self::assertStringContainsString('tags__id__all=2', $this->requests[1]['url']);
        // The middle crumb still links back to the tag, because the search is a
        // slice of it rather than of the whole archive.
        self::assertSame(['Paperless', 'invoices', '“kilowatt”'], array_map(
            static fn ($c) => $c->name,
            $listing->breadcrumb,
        ));
    }

    public function testSearchResultsPageThroughTheCursor(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['next' => 'https://paperless.example.com/api/documents/?page=4', 'results' => [
                ['id' => 12, 'title' => 'Page three hit'],
            ]]),
        ]);

        $listing = $driver->search($this->integration(), 'kilowatt', null, '3');

        self::assertStringContainsString('page=3', $this->requests[0]['url']);
        self::assertStringContainsString('query=kilowatt', $this->requests[0]['url']);
        self::assertSame('4', $listing->nextCursor, 'the cursor follows the page, not the URL Paperless sent');
    }

    public function testAnEmptyQueryGoesBackToWhereTheUserWas(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['results' => []]),
            new JsonMockResponse(['results' => []]),
        ]);

        $driver->search($this->integration(), '   ');

        // Clearing the box is how a user leaves a search, so it must not go
        // looking for the empty string.
        self::assertStringContainsString('/api/tags/', $this->requests[0]['url']);
        self::assertStringNotContainsString('query=', $this->requests[1]['url']);
    }

    // ── Fetching ──────────────────────────────────────────────────────────────

    public function testDownloadTakesTheFilenameFromContentDisposition(): void
    {
        $driver = $this->driver([
            new MockResponse('%PDF-1.7 ...', ['response_headers' => [
                'content-type'        => 'application/pdf',
                'content-disposition' => 'attachment; filename="2026-04-02 Electricity bill.pdf"',
            ]]),
        ]);

        $file = $driver->download($this->integration(), '91');

        self::assertSame('2026-04-02 Electricity bill.pdf', $file->filename);
        self::assertSame('application/pdf', $file->mime);
        self::assertSame('%PDF-1.7 ...', $file->contents);
        // No ?original=true: /download/ serves the archived, OCR'd PDF and falls
        // back to the original by itself where Paperless made none.
        self::assertStringEndsWith('/api/documents/91/download/', $this->requests[0]['url']);
    }

    public function testDownloadAsksForTheNameWhenTheHeaderWasStripped(): void
    {
        $driver = $this->driver([
            new MockResponse('%PDF-1.7 ...', ['response_headers' => ['content-type' => 'application/pdf']]),
            new JsonMockResponse(['id' => 91, 'archived_file_name' => 'bill.pdf', 'title' => 'Electricity bill']),
        ]);

        $file = $driver->download($this->integration(), '91');

        // An attachment called "91" is a bug report, and the extra call only
        // happens when a proxy has eaten the header.
        self::assertSame('bill.pdf', $file->filename);
        self::assertStringEndsWith('/api/documents/91/', $this->requests[1]['url']);
    }

    public function testThumbnailReturnsThePreviewAndNullWhereThereIsNone(): void
    {
        $driver = $this->driver([
            new MockResponse('png-bytes', ['response_headers' => ['content-type' => 'image/png']]),
            new MockResponse('', ['http_code' => 404]),
        ]);

        $preview = $driver->thumbnail($this->integration(), '91');

        self::assertNotNull($preview);
        self::assertSame('image/png', $preview->mime);
        self::assertSame('png-bytes', $preview->contents);
        self::assertStringEndsWith('/api/documents/91/thumb/', $this->requests[0]['url']);

        // A missing preview is a null rather than an error: the picker renders a
        // placeholder from it, and plenty of things legitimately have none.
        self::assertNull($driver->thumbnail($this->integration(), '92'));
    }

    // ── Saving out ────────────────────────────────────────────────────────────

    /**
     * post_document/ is asynchronous, so what comes back is a consumer task id
     * and not a document id. Returning it bare would hand the caller something
     * that looks exactly like a document id and is not one.
     */
    public function testUploadReturnsAMarkedTaskIdRatherThanAFakeDocumentId(): void
    {
        $path = $this->scratchFile('scan-bytes');

        try {
            // A quoted UUID, which is the whole body — not an object.
            $driver = $this->driver([new MockResponse('"3f2a1b8c-0000-4000-8000-000000000001"')]);

            $id = $driver->upload($this->integration(), $path, 'bill.pdf', 'application/pdf');

            self::assertSame('task:3f2a1b8c-0000-4000-8000-000000000001', $id);
            self::assertSame('POST', $this->requests[0]['method']);
            self::assertStringEndsWith('/api/documents/post_document/', $this->requests[0]['url']);

            // The extension is stripped from the title, because Paperless would
            // otherwise file a document literally called "bill.pdf".
            self::assertStringContainsString('name="title"', $this->requests[0]['body']);
            self::assertStringContainsString('bill', $this->requests[0]['body']);
            // No tag chosen, so no tags field at all rather than an empty one.
            self::assertStringNotContainsString('name="tags"', $this->requests[0]['body']);
        } finally {
            unlink($path);
        }
    }

    public function testNothingCanMistakeATaskIdForADocument(): void
    {
        $driver = $this->driver([]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Paperless is still filing that document, so it cannot be attached yet.');

        // Refused before any request is made — the point of marking the id is
        // that the driver recognises its own.
        $driver->download($this->integration(), 'task:3f2a1b8c-0000-4000-8000-000000000001');
    }

    public function testUploadFilesUnderTheChosenTag(): void
    {
        $path = $this->scratchFile('scan-bytes');

        try {
            $driver = $this->driver([new MockResponse('"3f2a1b8c-0000-4000-8000-000000000002"')]);

            $driver->upload($this->integration(), $path, 'bill.pdf', 'application/pdf', '2');

            // A repeated form field named "tags", not "tags[0]": Paperless reads
            // the latter as a field it does not have and files the document
            // under nothing.
            self::assertStringContainsString('name="tags"', $this->requests[0]['body']);
            self::assertStringNotContainsString('name="tags[0]"', $this->requests[0]['body']);
        } finally {
            unlink($path);
        }
    }

    public function testDestinationsAreTheTagsWithNoDocumentsMixedIn(): void
    {
        $driver = $this->driver([
            new JsonMockResponse(['results' => [
                ['id' => 4, 'name' => 'Taxes'],
                ['id' => 2, 'name' => 'invoices'],
            ]]),
        ]);

        $listing = $driver->destinations($this->integration());

        self::assertSame(['invoices', 'Taxes'], array_map(static fn ($e) => $e->name, $listing->entries));
        self::assertCount(1, $this->requests, 'a destination pick asks for no documents');
    }

    /**
     * A tag id arrives in a request, so it is confirmed against this account's
     * own tags before a byte is uploaded. "Save under this tag" that silently
     * files under none is a lie to the user.
     */
    public function testAssertDestinationRefusesATagThisAccountDoesNotHold(): void
    {
        $driver = $this->driver([new MockResponse('', ['http_code' => 404])]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('That tag is not in this Paperless account.');

        $driver->assertDestination($this->integration(), '9999');
    }

    public function testAssertDestinationRefusesAnythingThatIsNotAnIdAtAll(): void
    {
        $driver = $this->driver([]);

        try {
            $driver->assertDestination($this->integration(), '../../etc');
            self::fail('a traversal attempt should not be accepted as a tag');
        } catch (IntegrationException $e) {
            self::assertSame('That tag is not in this Paperless account.', $e->getMessage());
        }

        // Stopped by shape before it is stopped by lookup, so it never reaches
        // a URL in the first place.
        self::assertSame([], $this->requests);
    }

    public function testAssertDestinationAcceptsATagThisAccountHoldsAndTheEmptyDefault(): void
    {
        $driver = $this->driver([new JsonMockResponse(['id' => 2, 'name' => 'invoices'])]);

        $driver->assertDestination($this->integration(), '2');
        self::assertCount(1, $this->requests);

        // The empty string is "no tag" — Paperless's own inbox — and costs no
        // round trip.
        $driver->assertDestination($this->integration(), '');
        self::assertCount(1, $this->requests);
    }

    public function testCreateDestinationPostsATagAndReturnsItsId(): void
    {
        $driver = $this->driver([new JsonMockResponse(['id' => 12, 'name' => 'Mail'])]);

        $id = $driver->createDestination($this->integration(), null, 'Mail');

        self::assertSame('12', $id);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertStringEndsWith('/api/tags/', $this->requests[0]['url']);
    }

    public function testShareLinkIsAlwaysNullAndIsNotAdvertised(): void
    {
        // Paperless can share a document, but only through a permissioned
        // feature with its own expiry — publishing a filed document is a bigger
        // act than attaching one, so the capability is not claimed and the
        // picker never offers "insert link".
        self::assertFalse(Provider::Paperless->supports(Capability::ShareLink));
        self::assertNull($this->driver([])->shareLink($this->integration(), '91'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function scratchFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'paperless');

        self::assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }

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
    private function driver(array $responses): PaperlessDriver
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): ResponseInterface {
            // Drained here rather than at assertion time: the client consumes a
            // streamed body on its way out, so reading it later always yields an
            // empty string.
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

        return new PaperlessDriver($client, new IntegrationUrlValidator(), $configRepository);
    }

    private function integration(): Integration
    {
        $integration = new Integration(new User(), Provider::Paperless, 'Documents');
        $integration->baseUrl = 'https://paperless.example.com';
        $integration->secret = 'paperless-token';

        return $integration;
    }
}
