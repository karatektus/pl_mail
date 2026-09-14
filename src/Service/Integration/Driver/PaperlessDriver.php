<?php

declare(strict_types=1);

namespace App\Service\Integration\Driver;

use App\Domain\DTO\Integration\Entry;
use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Domain\Interface\DestinationDriverInterface;
use App\Domain\Interface\IntegrationDriverInterface;
use App\Domain\Interface\SearchableDriverInterface;
use App\Entity\Integration\Integration;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Service\Integration\IntegrationUrlValidator;
use DateTimeImmutable;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Paperless-ngx, the self-hosted document archive.
 *
 * A document archive has no directory tree at all — Paperless deliberately
 * replaced folders with tags, a correspondent and a document type, and expects
 * everything else to be found by searching. That leaves the picker, whose whole
 * vocabulary is "descend into a container, pick a file", with nothing to descend
 * into, so **tags are the container layer**: the root lists every tag as a
 * folder and then the newest documents beneath them, and opening a tag lists
 * that tag's documents. The alternative was one flat, endless list of every
 * document in the archive, which is a scroll bar rather than a selector.
 *
 * Correspondents and document types would work as containers on exactly the same
 * argument, and are deliberately not offered: three sideways slices of the same
 * archive is a navigation problem, and full-text search already crosses all
 * three. Tags win because they are the one axis a person files *deliberately*.
 *
 * Search is Paperless's own full-text index (`?query=`), which reads the OCR
 * layer as well as the metadata — so a scanned invoice is findable by a line
 * item nobody ever typed. That is the point of the integration: the user should
 * never have to open Paperless, find a document and download it before they can
 * attach it. Inside a tag the query stays scoped to that tag.
 *
 * Paperless has no per-document public URL that can be minted as cheaply as
 * attaching should be — sharing is a separate, permissioned feature with its own
 * expiry semantics — so Provider::Paperless omits ShareLink and shareLink()
 * returns null. The picker offers "attach a copy" only.
 *
 * Auth is a per-user API token in `Authorization: Token …`, generated in
 * Paperless under the user's profile. Like Immich's key it identifies the user
 * on its own, so no username is asked for.
 *
 * Entry ids are Paperless's own integer primary keys as strings: a tag id where
 * a folder is expected, a document id where a file is. The two never share an
 * argument — list() is only ever handed a tag, download() only ever a document —
 * so neither needs a prefix to tell it from the other. The one id that *is*
 * prefixed is the one upload() hands back; see there for why.
 */
