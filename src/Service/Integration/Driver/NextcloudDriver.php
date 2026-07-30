<?php

declare(strict_types=1);

namespace App\Service\Integration\Driver;

use App\Domain\DTO\Integration\Entry;
use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Domain\Interface\IntegrationDriverInterface;
use App\Domain\Interface\SearchableDriverInterface;
use App\Entity\Integration;
use App\Repository\IntegrationProviderConfigRepository;
use App\Service\Integration\IntegrationUrlValidator;
use DateTimeImmutable;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Nextcloud (and any ownCloud-compatible server) over WebDAV.
 *
 * Two APIs are in play. Files go through WebDAV at /remote.php/dav/files/{user},
 * which is plain HTTP verbs — PROPFIND to list, GET to download, PUT to
 * upload. Public links go through the OCS sharing API, which is a different
 * base path with its own required header and its own error envelope.
 *
 * Credentials are the user's Nextcloud username plus an **app password**, not
 * their login password: app passwords are individually revocable and survive
 * the account having 2FA turned on, which a login password does not.
 *
 * Entry ids are the path relative to the user's files root ('' is the root,
 * 'Documents/report.pdf' a file). Choosing the path as the id rather than the
 * WebDAV fileid keeps every operation a single request — an id that had to be
 * resolved to a path first would double the round trips on every click for no
 * gain, since Nextcloud addresses everything by path anyway.
 */
