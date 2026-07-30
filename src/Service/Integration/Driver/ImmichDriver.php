<?php

declare(strict_types=1);

namespace App\Service\Integration\Driver;

use App\Domain\DTO\Integration\Entry;
use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\DTO\Integration\TimelineBucket;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Domain\Interface\IntegrationDriverInterface;
use App\Domain\Interface\SearchableDriverInterface;
use App\Domain\Interface\TimelineDriverInterface;
use App\Entity\Integration\Integration;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Service\Integration\IntegrationUrlValidator;
use DateTimeImmutable;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Immich, the self-hosted photo library.
 *
 * A photo library has no directory tree, so the landing view is the library
 * itself — every asset, newest first — with albums offered as a sideways jump
 * rather than as the way in. Browsing albums only was the original design and it
 * was too narrow: most photos a person wants to attach were never filed into
 * one, so the picker looked like it held nothing.
 *
 * Search goes through Immich's own smart search, which is CLIP-based and takes
 * natural language ("beach at sunset"). When the ML service is not running that
 * endpoint fails, so it falls back to metadata search on the filename — a
 * worse search, but a working one, and the picker never has to know.
 *
 * Immich cannot make a public URL for a single asset — sharing is album-level
 * and creating an album as a side effect of attaching a photo would be a far
 * bigger action than the user asked for. So Provider::Immich omits ShareLink
 * and shareLink() returns null; the picker offers "attach a copy" only.
 *
 * Sizes come from exifInfo, which Immich omits until it has processed an asset.
 * A null size therefore means "not known yet", and the picker treats it as
 * attachable — the attach endpoint re-checks the real size once the bytes are
 * in hand. Treating unknown as oversize, which is what this driver did first,
 * made every photo unselectable.
 *
 * Auth is an API key in x-api-key, generated per user in Immich's account
 * settings. Entry ids are Immich's own UUIDs, opaque to everything else.
 */
