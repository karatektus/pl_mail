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
use App\Repository\Integration\IntegrationProviderConfig\IntegrationProviderConfigRepository;
use App\Service\Integration\IntegrationUrlValidator;
use DateTimeImmutable;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A file store reached over the ownCloud flavour of WebDAV: Nextcloud, and
 * OpenCloud (#476), which forked from ownCloud Infinite Scale and kept the
 * same /remote.php/dav/files/{user} tree for the clients that expect it.
 *
 * Extracted from NextcloudDriver when OpenCloud arrived, because the two agree
 * on everything a picker does most: PROPFIND to list, GET to download, PUT and
 * MKCOL to save, and the OCS sharing API for a public link — the same endpoint,
 * the same shareType 3, the same envelope. They part ways on three things, and
 * those are the abstract methods: how to search (Nextcloud's SEARCH method is
 * not implemented in OpenCloud, whose search service answers a REPORT), where
 * a preview comes from, and what the credential is called when it is refused.
 *
 * Entry ids are paths relative to the user's files root ('' is the root,
 * 'Documents/report.pdf' a file). Choosing the path rather than the server's
 * fileid keeps every operation a single request — an id that had to be resolved
 * to a path first would double the round trips on every click, and both servers
 * address everything by path anyway.
 *
 * Credentials are a username plus a per-device secret (a Nextcloud app
 * password, an OpenCloud app token), never the login password: those are
 * individually revocable and survive the account having 2FA or SSO turned on,
 * which a login password does not.
 */