final readonly class NextcloudDriver implements IntegrationDriverInterface, SearchableDriverInterface
{
    private const string DAV_ROOT = '/remote.php/dav';
    private const string DAV_PATH = self::DAV_ROOT.'/files';

    /**
     * Cap on search hits.
     *
     * Was 200, which is more than anyone reads and turned one search into a
     * wall of rows. A picker is for finding one file, so a tighter term beats a
     * longer list — and basicsearch has no cursor to page with, so this number
     * is the whole result set rather than a first page.
     */
    private const int SEARCH_LIMIT = 60;
    private const string OCS_SHARES = '/ocs/v2.php/apps/files_sharing/api/v1/shares';
    private const string PREVIEW_PATH = '/index.php/core/preview.png';

    /** Edge length of picker thumbnails, in CSS pixels before DPR scaling. */
    private const int THUMBNAIL_SIZE = 256;

    /** Public read-only link. */
    private const int SHARE_TYPE_PUBLIC_LINK = 3;

    /**
     * Properties asked for on every PROPFIND. Requesting explicitly rather
     * than using <allprop> keeps the response small on folders with many
     * files — allprop pulls per-file shares and tags we never read.
     */
    private const string PROPFIND_BODY = <<<'XML'
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
        private HttpClientInterface                $httpClient,
        private IntegrationUrlValidator            $urlValidator,
        private IntegrationProviderConfigRepository $configRepository,
    ) {
    }

    public function supports(Provider $provider): bool
    {
        return Provider::Nextcloud === $provider;
    }

    public function verify(Integration $integration): void
    {
        // A PROPFIND on the root is the cheapest call that exercises the base
        // URL, the username and the app password all at once.
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

            $entries[] = new Entry(
                id: $item['path'],
                name: basename($item['path']),
                isFolder: $item['isFolder'],
                size: $item['size'],
                mime: $item['mime'],
                modifiedAt: $item['modifiedAt'],
            );
        }

        usort($entries, static function (Entry $a, Entry $b): int {
            if ($a->isFolder !== $b->isFolder) {
                return $a->isFolder ? -1 : 1;
            }

            return strnatcasecmp($a->name, $b->name);
        });

        return new Listing($entries, $this->breadcrumb($folder));
    }

    /**
     * Filename search, via WebDAV's SEARCH method.
     *
     * Nextcloud also has a unified-search OCS endpoint, but it answers with
     * app URLs like /apps/files/?dir=…&openfile=123 — which are not WebDAV
     * paths, and our entry ids are paths. SEARCH returns ordinary multistatus
     * hrefs instead, so the existing parsing handles the response unchanged and
     * every result is immediately addressable.
     *
     * Matching is a case-insensitive contains on the display name. That is what
     * basicsearch offers; it is not full-text, and the placeholder text says
     * "Search Nextcloud" rather than promising more.
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

        $scope = $this->normalisePath($folderId ?? '');

        $response = $this->request($integration, 'SEARCH', $this->baseUrl($integration).self::DAV_ROOT.'/', [
            'headers' => ['Content-Type' => 'application/xml'],
            'body'    => $this->searchBody($integration, $scope, $query),
        ]);

        try {
            $xml = $response->getContent();
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        $entries = [];

        foreach ($this->parseMultistatus($xml, $this->davRoot($integration)) as $item) {
            // The scope itself comes back as a hit on some versions.
            if ($item['path'] === $scope || '' === $item['path']) {
                continue;
            }

            $entries[] = new Entry(
                id: $item['path'],
                name: basename($item['path']),
                isFolder: $item['isFolder'],
                size: $item['size'],
                mime: $item['mime'],
                modifiedAt: $item['modifiedAt'],
            );
        }

        usort($entries, static function (Entry $a, Entry $b): int {
            if ($a->isFolder !== $b->isFolder) {
                return $a->isFolder ? -1 : 1;
            }

            return strnatcasecmp($a->name, $b->name);
        });

        return new Listing($entries, [
            Entry::folder('', 'Nextcloud'),
            // Names the search rather than a place, since hits cross folders.
            Entry::folder('', sprintf('\u{201C}%s\u{201D}', $query)),
        ]);
    }

    /**
     * basicsearch request body.
     *
     * The literal is XML-escaped and the percent signs are ours, not the
     * user's: a term containing % would otherwise widen its own match.
     */
    private function searchBody(Integration $integration, string $scope, string $query): string
    {
        // The scope href is relative to the DAV root, not an absolute server
        // path: "/files/alice/Documents", never "/remote.php/dav/files/alice/…".
        // Nextcloud fails to resolve the latter and answers 404, which reads to a
        // user as "no such folder" when the folder is fine and the request is not.
        $href = '/files/'.rawurlencode((string) $integration->username)
            .('' === $scope ? '' : '/'.implode('/', array_map('rawurlencode', explode('/', $scope))));

        $literal = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%';

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<d:searchrequest xmlns:d="DAV:"><d:basicsearch>'
            .'<d:select><d:prop><d:resourcetype/><d:getcontentlength/>'
            .'<d:getcontenttype/><d:getlastmodified/></d:prop></d:select>'
            .'<d:from><d:scope><d:href>%s</d:href><d:depth>infinity</d:depth></d:scope></d:from>'
            .'<d:where><d:like><d:prop><d:displayname/></d:prop>'
            .'<d:literal>%s</d:literal></d:like></d:where>'
            .'<d:orderby/><d:limit><d:nresults>%d</d:nresults></d:limit>'
            .'</d:basicsearch></d:searchrequest>',
            htmlspecialchars($href, ENT_XML1),
            htmlspecialchars($literal, ENT_XML1),
            self::SEARCH_LIMIT,
        );
    }

    public function download(Integration $integration, string $fileId): RemoteFile
    {
        $path = $this->normalisePath($fileId);

        if ('' === $path) {
            throw new IntegrationException('No file selected.');
        }

        $response = $this->request($integration, 'GET', $this->davUrl($integration, $path));

        try {
            $contents = $response->getContent();
            $mime = $response->getHeaders()['content-type'][0] ?? 'application/octet-stream';
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        return new RemoteFile(
            filename: basename($path),
            // Nextcloud appends charset for text types; MessagePart wants the
            // bare type.
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

        // Nextcloud does not create intermediate collections on PUT, so a
        // configured upload folder that does not exist yet would fail with a
        // 409 that reads like a conflict rather than a missing directory.
        $this->ensureFolder($integration, $folder);

        try {
            $this->request($integration, 'PUT', $this->davUrl($integration, $target), [
                'headers' => ['Content-Type' => $mime],
                'body'    => $handle,
            ])->getStatusCode();
        } finally {
            if (true === is_resource($handle)) {
                fclose($handle);
            }
        }

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
            'body' => [
                'path'      => '/'.$path,
                'shareType' => self::SHARE_TYPE_PUBLIC_LINK,
                'permissions' => 1, // read only
            ],
        ]);

        try {
            $payload = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        // OCS answers 200 with the real outcome in the body, so the status code
        // alone does not tell us whether the share was created.
        $status = (int) ($payload['ocs']['meta']['statuscode'] ?? 0);

        if (100 !== $status && 200 !== $status) {
            throw new IntegrationException(sprintf(
                'Nextcloud refused to create a share link: %s',
                (string) ($payload['ocs']['meta']['message'] ?? 'unknown error'),
            ));
        }

        $url = $payload['ocs']['data']['url'] ?? null;

        return is_string($url) && '' !== $url ? $url : null;
    }

    public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
    {
        $path = $this->normalisePath($fileId);

        if ('' === $path) {
            return null;
        }

        // The preview endpoint takes a path, which is exactly what our ids are.
        // It answers 404 for file types it cannot render — a perfectly normal
        // outcome for a .zip, so it is a null rather than an error.
        $response = $this->request($integration, 'GET', $this->baseUrl($integration).self::PREVIEW_PATH, [
            'query' => [
                'file'          => '/'.$path,
                'x'             => self::THUMBNAIL_SIZE,
                'y'             => self::THUMBNAIL_SIZE,
                'a'             => 0, // crop to square rather than letterbox
                'forceIcon'     => 0,
            ],
        ], false);

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

        return new RemoteFile(basename($path), trim(explode(';', $mime)[0]), $contents);
    }

    // ── WebDAV ────────────────────────────────────────────────────────────────

    /**
     * @return list<array{path:string,isFolder:bool,size:?int,mime:?string,modifiedAt:?DateTimeImmutable}>
     */
    private function propfind(Integration $integration, string $path): array
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

        return $this->parseMultistatus($xml, $this->davRoot($integration));
    }

    /**
     * @return list<array{path:string,isFolder:bool,size:?int,mime:?string,modifiedAt:?DateTimeImmutable}>
     */
    private function parseMultistatus(string $xml, string $davRoot): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        if (false === $document) {
            throw new IntegrationException('Nextcloud returned a response we could not read. Check the server address.');
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
                'size'       => null === $size ? null : (int) (string) $size,
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
    private function hrefToPath(string $href, string $davRoot): ?string
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
    private function ensureFolder(Integration $integration, string $folder): void
    {
        if ('' === $folder) {
            return;
        }

        $segments = explode('/', $folder);
        $walked = '';

        foreach ($segments as $segment) {
            $walked = '' === $walked ? $segment : $walked.'/'.$segment;

            $status = $this->request($integration, 'MKCOL', $this->davUrl($integration, $walked), [], false)
                ->getStatusCode();

            // 201 created, 405 already there. Anything else is a real problem,
            // but let the PUT report it — its error names the actual file.
            if (201 !== $status && 405 !== $status) {
                return;
            }
        }
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

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
        if (null === $integration->username || null === $integration->secret) {
            throw new IntegrationException('This Nextcloud connection is missing its username or app password.');
        }

        $options['auth_basic'] = [$integration->username, $integration->secret];

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
            401 === $status || 403 === $status => 'Nextcloud rejected the username or app password.',
            404 === $status                    => 'Nextcloud could not find that folder or file.',
            507 === $status                    => 'The Nextcloud account is out of storage space.',
            $status >= 500                     => 'The Nextcloud server reported an error.',
            0 === $status                      => 'Could not reach the Nextcloud server. Check the address.',
            default                            => sprintf('Nextcloud returned an unexpected response (%d).', $status),
        };
    }

    // ── URLs ──────────────────────────────────────────────────────────────────

    private function baseUrl(Integration $integration): string
    {
        return $this->urlValidator->resolve(
            $integration,
            $this->configRepository->findOneByProvider(Provider::Nextcloud),
        );
    }

    /** Path prefix every href in a multistatus body is expected to carry. */
    private function davRoot(Integration $integration): string
    {
        $base = $this->baseUrl($integration);
        $prefix = parse_url($base, PHP_URL_PATH);

        return rtrim(is_string($prefix) ? $prefix : '', '/')
            .self::DAV_PATH.'/'.rawurlencode((string) $integration->username).'/';
    }

    private function davUrl(Integration $integration, string $path): string
    {
        $url = $this->baseUrl($integration)
            .self::DAV_PATH.'/'.rawurlencode((string) $integration->username);

        if ('' === $path) {
            return $url.'/';
        }

        return $url.'/'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * Strip the leading and trailing slashes a caller may have included, and
     * refuse traversal outright — the path is user-controlled and ends up in a
     * URL, so '..' must never survive to reach another user's files.
     */
    private function normalisePath(string $path): string
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
    private function breadcrumb(string $folder): array
    {
        $crumbs = [Entry::folder('', 'Nextcloud')];

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

    private function parseDate(?string $raw): ?DateTimeImmutable
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
