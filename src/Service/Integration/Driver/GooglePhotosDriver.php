<?php

declare(strict_types=1);

namespace App\Service\Integration\Driver;

use App\Domain\DTO\Integration\Entry;
use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Domain\Interface\DestinationDriverInterface;
use App\Entity\Integration\Integration;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * Google Photos, over the Photos Library API.
 *
 * Browsing is albums then their media items, like Immich — a photo library has
 * no directory tree.
 *
 * ── A caveat that belongs in the open ─────────────────────────────────────────
 * Google restricted the Photos Library read scopes in March 2025. An app newly
 * registered today is generally granted only appcreateddata, which sees
 * nothing but media the app itself uploaded; full-library reading needs the
 * photoslibrary.readonly scope, which Google grants case by case, or the
 * client-side Picker API, which is a browser widget and cannot be driven from
 * a server-rendered picker like ours.
 *
 * So this driver is complete and correct against the API, and on an app without
 * the readonly grant it will show an empty or near-empty library. That is a
 * Google policy limit, not a bug here — the admin tutorial says so, and an
 * empty listing is reported as empty rather than dressed up as an error.
 *
 * Uploading is unaffected: appendonly is granted freely, so save-to and the
 * filter action work regardless of the read situation.
 *
 * Entry ids are album ids for folders and mediaItem ids for files. Downloading
 * goes through baseUrl, a short-lived URL the API returns per item — it must be
 * fetched fresh, never stored.
 */
final class GooglePhotosDriver extends AbstractOAuthDriver implements DestinationDriverInterface
{
    private const string API = 'https://photoslibrary.googleapis.com/v1';

    /** Bytes are served from baseUrl with a size suffix; =d means original. */
    private const string ORIGINAL_SUFFIX = '=d';

    /** Long edge, in pixels, for a picker thumbnail. */
    private const int THUMBNAIL_EDGE = 256;

    public function supports(Provider $provider): bool
    {
        return Provider::GooglePhotos === $provider;
    }

    protected function label(): string
    {
        return 'Google Photos';
    }

    public function verify(Integration $integration): void
    {
        // Listing one album is the cheapest call that exercises the read scope.
        // It succeeds and returns nothing on an appendonly-only grant, which is
        // the correct outcome: the connection works, the library is just not
        // visible to us.
        $this->json($integration, 'GET', self::API.'/albums', ['query' => ['pageSize' => 1]]);
    }

    public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        if (null === $folderId || '' === $folderId) {
            return $this->albums($integration, $cursor);
        }