final readonly class ImmichDriver implements IntegrationDriverInterface, SearchableDriverInterface, TimelineDriverInterface
{
    private const string API = '/api';

    /**
     * Immich renders two preview sizes; "thumbnail" is the small square one
     * the picker grid wants.
     */
    private const string THUMBNAIL_SIZE = 'thumbnail';

    /**
     * Virtual folder ids. Immich album ids are UUIDs, so bare words cannot
     * collide with one.
     */
    private const string TIMELINE = 'timeline';
    private const string ALBUMS = 'albums';
    private const string PEOPLE = 'people';

    /**
     * Prefix marking a folder id as a person rather than an album — both are
     * UUIDs, so without it the two are indistinguishable.
     */
    private const string PERSON_PREFIX = 'person:';

    /**
     * Separator in a paging cursor: "<iso>@<page>" pages within a date-anchored
     * query, a bare number pages the unanchored one. The anchor has to travel
     * with the page or the second page of a scrubber jump would silently come
     * from the top of the library.
     */
    private const string CURSOR_SEPARATOR = '@';

    /** Assets per page, for both the timeline and search results. */
    private const int PAGE_SIZE = 100;

    public function __construct(
        private HttpClientInterface                 $httpClient,
        private IntegrationUrlValidator             $urlValidator,
        private IntegrationProviderConfigRepository $configRepository,
    ) {
    }

    public function supports(Provider $provider): bool
    {
        return Provider::Immich === $provider;
    }

    public function verify(Integration $integration): void
    {
        // Cheapest endpoint that proves both the address and the key.
        $this->get($integration, '/users/me');
    }

    public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        // The library itself is the landing view. Albums are a sideways jump
        // from it, not the way in — most photos worth attaching were never
        // filed into one, and opening on a list of albums made the picker look
        // like it held nothing.
        if (null === $folderId || '' === $folderId || self::TIMELINE === $folderId) {
            return $this->timeline($integration, $cursor);
        }

        if (self::ALBUMS === $folderId) {
            return $this->albumList($integration);
        }

        if (self::PEOPLE === $folderId) {
            return $this->peopleList($integration, null);
        }

        if (true === str_starts_with($folderId, self::PERSON_PREFIX)) {
            return $this->personAssets($integration, substr($folderId, strlen(self::PERSON_PREFIX)), $cursor);
        }

        return $this->albumAssets($integration, $folderId, $cursor);
    }

    /**
     * Immich's own search.
     *
     * Smart search first, because that is what people actually want from Immich
     * — it understands "dog on a beach" with none of those words in a filename.
     * It needs the machine-learning service, which is optional in an Immich
     * deployment, so a failure falls back to metadata search on the filename:
     * a worse search, but a working one, and the picker never has to know.
     */
    public function search(
        Integration $integration,
        string $query,
        ?string $folderId = null,
        ?string $cursor = null,
    ): Listing {
        $query = trim($query);

        // The people view's box filters faces by name; everywhere else it
        // searches photos. One box, two meanings, decided by where the user is.
        if (self::PEOPLE === $folderId) {
            return $this->peopleList($integration, '' === $query ? null : $query);
        }

        if ('' === $query) {
            return $this->timeline($integration, null);
        }

        [$anchor, $page] = $this->parseCursor($cursor);

        try {
            $payload = $this->json($integration, 'POST', $this->url($integration, '/search/smart'), [
                'json' => $this->searchBody(['query' => $query], $anchor, $page),
            ]);
        } catch (IntegrationException) {
            $payload = $this->json($integration, 'POST', $this->url($integration, '/search/metadata'), [
                'json' => $this->searchBody(['originalFileName' => $query], $anchor, $page),
            ]);
        }

        return $this->fromAssetsPayload(
            $payload,
            [
                Entry::folder(self::TIMELINE, 'All photos'),
                // The trail names the search rather than a location, since
                // results cross every album.
                Entry::folder('', sprintf('“%s”', $query)),
            ],
            $this->shortcuts(),
            $anchor,
        );
    }

    /**
     * Newest-first month buckets for the scrubber.
     *
     * One call, and a failure is an empty list rather than an exception: a
     * missing scrubber is a cosmetic loss, and taking the whole picker down over
     * it would trade a small feature for the main one.
     *
     * @return list<TimelineBucket>
     */
    public function timelineBuckets(Integration $integration): array
    {
        try {
            $raw = $this->get($integration, '/timeline/buckets', ['size' => 'month']);
        } catch (IntegrationException) {
            return [];
        }

        $buckets = [];

        foreach ($raw as $bucket) {
            if (false === is_array($bucket)) {
                continue;
            }

            $at = $this->parseDate($bucket['timeBucket'] ?? null);
            $count = $this->intOrNull($bucket['count'] ?? null);

            if (null === $at || null === $count || 0 === $count) {
                continue;
            }

            $buckets[] = new TimelineBucket(
                cursor: $this->buildCursor($at->format('Y-m-d'), 1),
                // The bar is only a few pixels wide, so the segment label is the
                // year and the month is left to the hover readout.
                label: $at->format('Y'),
                count: $count,
                title: $at->format('F Y'),
            );
        }

        return $buckets;
    }

    public function download(Integration $integration, string $fileId): RemoteFile
    {
        $response = $this->request($integration, 'GET', $this->url($integration, '/assets/'.rawurlencode($fileId).'/original'));

        try {
            $contents = $response->getContent();
            $headers = $response->getHeaders();
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        return new RemoteFile(
            filename: $this->filenameFromHeaders($headers) ?? $fileId,
            mime: trim(explode(';', $headers['content-type'][0] ?? 'application/octet-stream')[0]),
            contents: $contents,
        );
    }

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

        $stat = @stat($absolutePath);
        $modified = (new DateTimeImmutable())->setTimestamp(
            false === $stat ? time() : (int) $stat['mtime'],
        )->format(DATE_ATOM);

        // deviceAssetId is Immich's dedup key: uploading the same file twice
        // under the same id returns the existing asset instead of a duplicate.
        // Keying it on the content hash means re-running a filter over the same
        // mail does not fill the library with copies.
        $deviceAssetId = 'plmail-'.hash_file('sha256', $absolutePath);

        $formFields = [
            'deviceAssetId'   => $deviceAssetId,
            'deviceId'        => 'plmail',
            'fileCreatedAt'   => $modified,
            'fileModifiedAt'  => $modified,
            'assetData'       => new DataPart(new File($absolutePath), $filename, $mime),
        ];

        $form = new FormDataPart($formFields);

        $response = $this->request($integration, 'POST', $this->url($integration, '/assets'), [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body'    => $form->bodyToIterable(),
        ]);

        try {
            $payload = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        $id = $payload['id'] ?? null;

        if (false === is_string($id) || '' === $id) {
            throw new IntegrationException('Immich accepted the upload but did not return an asset id.');
        }

        if (null !== $folderId && '' !== $folderId) {
            $this->addToAlbum($integration, $folderId, $id);
        }

        return $id;
    }

    /**
     * Always null — see the class docblock. Provider::Immich does not declare
     * ShareLink, so nothing should be calling this.
     */
    public function shareLink(Integration $integration, string $fileId): ?string
    {
        return null;
    }

    public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
    {
        // People and assets both arrive here, since both are rendered with a
        // preview; they live on different endpoints.
        [$url, $query] = true === str_starts_with($fileId, self::PERSON_PREFIX)
            ? [
                $this->url($integration, '/people/'.rawurlencode(substr($fileId, strlen(self::PERSON_PREFIX))).'/thumbnail'),
                [],
            ]
            : [
                $this->url($integration, '/assets/'.rawurlencode($fileId).'/thumbnail'),
                ['size' => self::THUMBNAIL_SIZE],
            ];

        $response = $this->request($integration, 'GET', $url, ['query' => $query], false);

        try {
            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $contents = $response->getContent(false);
            $mime = $response->getHeaders(false)['content-type'][0] ?? 'image/jpeg';
        } catch (HttpExceptionInterface) {
            return null;
        }

        if ('' === $contents) {
            return null;
        }

        return new RemoteFile($fileId, trim(explode(';', $mime)[0]), $contents);
    }

    // ── Listings ──────────────────────────────────────────────────────────────

    /** Every album, as folders to descend into. */
    private function albumList(Integration $integration): Listing
    {
        return new Listing(
            $this->albums($integration),
            [...$this->breadcrumb(), Entry::folder(self::ALBUMS, 'Albums')],
        );
    }

    /**
     * Every asset, newest first.
     *
     * Uses metadata search with no filter rather than a timeline endpoint:
     * search returns plain paged assets with exifInfo attached, which is exactly
     * the shape the picker needs, and it is the same code path search results
     * come back through.
     */
    private function timeline(Integration $integration, ?string $cursor): Listing
    {
        [$anchor, $page] = $this->parseCursor($cursor);

        $payload = $this->json($integration, 'POST', $this->url($integration, '/search/metadata'), [
            'json' => $this->searchBody([], $anchor, $page),
        ]);

        return $this->fromAssetsPayload(
            $payload,
            [Entry::folder(self::TIMELINE, 'All photos')],
            $this->shortcuts(),
            $anchor,
        );
    }

    /**
     * Every recognised face, optionally filtered by name.
     *
     * Immich exposes two endpoints here: /people lists them, /search/person
     * filters them. Both come back as portraits with a name that may be empty —
     * an unnamed face is still worth browsing, it just shows as "Unnamed".
     */
    private function peopleList(Integration $integration, ?string $nameFilter): Listing
    {
        if (null === $nameFilter) {
            $payload = $this->get($integration, '/people', ['withHidden' => 'false']);
            $items = $payload['people'] ?? [];
        } else {
            // This endpoint answers a bare array rather than an envelope.
            $items = $this->get($integration, '/search/person', [
                'name'       => $nameFilter,
                'withHidden' => 'false',
            ]);
        }

        $entries = [];

        if (true === is_array($items)) {
            foreach ($items as $person) {
                if (false === is_array($person)) {
                    continue;
                }

                $id = $this->stringOrNull($person['id'] ?? null);

                if (null === $id) {
                    continue;
                }

                $entries[] = Entry::person(
                    self::PERSON_PREFIX.$id,
                    $this->stringOrNull($person['name'] ?? null) ?? 'Unnamed',
                );
            }
        }

        return new Listing(
            $entries,
            [...$this->breadcrumb(), Entry::folder(self::PEOPLE, 'People')],
            null,
            $this->shortcuts(),
        );
    }

    /** Everything Immich recognised this person in. */
    private function personAssets(Integration $integration, string $personId, ?string $cursor): Listing
    {
        [$anchor, $page] = $this->parseCursor($cursor);

        $payload = $this->json($integration, 'POST', $this->url($integration, '/search/metadata'), [
            'json' => $this->searchBody(['personIds' => [$personId]], $anchor, $page),
        ]);

        return $this->fromAssetsPayload(
            $payload,
            [
                ...$this->breadcrumb(),
                Entry::folder(self::PEOPLE, 'People'),
                Entry::folder(self::PERSON_PREFIX.$personId, $this->personName($integration, $personId)),
            ],
            $this->shortcuts(),
            $anchor,
        );
    }

    private function personName(Integration $integration, string $personId): string
    {
        try {
            $person = $this->get($integration, '/people/'.rawurlencode($personId));
        } catch (IntegrationException) {
            return 'Person';
        }

        return $this->stringOrNull($person['name'] ?? null) ?? 'Unnamed';
    }

    /**
     * The sideways jumps offered from any asset view. Albums and people are both
     * ways of slicing the same library, so neither is a parent of the other.
     *
     * @return list<Entry>
     */
    private function shortcuts(): array
    {
        return [
            Entry::folder(self::ALBUMS, 'Albums'),
            Entry::folder(self::PEOPLE, 'People'),
        ];
    }

    /**
     * Turn a search/metadata or search/smart response into a listing.
     *
     * Both wrap their results in `assets: { items, nextPage }`. Paging is by
     * page number, so the cursor is just the next page — Immich reports
     * nextPage itself, and its absence is the end.
     *
     * The anchor is threaded back through so the next cursor keeps it: without
     * that, page two of a scrubber jump would quietly come from the top of the
     * library instead of from where the user landed.
     *
     * @param array<mixed> $payload
     * @param list<Entry>  $breadcrumb
     * @param list<Entry>  $shortcuts
     */
    private function fromAssetsPayload(
        array $payload,
        array $breadcrumb,
        array $shortcuts = [],
        ?string $anchor = null,
    ): Listing
    {
        $bucket = $payload['assets'] ?? [];
        $items = is_array($bucket) ? ($bucket['items'] ?? []) : [];
        $entries = [];

        if (true === is_array($items)) {
            foreach ($items as $asset) {
                if (true === is_array($asset)) {
                    $entry = $this->asset($asset);

                    if (null !== $entry) {
                        $entries[] = $entry;
                    }
                }
            }
        }

        $next = is_array($bucket) ? ($bucket['nextPage'] ?? null) : null;
        $nextPage = $this->intOrNull($next);

        return new Listing(
            $entries,
            $breadcrumb,
            null === $nextPage ? null : $this->buildCursor($anchor, $nextPage),
            $shortcuts,
        );
    }

    /**
     * One asset as a picker entry, or null if it has no usable id.
     *
     * @param array<string,mixed> $asset
     */
    private function asset(array $asset): ?Entry
    {
        $id = $this->stringOrNull($asset['id'] ?? null);

        if (null === $id) {
            return null;
        }

        return new Entry(
            id: $id,
            name: (string) ($asset['originalFileName'] ?? $id),
            isFolder: false,
            // Immich keeps size under exifInfo and omits it until it has
            // processed the asset. Null means "not known yet"; the picker treats
            // that as attachable and the attach endpoint checks the real size
            // once the bytes are in hand.
            size: $this->intOrNull($asset['exifInfo']['fileSizeInByte'] ?? null),
            mime: $this->stringOrNull($asset['originalMimeType'] ?? null),
            modifiedAt: $this->parseDate($asset['fileCreatedAt'] ?? null),
        );
    }

    /** @return list<Entry> */
    private function albums(Integration $integration): array
    {
        $albums = [];

        foreach ($this->get($integration, '/albums') as $album) {
            if (false === is_array($album)) {
                continue;
            }

            $id = (string) ($album['id'] ?? '');

            if ('' === $id) {
                continue;
            }

            $albums[] = new Entry(
                id: $id,
                name: (string) ($album['albumName'] ?? 'Album'),
                isFolder: true,
                size: null,
                mime: null,
                modifiedAt: $this->parseDate($album['updatedAt'] ?? null),
            );
        }

        usort($albums, static fn (Entry $a, Entry $b): int => strnatcasecmp($a->name, $b->name));

        return $albums;
    }

    /**
     * One album's contents.
     *
     * Goes through metadata search filtered by album rather than reading the
     * album endpoint's embedded assets: search pages, carries exifInfo, and
     * shares the parsing with the timeline. The album endpoint returns every
     * asset in one response, which is fine for a holiday album and not fine for
     * one with ten thousand photos in it.
     */
    private function albumAssets(Integration $integration, string $albumId, ?string $cursor): Listing
    {
        [$anchor, $page] = $this->parseCursor($cursor);

        $payload = $this->json($integration, 'POST', $this->url($integration, '/search/metadata'), [
            'json' => $this->searchBody(['albumIds' => [$albumId]], $anchor, $page),
        ]);

        return $this->fromAssetsPayload(
            $payload,
            [
                ...$this->breadcrumb(),
                Entry::folder(self::ALBUMS, 'Albums'),
                Entry::folder($albumId, $this->albumName($integration, $albumId)),
            ],
            $this->shortcuts(),
            $anchor,
        );
    }

    /**
     * An album's title. Requested without its assets — this is only for the
     * breadcrumb, and the contents come from search.
     */
    private function albumName(Integration $integration, string $albumId): string
    {
        try {
            $album = $this->get($integration, '/albums/'.rawurlencode($albumId), ['withoutAssets' => 'true']);
        } catch (IntegrationException) {
            return 'Album';
        }

        return (string) ($album['albumName'] ?? 'Album');
    }

    /**
     * A search body with the paging and ordering every asset view shares.
     *
     * takenBefore is how a scrubber jump works: rather than paging forward until
     * 2019 appears, the query starts there. It costs nothing extra — Immich
     * filters server-side — and it is why jumping to an unloaded month is
     * instant.
     *
     * @param array<string,mixed> $filters
     *
     * @return array<string,mixed>
     */
    private function searchBody(array $filters, ?string $anchor, int $page): array
    {
        $body = $filters + [
            'size'  => self::PAGE_SIZE,
            'page'  => $page,
            'order' => 'desc',
        ];

        if (null !== $anchor) {
            // End of that day, so the anchored month is included rather than
            // skipped by an off-by-one at midnight.
            $body['takenBefore'] = $anchor.'T23:59:59.999Z';
        }

        return $body;
    }

    /**
     * Split a cursor into its date anchor and page.
     *
     * Anything unparseable starts from the beginning rather than failing — a
     * hand-edited URL should not produce a 500.
     *
     * @return array{0: ?string, 1: int}
     */
    private function parseCursor(?string $cursor): array
    {
        if (null === $cursor || '' === $cursor) {
            return [null, 1];
        }

        if (true === ctype_digit($cursor)) {
            return [null, max(1, (int) $cursor)];
        }

        $parts = explode(self::CURSOR_SEPARATOR, $cursor, 2);
        $anchor = $parts[0];
        $page = isset($parts[1]) && ctype_digit($parts[1]) ? max(1, (int) $parts[1]) : 1;

        // Only a plain date is accepted; the value is interpolated into a query.
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) {
            return [null, 1];
        }

        return [$anchor, $page];
    }

    private function buildCursor(?string $anchor, int $page): string
    {
        return null === $anchor
            ? (string) $page
            : $anchor.self::CURSOR_SEPARATOR.$page;
    }

    private function addToAlbum(Integration $integration, string $albumId, string $assetId): void
    {
        // Best effort: the asset is already safely in the library, so failing
        // to file it under an album is not worth losing the upload over.
        $this->request(
            $integration,
            'PUT',
            $this->url($integration, '/albums/'.rawurlencode($albumId).'/assets'),
            ['json' => ['ids' => [$assetId]]],
            false,
        )->getStatusCode();
    }

    /** @return list<Entry> */
    private function breadcrumb(): array
    {
        return [Entry::folder('', 'Immich')];
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    /**
     * Any request whose response is JSON. The search endpoints are POSTs, so
     * get() alone would not cover them.
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
            throw new IntegrationException('This Immich connection is missing its API key.');
        }

        $options['headers'] = array_merge(
            ['x-api-key' => $integration->secret, 'Accept' => 'application/json'],
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
            401 === $status || 403 === $status => 'Immich rejected the API key.',
            404 === $status                    => 'Immich could not find that album or photo.',
            $status >= 500                     => 'The Immich server reported an error.',
            0 === $status                      => 'Could not reach the Immich server. Check the address.',
            default                            => sprintf('Immich returned an unexpected response (%d).', $status),
        };
    }

    private function url(Integration $integration, string $path): string
    {
        $base = $this->urlValidator->resolve(
            $integration,
            $this->configRepository->findOneByProvider(Provider::Immich),
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
