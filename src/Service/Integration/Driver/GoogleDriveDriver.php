<?php

declare(strict_types=1);

namespace App\Service\Integration\Driver;

use App\Domain\DTO\Integration\Entry;
use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * Google Drive, over the Drive v3 REST API.
 *
 * Entry ids are Drive file ids. 'root' is the alias for the user's My Drive,
 * which is what an empty folder id resolves to — Drive has no path-addressing,
 * so navigation is "list children of this id" all the way down.
 *
 * Folders are files with a magic MIME type, and Drive's tree is a graph rather
 * than a hierarchy: a file can sit in several parents at once. Browsing follows
 * the single parent Drive reports first, which is what the web UI shows too.
 *
 * Two Drive behaviours the listing has to account for. Trashed files still come
 * back unless excluded, so an attachment picked from the bin would 404 later.
 * And Google Docs/Sheets/Slides have no bytes at all — files.get with alt=media
 * fails on them — so they are listed but marked unattachable by reporting no
 * size, rather than being offered and then failing.
 */
final class GoogleDriveDriver extends AbstractOAuthDriver
{
    private const string API = 'https://www.googleapis.com/drive/v3';
    private const string UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    private const string FOLDER_MIME = 'application/vnd.google-apps.folder';

    /** Drive's own alias for My Drive. */
    private const string ROOT = 'root';

    /** Native Google editor formats, which have no downloadable bytes. */
    private const string NATIVE_PREFIX = 'application/vnd.google-apps.';

    private const string FILE_FIELDS = 'id,name,mimeType,size,modifiedTime,thumbnailLink,parents';

    /** How far up the parent chain the breadcrumb walks before giving up. */
    private const int BREADCRUMB_LIMIT = 10;

    public function supports(Provider $provider): bool
    {
        return Provider::GoogleDrive === $provider;
    }

    protected function label(): string
    {
        return 'Google Drive';
    }

    public function verify(Integration $integration): void
    {
        // Cheapest call that proves both the token and the granted scope.
        $this->json($integration, 'GET', self::API.'/about', ['query' => ['fields' => 'user(emailAddress)']]);
    }

    public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        $folder = $this->folderOr($folderId);

        $payload = $this->json($integration, 'GET', self::API.'/files', [
            'query' => [
                // Trashed files are excluded here rather than filtered after:
                // they would otherwise fill a page and be unusable anyway.
                'q'                         => sprintf("'%s' in parents and trashed = false", $this->escape($folder)),
                'fields'                    => sprintf('nextPageToken,files(%s)', self::FILE_FIELDS),
                'pageSize'                  => 200,
                'pageToken'                 => $cursor,
                'orderBy'                   => 'folder,name',
                // Shared drives are part of "my files" to the person using
                // them, so hiding them would make the picker look empty for
                // anyone working in a team drive.
                'supportsAllDrives'         => 'true',
                'includeItemsFromAllDrives' => 'true',
            ],
        ]);

        $entries = [];

        foreach ($payload['files'] ?? [] as $file) {
            if (false === is_array($file)) {
                continue;
            }

            $entries[] = $this->entry($file);
        }