        return $this->albumItems($integration, $folderId, $cursor);
    }

    public function download(Integration $integration, string $fileId): RemoteFile
    {
        $item = $this->json($integration, 'GET', self::API.'/mediaItems/'.rawurlencode($fileId));

        $baseUrl = $this->stringOrNull($item['baseUrl'] ?? null);

        if (null === $baseUrl) {
            throw new IntegrationException('Google Photos returned no download URL for that item.');
        }

        // baseUrl carries its own short-lived credential, so our bearer token
        // is deliberately not sent — Google rejects the combination.
        try {
            $response = $this->httpClient->request('GET', $baseUrl.self::ORIGINAL_SUFFIX);

            if ($response->getStatusCode() >= 400) {
                throw new IntegrationException($this->messageForStatus($response->getStatusCode(), $response));
            }

            $contents = $response->getContent();
        } catch (IntegrationException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        return new RemoteFile(
            filename: (string) ($item['filename'] ?? $fileId),
            mime: (string) ($item['mimeType'] ?? 'application/octet-stream'),
            contents: $contents,
        );
    }

    /**
     * Two steps, as the API requires: post the bytes to get an upload token,
     * then commit that token into the library.
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

        $handle = @fopen($absolutePath, 'r');

        if (false === $handle) {
            throw new IntegrationException(sprintf('Cannot read %s to upload.', $filename));
        }

        try {
            $response = $this->request($integration, 'POST', self::API.'/uploads', [
                'headers' => [
                    'Content-Type'             => 'application/octet-stream',
                    'X-Goog-Upload-Content-Type' => $mime,
                    'X-Goog-Upload-Protocol'   => 'raw',
                ],
                'body' => $handle,
            ]);

            $uploadToken = trim($response->getContent());
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        } finally {
            if (true === is_resource($handle)) {
                fclose($handle);
            }
        }

        if ('' === $uploadToken) {
            throw new IntegrationException('Google Photos accepted the bytes but returned no upload token.');
        }

        $body = [
            'newMediaItems' => [[
                'description'     => $filename,
                'simpleMediaItem' => ['fileName' => $filename, 'uploadToken' => $uploadToken],
            ]],
        ];

        if (null !== $folderId && '' !== $folderId) {
            $body['albumId'] = $folderId;
        }

        $payload = $this->json($integration, 'POST', self::API.'/mediaItems:batchCreate', ['json' => $body]);

        // batchCreate answers 200 with a per-item status, so the HTTP code
        // alone does not say whether the item was actually created.
        $result = $payload['newMediaItemResults'][0] ?? null;

        if (false === is_array($result)) {
            throw new IntegrationException('Google Photos did not report the outcome of the upload.');
        }

        $id = $this->stringOrNull($result['mediaItem']['id'] ?? null);

        if (null === $id) {
            throw new IntegrationException(sprintf(
                'Google Photos rejected the upload: %s',
                (string) ($result['status']['message'] ?? 'unknown error'),
            ));
        }

        return $id;
    }

    public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
    {
        try {
            $item = $this->json($integration, 'GET', self::API.'/mediaItems/'.rawurlencode($fileId));
        } catch (IntegrationException) {
            return null;
        }

        $baseUrl = $this->stringOrNull($item['baseUrl'] ?? null);

        if (null === $baseUrl) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', sprintf('%s=w%d-h%d-c', $baseUrl, self::THUMBNAIL_EDGE, self::THUMBNAIL_EDGE));

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $contents = $response->getContent(false);
            $mime = $response->getHeaders(false)['content-type'][0] ?? 'image/jpeg';
        } catch (HttpExceptionInterface) {
            return null;
        }

        return '' === $contents
            ? null
            : new RemoteFile($fileId, $this->bareMime($mime, 'image/jpeg'), $contents);
    }

    // ── Destinations ────────────────────────────────────────────────────────────

    /**
     * A save targets an album, and the album list is exactly what list() opens
     * on for this provider — so destinations is that same view.
     */
    public function destinations(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        return $this->albums($integration, $cursor);
    }

    /**
     * Confirm the chosen album is one this account holds, by fetching it: a
     * foreign or made-up id answers 404 and is refused before an upload files a
     * photo into nothing. The empty string is "library, no album", always fine.
     *
     * Note a Google Photos quirk the picker cannot paper over: the append scope
     * only lets an app add media to albums the app itself created, so an album
     * that exists here but was made in Google's own client will pass this check
     * and still be refused by the upload. Only a live account can exercise that;
     * see the driver's scope notes.
     */
    public function assertDestination(Integration $integration, string $destination): void
    {
        if ('' === $destination) {
            return;
        }

        try {
            $this->json($integration, 'GET', self::API.'/albums/'.rawurlencode($destination));
        } catch (IntegrationException) {
            throw new IntegrationException('That album is not in this Google Photos account.');
        }
    }

    /** Create an album and return its id. A flat list, so $parent is ignored. */
    public function createDestination(Integration $integration, ?string $parent, string $name): string
    {
        $name = trim($name);

        if ('' === $name) {
            throw new IntegrationException('An album needs a name.');
        }

        $payload = $this->json($integration, 'POST', self::API.'/albums', [
            'json' => ['album' => ['title' => $name]],
        ]);

        $id = $this->stringOrNull($payload['id'] ?? null);

        if (null === $id) {
            throw new IntegrationException('Google Photos created the album but did not return its id.');
        }

        return $id;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function albums(Integration $integration, ?string $cursor): Listing
    {
        $payload = $this->json($integration, 'GET', self::API.'/albums', [
            'query' => ['pageSize' => 50, 'pageToken' => $cursor],
        ]);

        $entries = [];

        foreach ($payload['albums'] ?? [] as $album) {
            if (false === is_array($album)) {
                continue;
            }

            $id = $this->stringOrNull($album['id'] ?? null);

            if (null === $id) {
                continue;
            }

            $entries[] = Entry::folder($id, (string) ($album['title'] ?? 'Album'));
        }

        return new Listing($entries, $this->breadcrumb(), $this->stringOrNull($payload['nextPageToken'] ?? null));
    }

    private function albumItems(Integration $integration, string $albumId, ?string $cursor): Listing
    {
        // Searching by album is a POST, unlike everything else here.
        $payload = $this->json($integration, 'POST', self::API.'/mediaItems:search', [
            'json' => array_filter([
                'albumId'   => $albumId,
                'pageSize'  => 100,
                'pageToken' => $cursor,
            ], static fn (mixed $v): bool => null !== $v),
        ]);

        $entries = [];

        foreach ($payload['mediaItems'] ?? [] as $item) {
            if (false === is_array($item)) {
                continue;
            }

            $id = $this->stringOrNull($item['id'] ?? null);

            if (null === $id) {
                continue;
            }

            $entries[] = new Entry(
                id: $id,
                name: (string) ($item['filename'] ?? $id),
                isFolder: false,
                // The API reports no byte size for media items at all, so the
                // picker treats every photo as possibly-too-big. That is the
                // honest reading: the size is genuinely unknown until fetched.
                size: null,
                mime: $this->stringOrNull($item['mimeType'] ?? null),
                modifiedAt: $this->parseDate($item['mediaMetadata']['creationTime'] ?? null),
            );
        }

        $name = $this->albumName($integration, $albumId);

        return new Listing(
            $entries,
            [...$this->breadcrumb(), Entry::folder($albumId, $name)],
            $this->stringOrNull($payload['nextPageToken'] ?? null),
        );
    }

    private function albumName(Integration $integration, string $albumId): string
    {
        try {
            $album = $this->json($integration, 'GET', self::API.'/albums/'.rawurlencode($albumId));
        } catch (IntegrationException) {
            return 'Album';
        }

        return (string) ($album['title'] ?? 'Album');
    }

    /** @return list<Entry> */
    private function breadcrumb(): array
    {
        return [Entry::folder('', 'Google Photos')];
    }
}
