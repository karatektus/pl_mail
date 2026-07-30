<?php

declare(strict_types=1);

namespace App\Service\Integration\Driver;

use App\Domain\DTO\Integration\Entry;
use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Interface\SearchableDriverInterface;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Dropbox, over the v2 API.
 *
 * Two hosts, and the split matters: metadata calls go to api.dropboxapi.com as
 * JSON, while anything carrying bytes goes to content.dropboxapi.com with the
 * arguments moved into a Dropbox-API-Arg header — because the body is the file.
 * That header must be plain ASCII, so a filename with an umlaut has to be
 * escaped into \uXXXX form or Dropbox rejects the request outright.
 *
 * Entry ids are lowercase paths ('' is the root, '/photos/beach.jpg' a file),
 * which is what every endpoint here addresses things by.
 *
 * Almost everything is a POST, including reads — Dropbox has no GET endpoints
 * for these operations, so a listing being a POST is correct rather than a slip.
 */
final class DropboxDriver extends AbstractOAuthDriver implements SearchableDriverInterface
{
    private const string API = 'https://api.dropboxapi.com/2';
    private const string CONTENT = 'https://content.dropboxapi.com/2';

    /** Dropbox's ceiling for a single-request upload is 150 MB. */
    private const int UPLOAD_LIMIT = 150 * 1024 * 1024;

    public function supports(Provider $provider): bool
    {
        return Provider::Dropbox === $provider;
    }

    protected function label(): string
    {
        return 'Dropbox';
    }