        return new Listing(
            $entries,
            $this->breadcrumb($integration, $folder),
            $this->stringOrNull($payload['nextPageToken'] ?? null),
        );
    }

    public function download(Integration $integration, string $fileId): RemoteFile
    {
        $meta = $this->json($integration, 'GET', self::API.'/files/'.rawurlencode($fileId), [
            'query' => ['fields' => 'id,name,mimeType,size', 'supportsAllDrives' => 'true'],
        ]);

        $mime = (string) ($meta['mimeType'] ?? 'application/octet-stream');

        if (true === str_starts_with($mime, self::NATIVE_PREFIX)) {
            // A Doc or Sheet. Exporting it would mean choosing a format on the
            // user's behalf and silently changing what they picked, so this is
            // refused with the reason rather than guessed at.
            throw new IntegrationException(sprintf(
                '"%s" is a Google document and has no file to attach. Share a link instead.',
                (string) ($meta['name'] ?? $fileId),
            ));
        }

        $response = $this->request($integration, 'GET', self::API.'/files/'.rawurlencode($fileId), [
            'query' => ['alt' => 'media', 'supportsAllDrives' => 'true'],
        ]);

        try {
            $contents = $response->getContent();
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        return new RemoteFile(
            filename: (string) ($meta['name'] ?? $fileId),
            mime: $mime,
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

        $metadata = ['name' => $filename];

        if (null !== $folderId && '' !== $folderId) {
            $metadata['parents'] = [$folderId];
        }

        // Drive's multipart upload wants the JSON metadata as the first part
        // and the bytes as the second, related rather than form-data — hence
        // the explicit content type on the request below.
        $form = new FormDataPart([
            'metadata' => new DataPart(json_encode($metadata, JSON_THROW_ON_ERROR), null, 'application/json'),
            'file'     => new DataPart(new File($absolutePath), $filename, $mime),
        ]);

        $payload = $this->json($integration, 'POST', self::UPLOAD, [
            'query'   => ['uploadType' => 'multipart', 'fields' => 'id', 'supportsAllDrives' => 'true'],
            'headers' => [
                'Content-Type' => 'multipart/related; boundary='.$form->getPreparedHeaders()->getHeaderParameter('Content-Type', 'boundary'),
            ],
            'body' => $form->bodyToIterable(),
        ]);

        $id = $this->stringOrNull($payload['id'] ?? null);

        if (null === $id) {
            throw new IntegrationException('Google Drive accepted the upload but returned no file id.');
        }

        return $id;
    }

    public function shareLink(Integration $integration, string $fileId): ?string
    {
        // Grant "anyone with the link can read", then read back the link Drive
        // publishes for it. Two calls because permissions.create does not
        // return the URL.
        $this->json($integration, 'POST', self::API.'/files/'.rawurlencode($fileId).'/permissions', [
            'query' => ['supportsAllDrives' => 'true'],
            'json'  => ['role' => 'reader', 'type' => 'anyone'],
        ]);

        $meta = $this->json($integration, 'GET', self::API.'/files/'.rawurlencode($fileId), [
            'query' => ['fields' => 'webViewLink', 'supportsAllDrives' => 'true'],
        ]);

        return $this->stringOrNull($meta['webViewLink'] ?? null);
    }

    public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
    {
        $meta = $this->json($integration, 'GET', self::API.'/files/'.rawurlencode($fileId), [
            'query' => ['fields' => 'thumbnailLink', 'supportsAllDrives' => 'true'],
        ]);

        $link = $this->stringOrNull($meta['thumbnailLink'] ?? null);

        if (null === $link) {
            return null;
        }

        // thumbnailLink is a short-lived signed URL, so it is fetched without
        // our bearer token — sending one would be rejected as a mismatched
        // credential.
        try {
            $response = $this->httpClient->request('GET', $link);

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

        return new RemoteFile($fileId, $this->bareMime($mime, 'image/jpeg'), $contents);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $file
     */
    private function entry(array $file): Entry
    {
        $mime = (string) ($file['mimeType'] ?? '');
        $isFolder = self::FOLDER_MIME === $mime;

        return new Entry(
            id: (string) ($file['id'] ?? ''),
            name: (string) ($file['name'] ?? 'untitled'),
            isFolder: $isFolder,
            // Native Google formats report no size, which the picker already
            // treats as "cannot promise this fits" — and they genuinely cannot
            // be attached, so that is the correct reading rather than a gap.
            size: $isFolder ? null : $this->intOrNull($file['size'] ?? null),
            mime: $isFolder ? null : $this->stringOrNull($mime),
            modifiedAt: $this->parseDate($file['modifiedTime'] ?? null),
        );
    }

    /**
     * Walk up the parent chain to name the trail.
     *
     * One request per level, so it is capped: Drive allows arbitrarily deep
     * nesting, and a pathological tree must not turn one click into fifty
     * round trips. Past the cap the crumb simply starts lower down.
     *
     * @return list<Entry>
     */
    private function breadcrumb(Integration $integration, string $folderId): array
    {
        $crumbs = [];
        $current = $folderId;
        $seen = [];

        for ($depth = 0; $depth < self::BREADCRUMB_LIMIT; ++$depth) {
            if (self::ROOT === $current || '' === $current || true === isset($seen[$current])) {
                break;
            }

            $seen[$current] = true;

            try {
                $meta = $this->json($integration, 'GET', self::API.'/files/'.rawurlencode($current), [
                    'query' => ['fields' => 'id,name,parents', 'supportsAllDrives' => 'true'],
                ]);
            } catch (IntegrationException) {
                // A folder we cannot read is not worth failing the whole
                // listing over — the listing itself already succeeded.
                break;
            }

            array_unshift($crumbs, Entry::folder(
                (string) ($meta['id'] ?? $current),
                (string) ($meta['name'] ?? '…'),
            ));

            $parents = $meta['parents'] ?? [];
            $current = is_array($parents) && [] !== $parents ? (string) $parents[0] : self::ROOT;
        }

        return [Entry::folder('', 'My Drive'), ...$crumbs];
    }

    private function folderOr(?string $folderId): string
    {
        return null === $folderId || '' === $folderId ? self::ROOT : $folderId;
    }

    /**
     * Drive's query language is string-delimited, so an id containing a quote
     * would break out of the clause. Ids never legitimately contain one.
     */
    private function escape(string $id): string
    {
        return str_replace(["'", '\\'], '', $id);
    }
}