abstract readonly class AbstractWebDavDriver implements IntegrationDriverInterface, SearchableDriverInterface, DestinationDriverInterface
{
    /** The files tree under the DAV root, one collection per user. */
    protected const string FILES_PATH = '/remote.php/dav/files';

    protected const string OCS_SHARES = '/ocs/v2.php/apps/files_sharing/api/v1/shares';

    /** Edge length of picker thumbnails, in CSS pixels before DPR scaling. */
    protected const int THUMBNAIL_SIZE = 256;

    /**
     * Cap on search hits.
     *
     * Was 200, which is more than anyone reads and turned one search into a
     * wall of rows. A picker is for finding one file, so a tighter term beats a
     * longer list — and neither server's search has a cursor to page with, so
     * this number is the whole result set rather than a first page.
     */
    protected const int SEARCH_LIMIT = 60;

    /** Public read-only link. */
    private const int SHARE_TYPE_PUBLIC_LINK = 3;

    /**
     * Properties asked for on every PROPFIND. Requesting explicitly rather
     * than using <allprop> keeps the response small on folders with many
     * files — allprop pulls per-file shares and tags we never read.
     */
    protected const string PROPFIND_BODY = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <d:propfind xmlns:d="DAV:">
          <d:prop>
            <d:resourcetype/>
            <d:getcontentlength/>
            <d:getcontenttype/>
            <d:getlastmodified/>
          </d:prop>
        </d:propfind>
        XML;

    public function __construct(
        protected HttpClientInterface                 $httpClient,
        protected IntegrationUrlValidator             $urlValidator,
        protected IntegrationProviderConfigRepository $configRepository,
    ) {
    }

    /** The one provider this driver serves. */
    abstract protected function provider(): Provider;

    /**
     * What the secret is called on this server, for the message a 401 turns
     * into: "app password" on Nextcloud, "app token" on OpenCloud. Telling
     * someone their app password was refused when they pasted a token sends
     * them looking for a setting that does not exist.
     */
    abstract protected function credentialName(): string;

    public function supports(Provider $provider): bool
    {
        return $this->provider() === $provider;
    }

    public function verify(Integration $integration): void
    {
        // A PROPFIND on the root is the cheapest call that exercises the base
        // URL, the username and the secret all at once.
        $this->propfind($integration, '');
    }

    public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        $folder = $this->normalisePath($folderId ?? '');
        $entries = [];

        foreach ($this->propfind($integration, $folder) as $item) {
            // PROPFIND with Depth: 1 returns the folder itself first. It is not
            // a child, and including it would let the user descend into the
            // folder they are already looking at.
            if ($item['path'] === $folder) {
                continue;
            }

            $entries[] = $this->entry($item);
        }

        return new Listing($this->sorted($entries), $this->breadcrumb($integration, $folder));
    }

    public function download(Integration $integration, string $fileId): RemoteFile
    {
        $path = $this->normalisePath($fileId);

        if ('' === $path) {
            throw new IntegrationException('No file selected.');
        }

        $response = $this->request($integration, 'GET', $this->davUrl($integration, $path), HttpLimits::transfer());

        try {
            $contents = $response->getContent();
            $mime = $response->getHeaders()['content-type'][0] ?? 'application/octet-stream';
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        return new RemoteFile(
            filename: basename($path),
            // Both servers append charset for text types; MessagePart wants
            // the bare type.
            mime: trim(explode(';', $mime)[0]),
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
        $handle = @fopen($absolutePath, 'r');

        if (false === $handle) {
            throw new IntegrationException(sprintf('Cannot read %s to upload.', basename($absolutePath)));
        }

        $folder = $this->normalisePath($folderId ?? '');
        $target = '' === $folder ? $filename : $folder.'/'.$filename;

        // Neither server creates intermediate collections on PUT, so a
        // configured upload folder that does not exist yet would fail with a
        // 409 that reads like a conflict rather than a missing directory.
        $this->ensureFolder($integration, $folder);

        // Stated rather than left to the client: a stream body goes out
        // chunked without it, which Nextcloud accepts and OpenCloud refuses
        // with a 400 — it sizes the upload from this header before reading a
        // byte.
        $size = @filesize($absolutePath);
        $headers = ['Content-Type' => $mime] + (false === $size ? [] : ['Content-Length' => (string) $size]);

        try {
            $this->request($integration, 'PUT', $this->davUrl($integration, $target), [
                'headers' => $headers,
                'body'    => $handle,
            ] + HttpLimits::transfer())->getStatusCode();
        } finally {
            if (true === is_resource($handle)) {
                fclose($handle);
            }
        }

        return $target;
    }

    // ── Destinations ────────────────────────────────────────────────────────────

    /**
     * The folders a save may land in are just the folder tree, so a destination
     * pick reuses the ordinary listing — the picker shows its folders as
     * navigable and its files as context.
     */
    public function destinations(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        return $this->list($integration, $folderId, $cursor);
    }

    /**
     * A chosen folder path arrives in a request, so it is confirmed here before
     * a byte is uploaded rather than trusted for having once come out of list().
     *
     * The account itself is not a threat surface: every request carries this
     * integration's own username and secret, and the DAV root is that user's
     * files — a path cannot address another account's storage even in
     * principle. Traversal is the real risk, because the path is interpolated
     * into a URL, so a '..' segment is refused outright rather than silently
     * stripped the way normalisePath does for a listing. Refusing is the honest
     * answer to "save here", where quietly saving somewhere else is not.
     *
     * The empty string is the files root, which is always a valid target.
     */
    public function assertDestination(Integration $integration, string $destination): void
    {
        if ('' === $destination) {
            return;
        }

        foreach (explode('/', str_replace('\\', '/', $destination)) as $segment) {
            if ('..' === $segment) {
                throw new IntegrationException('That destination folder is not allowed.');
            }
        }
    }

    /**
     * Create a folder under $parent and return its path.
     *
     * A folder name is one path segment, never a path itself, so any slash a
     * user typed is flattened rather than allowed to dig a nested tree from the
     * name field. MKCOL is the same call the upload path already makes for a
     * missing folder, so this costs one round trip and no new machinery.
     */
    public function createDestination(Integration $integration, ?string $parent, string $name): string
    {
        $name = trim(str_replace(['/', '\\'], ' ', $name));

        if ('' === $name) {
            throw new IntegrationException('A folder needs a name.');
        }

        $base = $this->normalisePath($parent ?? '');
        $target = $this->normalisePath('' === $base ? $name : $base.'/'.$name);

        if ('' === $target) {
            throw new IntegrationException('A folder needs a name.');
        }

        $this->ensureFolder($integration, $target);

        return $target;
    }

    public function shareLink(Integration $integration, string $fileId): ?string
    {
        $path = $this->normalisePath($fileId);

        $response = $this->request($integration, 'POST', $this->baseUrl($integration).self::OCS_SHARES, [
            // OCS refuses any request without this header, as CSRF protection.
            'headers' => [
                'OCS-APIRequest' => 'true',
                'Accept'         => 'application/json',
            ],
            'query' => $this->shareQuery(),
            'body'  => $this->shareTarget($path) + [
                'shareType'   => self::SHARE_TYPE_PUBLIC_LINK,
                'permissions' => 1, // read only
            ],
        ], false);

        // Not thrown on every 4xx, as the other calls are: OpenCloud answers a
        // refused share with a matching 400 or 404 and the reason in the body,
        // and "unexpected response (400)" would throw that reason away. A
        // refused credential or a broken server has no such body to read.
        try {
            $httpStatus = $response->getStatusCode();
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        if (401 === $httpStatus || 403 === $httpStatus || $httpStatus >= 500) {
            throw new IntegrationException($this->messageForStatus($httpStatus), $httpStatus);
        }

        try {
            $payload = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        // OCS answers 200 with the real outcome in the body on Nextcloud, and
        // a matching HTTP status on OpenCloud, so the body is the one place
        // both agree on.
        $status = (int) ($payload['ocs']['meta']['statuscode'] ?? 0);

        if (100 !== $status && 200 !== $status) {
            throw $this->shareRefused((string) ($payload['ocs']['meta']['message'] ?? 'unknown error'));
        }

        $url = $payload['ocs']['data']['url'] ?? null;

        return is_string($url) && '' !== $url ? $url : null;
    }

    // ── Hooks ─────────────────────────────────────────────────────────────────

    /**
     * Extra query parameters for the share call. Nextcloud reads the Accept
     * header; OpenCloud ignores it and answers XML unless asked by name.
     *
     * @return array<string,string>
     */
    protected function shareQuery(): array
    {
        return [];
    }

    /**
     * Which file a share is for, in the OCS call's own terms: a path from
     * the user's files root, here.
     *
     * @return array<string,string>
     */
    protected function shareTarget(string $id): array
    {
        return ['path' => '/'.$id];
    }

    /**
     * Turn a path relative to the collection that was listed into an entry id.
     * The identity here, where every id is a path from the files root; a
     * driver whose ids also name a space prefixes it.
     */
    protected function qualify(string $listed, string $relative): string
    {
        return $relative;
    }

    /**
     * Whether an id is the top of a tree that exists by itself — the files
     * root here, a whole space for OpenCloud — and so is never created on the
     * way to a folder below it.
     */
    protected function isTreeRoot(string $id): bool
    {
        return '' === $id;
    }

    /** The error for a share the server would not create. */
    protected function shareRefused(string $message): IntegrationException
    {
        return new IntegrationException(sprintf(
            '%s refused to create a share link: %s',
            $this->provider()->label(),
            $message,
        ));
    }

    // ── WebDAV ────────────────────────────────────────────────────────────────

    /**
     * @return list<array{path:string,isFolder:bool,size:?int,mime:?string,modifiedAt:?DateTimeImmutable}>
     */
    protected function propfind(Integration $integration, string $path): array
    {
        $response = $this->request($integration, 'PROPFIND', $this->davUrl($integration, $path), [
            'headers' => [
                'Depth'        => '1',
                'Content-Type' => 'application/xml',
            ],
            'body' => self::PROPFIND_BODY,
        ]);

        try {
            $xml = $response->getContent();
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        return array_map(
            fn (array $item): array => ['path' => $this->qualify($path, $item['path'])] + $item,
            $this->parseMultistatus($xml, $this->davRoot($integration, $path)),
        );
    }

    /**
     * @return list<array{path:string,isFolder:bool,size:?int,mime:?string,modifiedAt:?DateTimeImmutable}>
     */
    protected function parseMultistatus(string $xml, string $davRoot): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        if (false === $document) {
            throw new IntegrationException(sprintf(
                '%s returned a response we could not read. Check the server address.',
                $this->provider()->label(),
            ));
        }

        $document->registerXPathNamespace('d', 'DAV:');
        $responses = $document->xpath('//d:response') ?: [];

        $items = [];

        foreach ($responses as $response) {
            $response->registerXPathNamespace('d', 'DAV:');

            $href = (string) ($response->xpath('d:href')[0] ?? '');

            if ('' === $href) {
                continue;
            }

            $path = $this->hrefToPath($href, $davRoot);

            if (null === $path) {
                continue;
            }

            $isFolder = [] !== ($response->xpath('d:propstat/d:prop/d:resourcetype/d:collection') ?: []);
            $size = $response->xpath('d:propstat/d:prop/d:getcontentlength')[0] ?? null;
            $mime = $response->xpath('d:propstat/d:prop/d:getcontenttype')[0] ?? null;
            $modified = $response->xpath('d:propstat/d:prop/d:getlastmodified')[0] ?? null;

            $items[] = [
                'path'       => $path,
                'isFolder'   => $isFolder,
                'size'       => null === $size || '' === (string) $size ? null : (int) (string) $size,
                'mime'       => null === $mime || '' === (string) $mime ? null : (string) $mime,
                'modifiedAt' => $this->parseDate(null === $modified ? null : (string) $modified),
            ];
        }

        return $items;
    }

    /**
     * Turn an href from the multistatus body into a path relative to the
     * user's files root. Returns null for anything outside it, which is how a
     * server with an unexpected layout fails visibly rather than producing
     * paths that then 404 one click later.
     */
    protected function hrefToPath(string $href, string $davRoot): ?string
    {
        // The href may be absolute or root-relative depending on the server.
        $path = parse_url($href, PHP_URL_PATH);
        $path = is_string($path) ? $path : $href;
        $path = rawurldecode($path);

        if (false === str_starts_with($path, $davRoot)) {
            return null;
        }

        return trim(substr($path, strlen($davRoot)), '/');
    }

    /**
     * MKCOL every missing segment of a folder path, root first. Existing
     * collections answer 405, which is success for our purposes.
     */
    protected function ensureFolder(Integration $integration, string $folder): void
    {
        if ('' === $folder) {
            return;
        }

        $segments = explode('/', $folder);
        $walked = '';

        foreach ($segments as $segment) {
            $walked = '' === $walked ? $segment : $walked.'/'.$segment;

            if (true === $this->isTreeRoot($walked)) {
                continue;
            }

            $status = $this->request($integration, 'MKCOL', $this->davUrl($integration, $walked), [], false)
                ->getStatusCode();

            // 201 created, 405 already there. Anything else is a real problem,
            // but let the PUT report it — its error names the actual file.
            if (201 !== $status && 405 !== $status) {
                return;
            }
        }
    }

    /**
     * @param array{path:string,isFolder:bool,size:?int,mime:?string,modifiedAt:?DateTimeImmutable} $item
     */
    protected function entry(array $item): Entry
    {
        return new Entry(
            id: $item['path'],
            name: basename($item['path']),
            isFolder: $item['isFolder'],
            size: $item['size'],
            mime: $item['mime'],
            modifiedAt: $item['modifiedAt'],
        );
    }

    /**
     * Folders first, then files, each run sorted naturally.
     *
     * @param list<Entry> $entries
     *
     * @return list<Entry>
     */
    protected function sorted(array $entries): array
    {
        usort($entries, static function (Entry $a, Entry $b): int {
            if ($a->isFolder !== $b->isFolder) {
                return $a->isFolder ? -1 : 1;
            }

            return strnatcasecmp($a->name, $b->name);
        });

        return $entries;
    }

    /**
     * The breadcrumb for a search, which names the search rather than a place,
     * since hits cross folders.
     *
     * @return list<Entry>
     */
    protected function searchBreadcrumb(string $query): array
    {
        return [
            Entry::folder('', $this->provider()->label()),
            Entry::folder('', sprintf("\u{201C}%s\u{201D}", $query)),
        ];
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $options
     */
    protected function request(
        Integration $integration,
        string $method,
        string $url,
        array $options = [],
        bool $throwOnError = true,
    ): ResponseInterface {
        if (null === $integration->username || null === $integration->secret) {
            throw new IntegrationException(sprintf(
                'This %s connection is missing its username or %s.',
                $this->provider()->label(),
                $this->credentialName(),
            ));
        }

        $options['auth_basic'] = [$integration->username, $integration->secret];

        $options += HttpLimits::CALL;

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

    protected function translate(HttpExceptionInterface $e): IntegrationException
    {
        $status = method_exists($e, 'getResponse') ? $e->getResponse()->getStatusCode() : 0;

        return new IntegrationException($this->messageForStatus($status), $status, $e);
    }

    protected function messageForStatus(int $status): string
    {
        $label = $this->provider()->label();

        return match (true) {
            401 === $status || 403 === $status => sprintf('%s rejected the username or %s.', $label, $this->credentialName()),
            404 === $status                    => sprintf('%s could not find that folder or file.', $label),
            507 === $status                    => sprintf('The %s account is out of storage space.', $label),
            $status >= 500                     => sprintf('The %s server reported an error.', $label),
            0 === $status                      => sprintf('Could not reach the %s server. Check the address.', $label),
            default                            => sprintf('%s returned an unexpected response (%d).', $label, $status),
        };
    }

    // ── URLs ──────────────────────────────────────────────────────────────────

    protected function baseUrl(Integration $integration): string
    {
        return $this->urlValidator->resolve(
            $integration,
            $this->configRepository->findOneByProvider($this->provider()),
        );
    }

    /** The path the base address carries, without a trailing slash: '' or '/cloud'. */
    protected function basePath(Integration $integration): string
    {
        $prefix = parse_url($this->baseUrl($integration), PHP_URL_PATH);

        return rtrim(is_string($prefix) ? $prefix : '', '/');
    }

    /**
     * Path prefix every href in a multistatus body is expected to carry, for
     * a listing of $id. One files tree here, so $id does not matter; it does
     * where an id can name a space.
     */
    protected function davRoot(Integration $integration, string $id = ''): string
    {
        return $this->basePath($integration)
            .self::FILES_PATH.'/'.rawurlencode((string) $integration->username).'/';
    }

    protected function davUrl(Integration $integration, string $path): string
    {
        $url = $this->baseUrl($integration)
            .self::FILES_PATH.'/'.rawurlencode((string) $integration->username);

        if ('' === $path) {
            return $url.'/';
        }

        return $url.'/'.$this->encodePath($path);
    }

    /** Percent-encode each segment of a path, keeping the slashes. */
    protected function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * Strip the leading and trailing slashes a caller may have included, and
     * refuse traversal outright — the path is user-controlled and ends up in a
     * URL, so '..' must never survive to reach another user's files.
     */
    protected function normalisePath(string $path): string
    {
        $clean = trim(str_replace('\\', '/', $path), '/');

        if ('' === $clean) {
            return '';
        }

        $segments = array_filter(
            explode('/', $clean),
            static fn (string $segment): bool => '' !== $segment && '.' !== $segment && '..' !== $segment,
        );

        return implode('/', $segments);
    }

    /** @return list<Entry> */
    protected function breadcrumb(Integration $integration, string $folder): array
    {
        $crumbs = [Entry::folder('', $this->provider()->label())];

        if ('' === $folder) {
            return $crumbs;
        }

        $walked = '';

        foreach (explode('/', $folder) as $segment) {
            $walked = '' === $walked ? $segment : $walked.'/'.$segment;
            $crumbs[] = Entry::folder($walked, $segment);
        }

        return $crumbs;
    }

    protected function parseDate(?string $raw): ?DateTimeImmutable
    {
        if (null === $raw || '' === $raw) {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
