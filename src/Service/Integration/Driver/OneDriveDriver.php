<?php

declare(strict_types=1);

namespace App\Service\Integration\Driver;

use App\Domain\DTO\Integration\Entry;
use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Interface\SearchableDriverInterface;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * OneDrive, over Microsoft Graph's drive endpoints.
 *
 * The same API the mail side already talks to, but a different app
 * registration: the mail one holds Mail.* scopes and grants no access to files.
 *
 * Entry ids are driveItem ids. An empty folder id means the drive root.
 *
 * Uploads take one of two paths because Graph has two. Simple PUT to
 * /content is documented for files up to 4 MB; past that it silently
 * misbehaves, so anything larger goes through an upload session and is sent in
 * chunks. Attachments routinely straddle that line, so both paths are real
 * rather than one being theoretical.
 */
final class OneDriveDriver extends AbstractOAuthDriver implements SearchableDriverInterface
{
    private const string API = 'https://graph.microsoft.com/v1.0';
    private const string DRIVE = self::API.'/me/drive';

    /** Graph's documented ceiling for a single-request upload. */
    private const int SIMPLE_UPLOAD_LIMIT = 4 * 1024 * 1024;

    /**
     * Chunk size for session uploads. Graph requires a multiple of 320 KiB;
     * this is 10 MiB, which is well inside that and keeps the request count
     * low for typical attachments.
     */
    private const int CHUNK_BYTES = 10 * 327_680;

    private const string ITEM_FIELDS = 'id,name,size,lastModifiedDateTime,folder,file,parentReference';

    public function supports(Provider $provider): bool
    {
        return Provider::OneDrive === $provider;
    }

    protected function label(): string
    {
        return 'OneDrive';
    }

    public function verify(Integration $integration): void
    {
        $this->json($integration, 'GET', self::DRIVE, ['query' => ['$select' => 'id']]);
    }

    public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        // Graph pages by handing back a whole URL, so a cursor is followed
        // as-is rather than turned back into parameters.
        $url = null !== $cursor && '' !== $cursor
            ? $cursor
            : $this->childrenUrl($folderId);

        $payload = $this->json($integration, 'GET', $url, [
            'query' => null !== $cursor && '' !== $cursor ? [] : [
                '$select' => self::ITEM_FIELDS,
                '$top'    => 200,
                '$orderby' => 'folder,name',
            ],
        ]);

        $entries = [];

        foreach ($payload['value'] ?? [] as $item) {
            if (false === is_array($item)) {
                continue;
            }

            $id = $this->stringOrNull($item['id'] ?? null);

            if (null === $id) {
                continue;
            }

            $isFolder = true === isset($item['folder']);

            $entries[] = new Entry(
                id: $id,
                name: (string) ($item['name'] ?? 'untitled'),
                isFolder: $isFolder,
                size: $isFolder ? null : $this->intOrNull($item['size'] ?? null),
                mime: $isFolder ? null : $this->stringOrNull($item['file']['mimeType'] ?? null),
                modifiedAt: $this->parseDate($item['lastModifiedDateTime'] ?? null),
            );
        }