    public function verify(Integration $integration): void
    {
        // get_current_account takes a null body, which Dropbox insists on
        // rather than an empty object.
        $this->request($integration, 'POST', self::API.'/users/get_current_account', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => 'null',
        ]);
    }

    public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        $payload = null !== $cursor && '' !== $cursor
            ? $this->json($integration, 'POST', self::API.'/files/list_folder/continue', ['json' => ['cursor' => $cursor]])
            : $this->json($integration, 'POST', self::API.'/files/list_folder', [
                'json' => [
                    // The root is the empty string, never '/'.
                    'path'      => $this->normalise($folderId ?? ''),
                    'limit'     => 200,
                    'recursive' => false,
                ],
            ]);

        $entries = [];

        foreach ($payload['entries'] ?? [] as $item) {
            if (false === is_array($item)) {
                continue;
            }

            $path = $this->stringOrNull($item['path_lower'] ?? null);

            if (null === $path) {
                continue;
            }

            $isFolder = 'folder' === ($item['.tag'] ?? '');

            $entries[] = new Entry(
                id: $path,
                name: (string) ($item['name'] ?? basename($path)),
                isFolder: $isFolder,
                size: $isFolder ? null : $this->intOrNull($item['size'] ?? null),
                // Dropbox reports no MIME type, so it is guessed from the
                // extension at attach time by the caller rather than invented
                // here.
                mime: null,
                modifiedAt: $this->parseDate($item['server_modified'] ?? null),
            );
        }

        usort($entries, static function (Entry $a, Entry $b): int {
            if ($a->isFolder !== $b->isFolder) {
                return $a->isFolder ? -1 : 1;
            }

            return strnatcasecmp($a->name, $b->name);
        });

        return new Listing(
            $entries,
            $this->breadcrumb($this->normalise($folderId ?? '')),
            true === ($payload['has_more'] ?? false) ? $this->stringOrNull($payload['cursor'] ?? null) : null,
        );
    }

    /**
     * Dropbox's files/search_v2.
     *
     * Its result shape is nested two levels deeper than a listing —
     * matches[].metadata.metadata — and paging is a cursor rather than a page,
     * so this cannot share fromEntries with list().
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

        $payload = null !== $cursor && '' !== $cursor
            ? $this->json($integration, 'POST', self::API.'/files/search_v2/continue_v2', ['json' => ['cursor' => $cursor]])
            : $this->json($integration, 'POST', self::API.'/files/search_v2', [
                'json' => [
                    'query'   => $query,
                    'options' => [
                        'path'       => $this->normalise($folderId ?? ''),
                        'max_results' => 200,
                        'file_status' => 'active',
                    ],
                ],
            ]);

        $entries = [];

        foreach ($payload['matches'] ?? [] as $match) {
            $item = is_array($match) ? ($match['metadata']['metadata'] ?? null) : null;

            if (false === is_array($item)) {
                continue;
            }

            $path = $this->stringOrNull($item['path_lower'] ?? null);

            if (null === $path) {
                continue;
            }

            $isFolder = 'folder' === ($item['.tag'] ?? '');

            $entries[] = new Entry(
                id: $path,
                name: (string) ($item['name'] ?? basename($path)),
                isFolder: $isFolder,
                size: $isFolder ? null : $this->intOrNull($item['size'] ?? null),
                mime: null,
                modifiedAt: $this->parseDate($item['server_modified'] ?? null),
            );
        }

        return new Listing(
            $entries,
            [Entry::folder('', 'Dropbox'), Entry::folder('', sprintf('\u{201C}%s\u{201D}', $query))],
            true === ($payload['has_more'] ?? false) ? $this->stringOrNull($payload['cursor'] ?? null) : null,
        );
    }

    public function download(Integration $integration, string $fileId): RemoteFile
    {
        $path = $this->normalise($fileId);

        if ('' === $path) {
            throw new IntegrationException('No file selected.');
        }

        $response = $this->contentRequest($integration, 'POST', self::CONTENT.'/files/download', ['path' => $path]);

        try {
            $contents = $response->getContent();
            $mime = $response->getHeaders()['content-type'][0] ?? 'application/octet-stream';
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        return new RemoteFile(
            filename: basename($path),
            mime: $this->bareMime($mime, 'application/octet-stream'),
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

        if ($size > self::UPLOAD_LIMIT) {
            throw new IntegrationException(sprintf(
                '%s is too large for a single Dropbox upload.',
                $filename,
            ));
        }

        $folder = $this->normalise($folderId ?? '');
        $target = ('' === $folder ? '' : $folder).'/'.$filename;

        $handle = @fopen($absolutePath, 'r');

        if (false === $handle) {
            throw new IntegrationException(sprintf('Cannot read %s to upload.', $filename));
        }

        try {
            $response = $this->contentRequest($integration, 'POST', self::CONTENT.'/files/upload', [
                'path' => $target,
                // Rename rather than overwrite: saving two attachments with the
                // same name from different mail must not silently destroy one.
                'mode' => 'add',
                'autorename' => true,
                'mute' => true,
            ], $handle, 'application/octet-stream');

            $payload = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        } finally {
            if (true === is_resource($handle)) {
                fclose($handle);
            }
        }

        $id = $this->stringOrNull($payload['path_lower'] ?? null);

        if (null === $id) {
            throw new IntegrationException('Dropbox accepted the upload but returned no path.');
        }

        return $id;
    }

    public function shareLink(Integration $integration, string $fileId): ?string
    {
        $path = $this->normalise($fileId);

        $response = $this->request($integration, 'POST', self::API.'/sharing/create_shared_link_with_settings', [
            'json' => ['path' => $path, 'settings' => ['requested_visibility' => 'public']],
        ], false);

        try {
            $status = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        if (200 === $status) {
            return $this->stringOrNull($payload['url'] ?? null);
        }

        // A file already shared answers 409 rather than returning the existing
        // link, so the link has to be looked up instead of treated as an error.
        if (409 === $status && true === str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'shared_link_already_exists')) {
            return $this->existingLink($integration, $path);
        }

        throw new IntegrationException($this->messageForStatus($status, $response), $status);
    }

    public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
    {
        $path = $this->normalise($fileId);

        try {
            $response = $this->contentRequest($integration, 'POST', self::CONTENT.'/files/get_thumbnail_v2', [
                'resource' => ['.tag' => 'path', 'path' => $path],
                'size'     => 'w256h256',
                'format'   => 'jpeg',
            ], null, null, false);

            // Anything Dropbox cannot render answers 409, which is a normal
            // outcome for a .zip rather than a failure worth reporting.
            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $contents = $response->getContent(false);
        } catch (HttpExceptionInterface) {
            return null;
        }

        return '' === $contents
            ? null
            : new RemoteFile(basename($path), 'image/jpeg', $contents);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function existingLink(Integration $integration, string $path): ?string
    {
        $payload = $this->json($integration, 'POST', self::API.'/sharing/list_shared_links', [
            'json' => ['path' => $path, 'direct_only' => true],
        ]);

        $first = $payload['links'][0] ?? null;

        return is_array($first) ? $this->stringOrNull($first['url'] ?? null) : null;
    }

    /**
     * A call to the content host: arguments ride in a header and the body is
     * the file.
     *
     * @param array<string,mixed>  $args
     * @param resource|string|null $body
     */
    private function contentRequest(
        Integration $integration,
        string $method,
        string $url,
        array $args,
        mixed $body = null,
        ?string $contentType = null,
        bool $throwOnError = true,
    ): ResponseInterface {
        $headers = ['Dropbox-API-Arg' => $this->apiArg($args)];

        if (null !== $contentType) {
            $headers['Content-Type'] = $contentType;
        }

        $options = ['headers' => $headers];

        if (null !== $body) {
            $options['body'] = $body;
        }

        return $this->request($integration, $method, $url, $options, $throwOnError);
    }

    /**
     * JSON for the Dropbox-API-Arg header. HTTP headers are ASCII-only, so
     * every non-ASCII character has to be escaped — a filename like "Grüße.pdf"
     * otherwise makes Dropbox reject the whole request.
     *
     * @param array<string,mixed> $args
     */
    private function apiArg(array $args): string
    {
        return json_encode($args, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Dropbox paths are absolute with a leading slash and no trailing one; the
     * root is the empty string. Traversal segments are dropped rather than
     * escaped, since the path reaches the API verbatim.
     */
    private function normalise(string $path): string
    {
        $segments = array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn (string $s): bool => '' !== $s && '.' !== $s && '..' !== $s,
        );

        return [] === $segments ? '' : '/'.implode('/', $segments);
    }

    /** @return list<Entry> */
    private function breadcrumb(string $path): array
    {
        $crumbs = [Entry::folder('', 'Dropbox')];

        if ('' === $path) {
            return $crumbs;
        }

        $walked = '';

        foreach (explode('/', trim($path, '/')) as $segment) {
            $walked .= '/'.$segment;
            $crumbs[] = Entry::folder($walked, $segment);
        }

        return $crumbs;
    }
}