final readonly class PaperlessDriver implements IntegrationDriverInterface, SearchableDriverInterface, DestinationDriverInterface
{
    private const string API = '/api';

    /**
     * Documents per page, for browsing and for search results alike.
     *
     * Smaller than Immich's hundred because these are rows with titles rather
     * than a thumbnail grid: a hundred of them is a wall of text, and the
     * picker appends the next page as the sentinel scrolls into view anyway.
     */
    private const int PAGE_SIZE = 50;

    /**
     * Ceiling on the tag list.
     *
     * The root shows tags as folders, so this is how many containers the user
     * can be offered at once — and a picker listing a thousand of them is not a
     * picker. Paperless installs run to tens of tags, not thousands; if one
     * genuinely exceeds this, search is the way through the archive and the tag
     * rows were never going to be how that user worked.
     */
    private const int TAG_LIMIT = 200;

    /** Newest first, which is how an archive reads when nothing is being sought. */
    private const string ORDERING = '-created';

    /**
     * Marks the id upload() returns as a *task*, not a document.
     *
     * post_document/ is asynchronous: it answers with the id of the consumer
     * task that will ingest the file, and the document only exists once that
     * task has run — seconds later, or never, if Paperless rejects the file as a
     * duplicate. There is no document id to return at the moment upload()
     * returns, and no amount of waiting makes one appear reliably.
     *
     * Returning the task id bare would hand the caller something that looks
     * exactly like a document id and is not one: a later download() would ask
     * for document "3f2a…" and get a 404, or worse, a coincidence. Returning an
     * empty string would be a lie of a different kind — the upload did happen.
     *
     * So the id says what it is. Nothing outside this driver parses an entry id,
     * which is precisely what makes the prefix safe; and download() and
     * thumbnail() recognise it and refuse it with a message a person can act on,
     * rather than passing a task id off as a document. The invariant is
     * structural rather than a comment asking nobody to try.
     *
     * Document ids are integers, so this can never collide with one.
     */
    private const string TASK_PREFIX = 'task:';

    public function __construct(
        private HttpClientInterface                 $httpClient,
        private IntegrationUrlValidator             $urlValidator,
        private IntegrationProviderConfigRepository $configRepository,
    ) {
    }

    public function supports(Provider $provider): bool
    {
        return Provider::Paperless === $provider;
    }

    public function verify(Integration $integration): void
    {
        // One document, which proves the address, the token and the API version
        // in a single call. Not /api/ui_settings/ or /api/profile/, which are
        // newer and would make an older Paperless look unreachable when it is
        // merely older.
        $this->get($integration, '/documents/', ['page_size' => 1]);
    }

    public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        $tagId = $this->tagFrom($folderId);

        if (null === $tagId) {
            // The root is the tag list followed by the newest documents, so the
            // archive is browsable and its containers visible in one view. The
            // tags ride on the first page only: they are the same set on every
            // page, and repeating them mid-scroll would read as duplicates
            // because the picker appends pages rather than replacing them.
            return $this->documents(
                $integration,
                null,
                null,
                $cursor,
                [$this->root()],
                1 === $this->page($cursor),
            );
        }

        return $this->documents($integration, $tagId, null, $cursor, [
            $this->root(),
            Entry::folder($tagId, $this->tagName($integration, $tagId)),
        ], false);
    }

    /**
     * Paperless's full-text search.
     *
     * `query` runs against the Whoosh index, which holds the OCR text as well as
     * the title, correspondent and tags — so a scanned receipt is findable by
     * something printed on it. Relevance order comes from the index, so no
     * explicit ordering is sent: asking for newest-first would throw away the
     * ranking that makes the search worth having.
     *
     * A query launched from inside a tag stays inside it. That is the same
     * decision SearchableDriverInterface describes for Immich's people view —
     * the box means "search here" when the user is somewhere, and "search
     * everything" when they are not.
     */
    public function search(
        Integration $integration,
        string $query,
        ?string $folderId = null,
        ?string $cursor = null,
    ): Listing {
        $query = trim($query);

        // Clearing the box is how a user leaves a search, so an empty one goes
        // back to where they were rather than searching for nothing.
        if ('' === $query) {
            return $this->list($integration, $folderId);
        }

        $tagId = $this->tagFrom($folderId);
        $breadcrumb = [$this->root()];

        if (null !== $tagId) {
            $breadcrumb[] = Entry::folder($tagId, $this->tagName($integration, $tagId));
        }

        // The last crumb names the search rather than a place, since hits cross
        // everything the scope still allows.
        $breadcrumb[] = Entry::folder('', sprintf('“%s”', $query));

        return $this->documents($integration, $tagId, $query, $cursor, $breadcrumb, false);
    }

    public function download(Integration $integration, string $fileId): RemoteFile
    {
        $id = $this->documentFrom($fileId);

        // No ?original=true: /download/ answers with the archived PDF where
        // Paperless made one and falls back to the original by itself where it
        // could not. That is the copy the user saw when they filed the document
        // — searchable, with the OCR layer baked in — and asking for the
        // original would attach the unreadable scan instead.
        $response = $this->request($integration, 'GET', $this->url($integration, '/documents/'.$id.'/download/'));

        try {
            $contents = $response->getContent();
            $headers = $response->getHeaders();
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        return new RemoteFile(
            // Paperless sends the filename in Content-Disposition; the metadata
            // lookup behind it costs a round trip and only runs when a server or
            // a proxy has stripped the header. An attachment called "42" is a
            // bug report, so the fallback is worth the call it almost never
            // makes.
            filename: $this->filenameFromHeaders($headers) ?? $this->documentName($integration, $id),
            mime: trim(explode(';', $headers['content-type'][0] ?? 'application/octet-stream')[0]),
            contents: $contents,
        );
    }

    /**
     * Hand a file to Paperless's consumer, and return the *task* id it answers
     * with — prefixed, because it is not a document id. See TASK_PREFIX.
     */
    public function upload(
        Integration $integration,
        string $absolutePath,
        string $filename,
        string $mime,
        ?string $folderId = null,
    ): string {
        if (false === is_readable($absolutePath)) {
            throw new IntegrationException(sprintf('Cannot read %s to upload.', $filename));
        }

        // A list of single-entry arrays rather than one map, because that is the
        // only shape FormDataPart turns into repeated fields of the same name:
        // a nested array under a string key would be serialised as "tags[0]",
        // which Paperless reads as a field it does not have rather than as a tag.
        $fields = [
            ['document' => new DataPart(new File($absolutePath), $filename, $mime)],
            ['title'    => $this->titleFor($filename)],
        ];

        $tagId = $this->tagFrom($folderId);

        if (null !== $tagId) {
            $fields[] = ['tags' => $tagId];
        }

        $form = new FormDataPart($fields);

        $response = $this->request($integration, 'POST', $this->url($integration, '/documents/post_document/'), [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body'    => $form->bodyToIterable(),
        ]);

        return self::TASK_PREFIX.$this->taskId($response);
    }

    // ── Destinations ────────────────────────────────────────────────────────────

    /**
     * An archive with no folders files a save under a tag, so the containers a
     * save may target are the tag list — flat, and without the documents list()
     * mixes in beside them. $folderId and $cursor are ignored for the same
     * reason Immich ignores them: there is nothing to descend into and one page
     * holds the lot.
     */
    public function destinations(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        return new Listing($this->tags($integration), [$this->root()]);
    }

    /**
     * A tag id arrives in a request, so it is confirmed against this account's
     * own tags before a byte is uploaded.
     *
     * Two things are being refused, and they are different. Anything that is not
     * a plain integer never reaches a URL at all — the id is interpolated into
     * one, so a traversal attempt is stopped by shape before it is stopped by
     * lookup. And an integer that Paperless does not answer for is refused as
     * well: the token is this user's, so a foreign tag could never be written to
     * in any case, but "save under this tag" that silently files under none is a
     * lie to the user, and worth one round trip to avoid telling.
     *
     * The empty string is "no tag" — Paperless's own inbox, which is where an
     * unfiled document is supposed to land — and is always valid.
     */
    public function assertDestination(Integration $integration, string $destination): void
    {
        if ('' === $destination) {
            return;
        }

        $id = $this->tagFrom($destination);

        if (null === $id) {
            throw new IntegrationException('That tag is not in this Paperless account.');
        }

        try {
            $this->get($integration, '/tags/'.$id.'/');
        } catch (IntegrationException $e) {
            // Paperless answers 404 both for a tag that never existed and for
            // one this token may not see, which is the same answer for our
            // purposes. Anything else — a dead server, a revoked token — is a
            // different problem and keeps its own message.
            if (404 === $e->getStatus()) {
                throw new IntegrationException('That tag is not in this Paperless account.', $e->getStatus(), $e);
            }

            throw $e;
        }
    }

    /** Create a tag and return its id. A flat list, so $parent is ignored. */
    public function createDestination(Integration $integration, ?string $parent, string $name): string
    {
        $name = trim($name);

        if ('' === $name) {
            throw new IntegrationException('A tag needs a name.');
        }

        $payload = $this->json($integration, 'POST', $this->url($integration, '/tags/'), [
            'json' => ['name' => $name],
        ]);

        $id = $this->intOrNull($payload['id'] ?? null);

        if (null === $id) {
            throw new IntegrationException('Paperless created the tag but did not return its id.');
        }

        return (string) $id;
    }

    /**
     * Always null — see the class docblock. Provider::Paperless does not declare
     * ShareLink, so nothing should be calling this.
     */
    public function shareLink(Integration $integration, string $fileId): ?string
    {
        return null;
    }

    public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
    {
        $id = $this->intOrNull($fileId);

        // A task id, or anything else that is not a document, has no preview —
        // and "no preview" is a null here rather than an exception, which is
        // what the interface asks for and what the picker renders a placeholder
        // from.
        if (null === $id) {
            return null;
        }

        $response = $this->request($integration, 'GET', $this->url($integration, '/documents/'.$id.'/thumb/'), [], false);

        try {
            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $contents = $response->getContent(false);
            $mime = $response->getHeaders(false)['content-type'][0] ?? 'image/png';
        } catch (HttpExceptionInterface) {
            return null;
        }

        if ('' === $contents) {
            return null;
        }

        return new RemoteFile((string) $id, trim(explode(';', $mime)[0]), $contents);
    }

    // ── Listings ──────────────────────────────────────────────────────────────

    /**
     * One page of documents, optionally scoped to a tag and optionally filtered
     * by a full-text query, with the tag list in front of it at the root.
     *
     * The one place documents are turned into entries, so browsing a tag,
     * reading the root and searching all agree about what a row looks like.
     *
     * @param list<Entry> $breadcrumb
     * @param bool        $withTags   put this account's tags in front of the
     *                                documents, as containers to descend into
     */
    private function documents(
        Integration $integration,
        ?string $tagId,
        ?string $query,
        ?string $cursor,
        array $breadcrumb,
        bool $withTags,
    ): Listing {
        $page = $this->page($cursor);

        $parameters = [
            'page'      => $page,
            'page_size' => self::PAGE_SIZE,
        ];

        if (null !== $tagId) {
            $parameters['tags__id__all'] = $tagId;
        }

        if (null === $query) {
            $parameters['ordering'] = self::ORDERING;
        } else {
            // No ordering alongside a query: the index ranks the hits, and
            // re-sorting them by date would discard the ranking.
            $parameters['query'] = $query;
        }

        // Tags first, because they are what the listing puts first; the reading
        // order of the two calls and the reading order of the rows agree.
        $entries = true === $withTags ? $this->tags($integration) : [];

        $payload = $this->get($integration, '/documents/', $parameters);
        $results = $payload['results'] ?? [];

        if (true === is_array($results)) {
            foreach ($results as $document) {
                if (false === is_array($document)) {
                    continue;
                }

                $entry = $this->document($document);

                if (null !== $entry) {
                    $entries[] = $entry;
                }
            }
        }

        // `next` is a full URL Paperless built from its own notion of where it
        // lives, which is not necessarily where we are allowed to talk to it —
        // behind a reverse proxy it is routinely the internal address. So the
        // cursor is the page number and the URL is rebuilt through
        // IntegrationUrlValidator like every other request; `next` is read only
        // for whether there IS another page.
        $next = $payload['next'] ?? null;

        return new Listing(
            $entries,
            $breadcrumb,
            true === is_string($next) && '' !== $next ? (string) ($page + 1) : null,
        );
    }

    /**
     * Every tag, as folders to descend into.
     *
     * Sorted here rather than by asking Paperless for an ordering: the sort is
     * the same natural, case-insensitive one the other file drivers use for
     * their folders, so a tag list and a folder list read alike, and it holds
     * whatever a given Paperless version decides to do with an `ordering`
     * parameter it does not recognise.
     *
     * @return list<Entry>
     */
    private function tags(Integration $integration): array
    {
        $payload = $this->get($integration, '/tags/', ['page_size' => self::TAG_LIMIT]);
        $results = $payload['results'] ?? [];
        $tags = [];

        if (true === is_array($results)) {
            foreach ($results as $tag) {
                if (false === is_array($tag)) {
                    continue;
                }

                $id = $this->intOrNull($tag['id'] ?? null);

                if (null === $id) {
                    continue;
                }

                $tags[] = Entry::folder(
                    (string) $id,
                    $this->stringOrNull($tag['name'] ?? null) ?? sprintf('Tag %d', $id),
                );
            }
        }

        usort($tags, static fn (Entry $a, Entry $b): int => strnatcasecmp($a->name, $b->name));

        return $tags;
    }

    /**
     * One document as a picker entry, or null if it has no usable id.
     *
     * The name is the **title**, because that is what Paperless shows and what a
     * person recognises — the stored filename is generated from the title and a
     * checksum on most installs, so showing it would be showing the same thing
     * worse. The real filename still reaches the draft: download() takes it from
     * Content-Disposition, where Paperless puts the name it would serve.
     *
     * @param array<string,mixed> $document
     */
    private function document(array $document): ?Entry
    {
        $id = $this->intOrNull($document['id'] ?? null);

        if (null === $id) {
            return null;
        }

        $filename = $this->stringOrNull($document['archived_file_name'] ?? null)
            ?? $this->stringOrNull($document['original_file_name'] ?? null);

        return new Entry(
            id: (string) $id,
            name: $this->stringOrNull($document['title'] ?? null)
                ?? $filename
                ?? sprintf('Document %d', $id),
            isFolder: false,
            // Paperless reports no size on a document listing at all. Null is
            // "not known", which the picker treats as attachable and the attach
            // endpoint re-checks once it holds the bytes — the same reading
            // Immich's unprocessed assets get, and for the same reason: calling
            // unknown "oversize" would make every row unselectable.
            size: null,
            // mime_type only appeared in recent versions, so an older server
            // leaves the row's icon to be derived from the filename. A wrong
            // icon is cosmetic; an absent one on every row looks broken.
            mime: $this->stringOrNull($document['mime_type'] ?? null) ?? $this->mimeFor($filename),
            // `created` is the date on the document itself — the invoice date,
            // not the scan date — which is the one a person is looking for.
            // `added` is when Paperless saw it, and stands in when a version or
            // a document has no `created`.
            modifiedAt: $this->parseDate($document['created'] ?? $document['added'] ?? null),
        );
    }

    /**
     * A tag's name. Only for the breadcrumb, so a failure is the generic word
     * rather than an exception — the listing beside it succeeded, and taking
     * the whole view down for a label would be trading the feature for its
     * caption.
     */
    private function tagName(Integration $integration, string $tagId): string
    {
        try {
            $tag = $this->get($integration, '/tags/'.$tagId.'/');
        } catch (IntegrationException) {
            return 'Tag';
        }

        return $this->stringOrNull($tag['name'] ?? null) ?? 'Tag';
    }

    /**
     * The filename Paperless would serve for a document, for the rare download
     * whose response carried no Content-Disposition.
     */
    private function documentName(Integration $integration, string $id): string
    {
        try {
            $document = $this->get($integration, '/documents/'.$id.'/');
        } catch (IntegrationException) {
            return 'document-'.$id;
        }

        return $this->stringOrNull($document['archived_file_name'] ?? null)
            ?? $this->stringOrNull($document['original_file_name'] ?? null)
            ?? $this->stringOrNull($document['title'] ?? null)
            ?? 'document-'.$id;
    }

    /** The crumb every trail starts from, and the id that means "the archive". */
    private function root(): Entry
    {
        return Entry::folder('', 'Paperless');
    }

    // ── Ids and names ─────────────────────────────────────────────────────────

    /**
     * The tag a folder id names, or null for the root.
     *
     * Anything that is not a plain integer is the root rather than an error.
     * Folder ids reach here straight off a query string, so a hand-edited one is
     * a URL somebody typed rather than a fault worth recording against the
     * connection — and the root is the one answer that is always safe to give.
     */
    private function tagFrom(?string $folderId): ?string
    {
        if (null === $folderId || '' === $folderId) {
            return null;
        }

        $id = $this->intOrNull($folderId);

        return null === $id ? null : (string) $id;
    }

    /**
     * The document a file id names.
     *
     * Refuses rather than falling back, because there is no safe default for
     * "fetch this file": every alternative would attach some *other* document.
     * The task-id case gets its own sentence — it is the one failure a user can
     * actually do something about, by waiting and picking the document once
     * Paperless has filed it.
     */
    private function documentFrom(string $fileId): string
    {
        if (true === str_starts_with($fileId, self::TASK_PREFIX)) {
            throw new IntegrationException('Paperless is still filing that document, so it cannot be attached yet.');
        }

        $id = $this->intOrNull($fileId);

        if (null === $id) {
            throw new IntegrationException('That is not a Paperless document.');
        }

        return (string) $id;
    }

    /**
     * Page number from a cursor. Anything unparseable starts at the beginning
     * rather than failing — the cursor is in a URL a user can edit, and a 500
     * is a poor answer to a typo.
     */
    private function page(?string $cursor): int
    {
        if (null === $cursor || false === ctype_digit($cursor)) {
            return 1;
        }

        return max(1, (int) $cursor);
    }

    /**
     * The title a newly uploaded document gets.
     *
     * The filename without its extension. Paperless falls back to the filename
     * itself when no title is sent, so "invoice.pdf" would become a document
     * literally titled "invoice.pdf" — the extension is noise in a list of
     * titles, and stripping it is the whole difference.
     */
    private function titleFor(string $filename): string
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);

        return '' === $stem ? $filename : $stem;
    }

    /**
     * The consumer task id from a post_document/ response.
     *
     * Paperless answers with a bare JSON string — a quoted UUID, not an object —
     * so this reads the body rather than using toArray(), which would throw on
     * a payload that is perfectly valid and simply is not a map. Recent versions
     * wrap it, hence the second shape.
     */
    private function taskId(ResponseInterface $response): string
    {
        try {
            $body = $response->getContent();
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        $decoded = json_decode($body, true);

        $id = true === is_array($decoded)
            ? $decoded['task_id'] ?? null
            : $decoded;

        if (false === is_string($id) || '' === $id) {
            throw new IntegrationException('Paperless accepted the upload but did not say what it did with it.');
        }

        return $id;
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    /**
     * Any request whose response is a JSON object. Tag creation is a POST, so
     * get() alone would not cover it.
     *
     * @param array<string,mixed> $options
     *
     * @return array<mixed>
     *
     * @throws IntegrationException
     */
    private function json(Integration $integration, string $method, string $url, array $options = []): array
    {
        $response = $this->request($integration, $method, $url, $options);

        try {
            return $response->toArray();
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }
    }

    /**
     * @param array<string,scalar> $query
     *
     * @return array<mixed>
     */
    private function get(Integration $integration, string $path, array $query = []): array
    {
        $response = $this->request($integration, 'GET', $this->url($integration, $path), [
            'query' => $query,
        ]);

        try {
            return $response->toArray();
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }
    }

    /**
     * @param array<string,mixed> $options
     */
    private function request(
        Integration $integration,
        string $method,
        string $url,
        array $options = [],
        bool $throwOnError = true,
    ): ResponseInterface {
        if (null === $integration->secret || '' === $integration->secret) {
            throw new IntegrationException('This Paperless connection is missing its API token.');
        }

        $options['headers'] = array_merge(
            // "Token", not "Bearer": Paperless uses DRF's own token
            // authentication, which refuses a Bearer scheme outright.
            ['Authorization' => 'Token '.$integration->secret, 'Accept' => 'application/json'],
            $options['headers'] ?? [],
        );

        try {
            $response = $this->httpClient->request($method, $url, $options);

            if (true === $throwOnError) {
                $status = $response->getStatusCode();

                if ($status >= 400) {
                    throw new IntegrationException($this->messageForStatus($status), $status);
                }
            }

            return $response;
        } catch (IntegrationException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }
    }

    private function translate(HttpExceptionInterface $e): IntegrationException
    {
        $status = method_exists($e, 'getResponse') ? $e->getResponse()->getStatusCode() : 0;

        return new IntegrationException($this->messageForStatus($status), $status, $e);
    }

    private function messageForStatus(int $status): string
    {
        return match (true) {
            401 === $status || 403 === $status => 'Paperless rejected the API token.',
            404 === $status                    => 'Paperless could not find that tag or document.',
            // The consumer refuses a file it cannot parse, and says so with a
            // 400 on the upload endpoint. Worth its own sentence: it is the one
            // status here a user can fix, by sending a different file.
            400 === $status                    => 'Paperless would not accept that file.',
            413 === $status                    => 'That file is too large for the Paperless server.',
            $status >= 500                     => 'The Paperless server reported an error.',
            0 === $status                      => 'Could not reach the Paperless server. Check the address.',
            default                            => sprintf('Paperless returned an unexpected response (%d).', $status),
        };
    }

    private function url(Integration $integration, string $path): string
    {
        $base = $this->urlValidator->resolve(
            $integration,
            $this->configRepository->findOneByProvider(Provider::Paperless),
        );

        return $base.self::API.$path;
    }

    // ── Parsing ───────────────────────────────────────────────────────────────

    /**
     * @param array<string,list<string>> $headers
     */
    private function filenameFromHeaders(array $headers): ?string
    {
        $disposition = $headers['content-disposition'][0] ?? null;

        if (null === $disposition) {
            return null;
        }

        if (1 === preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $matches)) {
            return rawurldecode($matches[1]);
        }

        return null;
    }

    /**
     * The type an extension implies, for the servers that do not report one.
     *
     * Through Symfony's own table rather than a hand-written map here: the
     * picker only needs enough to choose a row icon, and a second copy of
     * "pdf means application/pdf" is a copy that can drift.
     */
    private function mimeFor(?string $filename): ?string
    {
        if (null === $filename) {
            return null;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        if ('' === $extension) {
            return null;
        }

        return MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? null;
    }

    private function parseDate(mixed $raw): ?DateTimeImmutable
    {
        if (false === is_string($raw) || '' === $raw) {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
