<?php

declare(strict_types=1);

namespace App\Service\Integration\Driver;

use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\Integration;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * Nextcloud over WebDAV.
 *
 * Two APIs are in play. Files go through WebDAV at /remote.php/dav/files/{user},
 * which is plain HTTP verbs — PROPFIND to list, GET to download, PUT to
 * upload. Public links go through the OCS sharing API, which is a different
 * base path with its own required header and its own error envelope. Both live
 * in AbstractWebDavDriver, shared with OpenCloud (#476); what is left here is
 * what only Nextcloud does: the SEARCH method and the core preview endpoint.
 *
 * Credentials are the user's Nextcloud username plus an **app password**, not
 * their login password: app passwords are individually revocable and survive
 * the account having 2FA turned on, which a login password does not. Login
 * Flow v2 (#235) obtains one without the copy-and-paste; see
 * NextcloudLoginFlow.
 */
final readonly class NextcloudDriver extends AbstractWebDavDriver
{
    /**
     * Where Nextcloud serves WebDAV, CalDAV and CardDAV alike, under the base
     * address. Public because CalDavDiscovery starts a Nextcloud connection's
     * calendar lookup here (#158).
     */
    public const string DAV_ROOT = '/remote.php/dav';

    private const string PREVIEW_PATH = '/index.php/core/preview.png';

    protected function provider(): Provider
    {
        return Provider::Nextcloud;
    }

    protected function credentialName(): string
    {
        return 'app password';
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

            $entries[] = $this->entry($item);
        }

        return new Listing($this->sorted($entries), $this->searchBreadcrumb($query));
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
            .('' === $scope ? '' : '/'.$this->encodePath($scope));

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
}