        return new Listing(
            $entries,
            $this->breadcrumb($integration, $folderId),
            $this->stringOrNull($payload['@odata.nextLink'] ?? null),
        );
    }

    /**
     * Graph's drive search.
     *
     * The term goes in the URL's own quoted argument rather than a query
     * parameter, so a quote in it would break out of the call — hence the
     * stripping. Graph pages this with @odata.nextLink like any other listing.
     */
    public function search(
        Integration $integration,
        string $query,
        ?string $folderId = null,
        ?string $cursor = null,
    ): Listing {
        $query = trim($query);

        if ('' === $query) {
            return $this->list($integration, $folderId);
        }

        $url = null !== $cursor && '' !== $cursor
            ? $cursor
            : sprintf("%s/root/search(q='%s')", self::DRIVE, rawurlencode(str_replace("'", '', $query)));

        $payload = $this->json($integration, 'GET', $url, [
            'query' => null !== $cursor && '' !== $cursor ? [] : ['$select' => self::ITEM_FIELDS, '$top' => 200],
        ]);

        $entries = [];

        foreach ($payload['value'] ?? [] as $item) {
            if (false === is_array($item)) {
                continue;
            }

            $id = $this->stringOrNull($item['id'] ?? null);

            if (null === $id) {
                continue;
            }

            $isFolder = true === isset($item['folder']);

            $entries[] = new Entry(
                id: $id,
                name: (string) ($item['name'] ?? 'untitled'),
                isFolder: $isFolder,
                size: $isFolder ? null : $this->intOrNull($item['size'] ?? null),
                mime: $isFolder ? null : $this->stringOrNull($item['file']['mimeType'] ?? null),
                modifiedAt: $this->parseDate($item['lastModifiedDateTime'] ?? null),
            );
        }

        return new Listing(
            $entries,
            [Entry::folder('', 'OneDrive'), Entry::folder('', sprintf('\u{201C}%s\u{201D}', $query))],
            $this->stringOrNull($payload['@odata.nextLink'] ?? null),
        );
    }

    public function download(Integration $integration, string $fileId): RemoteFile
    {
        $meta = $this->json($integration, 'GET', self::DRIVE.'/items/'.rawurlencode($fileId), [
            'query' => ['$select' => 'name,file,size'],
        ]);

        $response = $this->request($integration, 'GET', self::DRIVE.'/items/'.rawurlencode($fileId).'/content');

        try {
            $contents = $response->getContent();
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        return new RemoteFile(
            filename: (string) ($meta['name'] ?? $fileId),
            mime: (string) ($meta['file']['mimeType'] ?? 'application/octet-stream'),
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
        $size = @filesize($absolutePath);

        if (false === $size) {
            throw new IntegrationException(sprintf('Cannot read %s to upload.', $filename));
        }

        return $size <= self::SIMPLE_UPLOAD_LIMIT
            ? $this->simpleUpload($integration, $absolutePath, $filename, $mime, $folderId)
            : $this->sessionUpload($integration, $absolutePath, $filename, $folderId, $size);
    }

    public function shareLink(Integration $integration, string $fileId): ?string
    {
        $payload = $this->json($integration, 'POST', self::DRIVE.'/items/'.rawurlencode($fileId).'/createLink', [
            'json' => ['type' => 'view', 'scope' => 'anonymous'],
        ]);

        return $this->stringOrNull($payload['link']['webUrl'] ?? null);
    }

    public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
    {
        $url = sprintf('%s/items/%s/thumbnails/0/medium/content', self::DRIVE, rawurlencode($fileId));

        try {
            $response = $this->request($integration, 'GET', $url, [], false);

            // 404 is the ordinary answer for a file type Graph cannot render.
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

    // ── Uploads ───────────────────────────────────────────────────────────────

    private function simpleUpload(
        Integration $integration,
        string $absolutePath,
        string $filename,
        string $mime,
        ?string $folderId,
    ): string {
        $handle = @fopen($absolutePath, 'r');

        if (false === $handle) {
            throw new IntegrationException(sprintf('Cannot read %s to upload.', $filename));
        }

        try {
            $payload = $this->json($integration, 'PUT', $this->contentUrl($folderId, $filename), [
                'headers' => ['Content-Type' => $mime],
                'body'    => $handle,
            ]);
        } finally {
            if (true === is_resource($handle)) {
                fclose($handle);
            }
        }

        return $this->uploadedId($payload);
    }

    /**
     * Chunked upload. Graph replies 202 to every chunk but the last, and only
     * the final response carries the created item — so the id comes from
     * whichever response first has one.
     */
    private function sessionUpload(
        Integration $integration,
        string $absolutePath,
        string $filename,
        ?string $folderId,
        int $size,
    ): string {
        $session = $this->json($integration, 'POST', $this->sessionUrl($folderId, $filename), [
            'json' => ['item' => ['@microsoft.graph.conflictBehavior' => 'rename']],
        ]);

        $uploadUrl = $this->stringOrNull($session['uploadUrl'] ?? null);

        if (null === $uploadUrl) {
            throw new IntegrationException('OneDrive did not return an upload URL.');
        }

        $handle = @fopen($absolutePath, 'r');

        if (false === $handle) {
            throw new IntegrationException(sprintf('Cannot read %s to upload.', $filename));
        }

        try {
            $offset = 0;

            while ($offset < $size) {
                $chunk = fread($handle, self::CHUNK_BYTES);

                if (false === $chunk || '' === $chunk) {
                    break;
                }

                $length = strlen($chunk);

                // The session URL is pre-authorised, so no bearer token goes
                // with the chunks — Graph rejects one here.
                $response = $this->httpClient->request('PUT', $uploadUrl, [
                    'headers' => [
                        'Content-Length' => (string) $length,
                        'Content-Range'  => sprintf('bytes %d-%d/%d', $offset, $offset + $length - 1, $size),
                    ],
                    'body' => $chunk,
                ]);

                $status = $response->getStatusCode();

                if ($status >= 400) {
                    throw new IntegrationException($this->messageForStatus($status, $response), $status);
                }

                $offset += $length;

                if (200 === $status || 201 === $status) {
                    return $this->uploadedId($response->toArray(false));
                }
            }
        } catch (IntegrationException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        } finally {
            if (true === is_resource($handle)) {
                fclose($handle);
            }
        }

        throw new IntegrationException(sprintf('OneDrive did not confirm the upload of %s.', $filename));
    }

    /**
     * @param array<mixed> $payload
     */
    private function uploadedId(array $payload): string
    {
        $id = $this->stringOrNull($payload['id'] ?? null);

        if (null === $id) {
            throw new IntegrationException('OneDrive accepted the upload but returned no item id.');
        }

        return $id;
    }

    // ── URLs ──────────────────────────────────────────────────────────────────

    private function childrenUrl(?string $folderId): string
    {
        return null === $folderId || '' === $folderId
            ? self::DRIVE.'/root/children'
            : self::DRIVE.'/items/'.rawurlencode($folderId).'/children';
    }

    /**
     * Graph addresses a new child by name under its parent, using the `:/name:`
     * colon syntax. The name is path-encoded but the colons must survive, so it
     * cannot simply be rawurlencode'd whole.
     */
    private function contentUrl(?string $folderId, string $filename): string
    {
        $parent = null === $folderId || '' === $folderId
            ? self::DRIVE.'/root'
            : self::DRIVE.'/items/'.rawurlencode($folderId);

        return sprintf('%s:/%s:/content', $parent, rawurlencode($filename));
    }

    private function sessionUrl(?string $folderId, string $filename): string
    {
        $parent = null === $folderId || '' === $folderId
            ? self::DRIVE.'/root'
            : self::DRIVE.'/items/'.rawurlencode($folderId);

        return sprintf('%s:/%s:/createUploadSession', $parent, rawurlencode($filename));
    }

    /**
     * Graph reports the parent of an item but not the whole chain, so the trail
     * is one level deep plus the root. Deeper crumbs would cost a request per
     * level for a path the user just clicked through anyway.
     *
     * @return list<Entry>
     */
    private function breadcrumb(Integration $integration, ?string $folderId): array
    {
        $root = Entry::folder('', 'OneDrive');

        if (null === $folderId || '' === $folderId) {
            return [$root];
        }

        try {
            $meta = $this->json($integration, 'GET', self::DRIVE.'/items/'.rawurlencode($folderId), [
                'query' => ['$select' => 'id,name'],
            ]);
        } catch (IntegrationException) {
            return [$root];
        }

        return [$root, Entry::folder($folderId, (string) ($meta['name'] ?? '…'))];
    }
}
