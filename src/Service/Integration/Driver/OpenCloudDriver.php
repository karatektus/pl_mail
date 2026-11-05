<?php

declare(strict_types=1);

namespace App\Service\Integration\Driver;

use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * OpenCloud (#476), the fork of ownCloud Infinite Scale.
 *
 * Listing, download, upload and share links are AbstractWebDavDriver's, and
 * work unchanged: OpenCloud keeps the /remote.php/dav/files/{user} tree and the
 * OCS sharing endpoint for the desktop and mobile clients that expect them. That
 * tree is the user's personal space only. Project spaces and "Shared with me"
 * live under /remote.php/dav/spaces/{id}, and are left out on purpose for now:
 * they would need entry ids that carry a space as well as a path, and the
 * personal space is where someone's own files are.
 *
 * Checked against OpenCloud 7.2.4 in a container; what differs from Nextcloud:
 *
 *   - **Credential.** An app token from the auth-app service, sent as HTTP
 *     Basic with the username. OpenCloud signs people in through OIDC, so
 *     there is no password to send otherwise. Tokens are created with an
 *     expiry, 72 hours unless the user picks longer, and an expired token
 *     reads as a 401 like any other refused credential.
 *   - **Search.** No SEARCH method. The search service answers a REPORT with
 *     an oc:search-files body and a KQL pattern, and its hits come back under
 *     the spaces tree rather than the files tree.
 *   - **Previews.** The DAV path itself, with ?preview=1, rather than a
 *     separate endpoint.
 *   - **Share links.** The same OCS call, but OpenCloud refuses a public link
 *     without a password unless the admin turned that policy off, and answers
 *     XML unless asked for JSON by name.
 */
final readonly class OpenCloudDriver extends AbstractWebDavDriver
{
    /** Where hits from the search service point: one tree per space. */
    private const string SPACES_PATH = '/remote.php/dav/spaces';

    /** The signed-in user's personal space, through the Graph API. */
    private const string MY_DRIVE = '/graph/v1.0/me/drive';

    protected function provider(): Provider
    {
        return Provider::OpenCloud;
    }

    protected function credentialName(): string
    {
        return 'app token';
    }

    /**
     * Filename search, through OpenCloud's search service.
     *
     * The pattern is KQL: `name:"*term*"` matches the term anywhere in a name,
     * case-insensitively, which is what the Nextcloud search does too. A bare
     * term matches only whole words, so "rep" would not find "report.pdf".
     * Quotes, asterisks and backslashes are dropped from the term rather than
     * escaped: KQL has no escape inside a quoted phrase, and nobody searches
     * for a file by its asterisk.
     *
     * Two things the request cannot say, done here instead:
     *
     *   - The search covers every space the user can reach, whatever URL the
     *     REPORT is sent to. Hits from any space other than the personal one
     *     are dropped, because their paths would be read as personal-space
     *     paths and open the wrong file, or none.
     *   - It cannot be scoped to a folder, so hits outside the folder being
     *     searched are dropped too. The limit applies before that, so a
     *     search inside a folder can find fewer than the limit while more
     *     exist elsewhere — acceptable for a picker, where a tighter term is
     *     the answer anyway.
     */
    public function search(
        Integration $integration,
        string $query,
        ?string $folderId = null,
        ?string $cursor = null,
    ): Listing {
        $query = trim($query);
        $term = trim(str_replace(['"', '*', '\\'], ' ', $query));

        if ('' === $term) {
            return $this->list($integration, $folderId);
        }

        $scope = $this->normalisePath($folderId ?? '');

        $response = $this->request($integration, 'REPORT', $this->davUrl($integration, ''), [
            'headers' => ['Content-Type' => 'application/xml'],
            'body'    => $this->searchBody($term),
        ]);

        try {
            $xml = $response->getContent();
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        $spaceRoot = $this->basePath($integration).self::SPACES_PATH.'/'.$this->personalDriveId($integration).'/';
        $entries = [];

        foreach ($this->parseMultistatus($xml, $spaceRoot) as $item) {
            if ('' === $item['path'] || $item['path'] === $scope) {
                continue;
            }

            if ('' !== $scope && false === str_starts_with($item['path'], $scope.'/')) {
                continue;
            }

            $entries[] = $this->entry($item);
        }

        return new Listing($this->sorted($entries), $this->searchBreadcrumb($query));
    }

    private function searchBody(string $term): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<oc:search-files xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
            .'<d:prop><d:resourcetype/><d:getcontentlength/><d:getcontenttype/><d:getlastmodified/></d:prop>'
            .'<oc:search><oc:pattern>%s</oc:pattern><oc:limit>%d</oc:limit></oc:search>'
            .'</oc:search-files>',
            htmlspecialchars(sprintf('name:"*%s*"', $term), ENT_XML1),
            self::SEARCH_LIMIT,
        );
    }

    /**
     * The id of the user's personal space, as the search service writes it
     * into hrefs: "{storage}${space}".
     *
     * Asked of the Graph API on every search rather than stored on the
     * connection: a search is one deliberate request from a person, one more
     * small GET does not show, and a stored id would be one more thing to go
     * stale after a server migration.
     */
    private function personalDriveId(Integration $integration): string
    {
        $response = $this->request($integration, 'GET', $this->baseUrl($integration).self::MY_DRIVE, [
            'headers' => ['Accept' => 'application/json'],
        ]);

        try {
            $payload = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        $id = $payload['id'] ?? null;

        if (false === is_string($id) || '' === $id || str_contains($id, '/')) {
            throw new IntegrationException('OpenCloud did not say which space holds your files.');
        }

        return $id;
    }

    /**
     * A preview from the DAV path itself.
     *
     * 404 for a type OpenCloud cannot render, and for every file when the admin
     * turned previews off over WebDAV — both normal, so both are a null rather
     * than an error, and the picker shows the type's icon instead.
     */
    public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
    {
        $path = $this->normalisePath($fileId);

        if ('' === $path) {
            return null;
        }

        $response = $this->request($integration, 'GET', $this->davUrl($integration, $path), [
            'query' => [
                'preview' => 1,
                'x'       => self::THUMBNAIL_SIZE,
                'y'       => self::THUMBNAIL_SIZE,
                'a'       => 0, // crop to square rather than letterbox
            ],
        ], false);

        try {
            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $contents = $response->getContent(false);
            $mime = trim(explode(';', $response->getHeaders(false)['content-type'][0] ?? 'image/jpeg')[0]);
        } catch (HttpExceptionInterface) {
            return null;
        }

        // A server with previews off may still answer 200 with the file
        // itself; that is not a picture to put in a 256-pixel tile.
        if ('' === $contents || false === str_starts_with($mime, 'image/')) {
            return null;
        }

        return new RemoteFile(basename($path), $mime, $contents);
    }

    protected function shareQuery(): array
    {
        return ['format' => 'json'];
    }

    /**
     * OpenCloud's default policy wants a password on every public link. plMail
     * could make one up, but a link in an email whose password is nowhere
     * is no link at all — so this says what to change instead.
     */
    protected function shareRefused(string $message): IntegrationException
    {
        if (str_contains(strtolower($message), 'password')) {
            return new IntegrationException(
                'OpenCloud requires a password on public links, so plMail cannot create one. Attach a copy instead, or ask your administrator to allow links without a password.',
            );
        }

        return parent::shareRefused($message);
    }
}
