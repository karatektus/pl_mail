<?php

declare(strict_types=1);

namespace App\Service\Integration\Driver;

use App\Domain\DTO\Integration\Entry;
use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Domain\Interface\SearchableDriverInterface;
use App\Entity\Integration\Integration;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * OpenCloud (#476), the fork of ownCloud Infinite Scale.
 *
 * Listing, download, upload and share links are AbstractWebDavDriver's, and
 * work unchanged for the personal space: OpenCloud keeps the
 * /remote.php/dav/files/{user} tree and the OCS sharing endpoint for the
 * clients that expect them.
 *
 * **Three places files live, and how each is addressed.** Checked against
 * OpenCloud 7.2.4 in a container:
 *
 *   - The **personal space** is the files tree. Ids are paths, as on Nextcloud.
 *   - **Shared with me** is the `Shares` folder in that same tree — every
 *     accepted share is mounted there — so it needs nothing of its own. Its
 *     ids are paths too: "Shares/Bob's folder/plan.txt".
 *   - **Project spaces** exist only under /remote.php/dav/spaces/{id}. Their
 *     ids carry the space: "space:{driveId}" for the top of one,
 *     "space:{driveId}/Reports/q3.pdf" below it. The root listing offers each
 *     space the user is a member of as a folder before the personal files.
 *
 * The prefix keeps every personal id what it was, so an upload folder saved
 * on a connection before spaces were listed still means the same folder.
 * A drive id never contains a slash, which is what lets the first segment of
 * an id be the space and the rest an ordinary path — normalisePath() and
 * assertDestination() need no change for it.
 *
 * What else differs from Nextcloud:
 *
 *   - **Credential.** An app token from the auth-app service, sent as HTTP
 *     Basic with the username. Tokens are created with an expiry, and an
 *     expired one reads as a 401 like any other refused credential.
 *   - **Search.** No SEARCH method: the search service answers a REPORT with a
 *     KQL pattern, across every space at once, with hits under the spaces
 *     tree. Each hit is mapped back to one of the three kinds of id above.
 *   - **Previews.** The DAV path itself, with ?preview=1.
 *   - **Share links.** The same OCS call, with `space_ref` for a project
 *     space, and JSON asked for by name. OpenCloud refuses a public link
 *     without a password unless the admin turned that policy off.
 */
final readonly class OpenCloudDriver extends AbstractWebDavDriver implements SearchableDriverInterface
{
    /** Where a space's tree lives, and where search hits point. */
    private const string SPACES_PATH = '/remote.php/dav/spaces';

    /** Every space the user can reach, through the Graph API. */
    private const string MY_DRIVES = '/graph/v1.0/me/drives';

    private const string SPACE_PREFIX = 'space:';

    /** Where OpenCloud mounts what was shared with the user, in the files tree. */
    private const string SHARES_FOLDER = 'Shares';

    protected function provider(): Provider
    {
        return Provider::OpenCloud;
    }

    protected function credentialName(): string
    {
        return 'app token';
    }

    /**
     * The personal files, with the project spaces in front of them at the top.
     *
     * In front rather than sorted in: a space called "Docs" beside a personal
     * folder called "Docs" would otherwise be two indistinguishable rows.
     */
    public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        $listing = parent::list($integration, $folderId, $cursor);

        if ('' !== $this->normalisePath($folderId ?? '')) {
            return $listing;
        }

        // The files are the point of the listing; the spaces are extra. A
        // server that refuses the Graph call — a proxy that only passes
        // WebDAV, say — still shows the personal files rather than nothing.
        try {
            $drives = $this->drives($integration);
        } catch (IntegrationException) {
            $drives = [];
        }

        $spaces = [];

        foreach ($drives as $drive) {
            if ('project' === $drive['type']) {
                $spaces[] = Entry::folder(self::SPACE_PREFIX.$drive['id'], $drive['name']);
            }
        }

        usort($spaces, static fn (Entry $a, Entry $b): int => strnatcasecmp($a->name, $b->name));

        return new Listing([...$spaces, ...$listing->entries], $listing->breadcrumb, $listing->nextCursor, $listing->shortcuts);
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
     * The service searches every space the user can reach, whatever URL the
     * REPORT goes to, and answers with hrefs under the spaces tree. mapHit()
     * turns each back into an id; a hit it cannot place is dropped rather than
     * opened as the wrong file. It cannot be scoped to a folder either, so hits
     * outside the folder being searched are dropped here — the limit applies
     * first, so a search inside a folder can find fewer than the limit while
     * more exist elsewhere, which a tighter term answers.
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

        $drives = $this->drives($integration);
        $entries = [];

        foreach ($this->parseMultistatus($xml, $this->basePath($integration).self::SPACES_PATH.'/') as $item) {
            $hit = $this->mapHit($item['path'], $drives);

            if (null === $hit || '' === $hit['id'] || $hit['id'] === $scope) {
                continue;
            }

            if ('' !== $scope && false === str_starts_with($hit['id'], $scope.'/')) {
                continue;
            }

            $entries[] = new Entry(
                id: $hit['id'],
                name: $hit['name'] ?? basename($hit['id']),
                isFolder: $item['isFolder'],
                size: $item['size'],
                mime: $item['mime'],
                modifiedAt: $item['modifiedAt'],
            );
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
     * One search hit, "{spaceId}/{path}" under the spaces tree, as an entry id.
     *
     *   - the personal space's id → the path, as listed from the files tree;
     *   - a project space's id    → "space:{id}/{path}";
     *   - an accepted share       → "Shares/{mount name}/{path}". Its href names
     *     the share as "{storage}!{share}", and the drive list names the same
     *     share "{storage}${space}!{share}", so the two meet on what follows
     *     the "!".
     *
     * The top of a space or a share has an empty path and is named after the
     * drive, which is what the person sees in OpenCloud.
     *
     * @param list<array{id:string,type:string,name:string}> $drives
     *
     * @return array{id:string,name:?string}|null
     */
    private function mapHit(string $hit, array $drives): ?array
    {
        [$spaceId, $path] = array_pad(explode('/', $hit, 2), 2, '');
        $path = trim($path, '/');

        foreach ($drives as $drive) {
            if ('personal' === $drive['type'] && $drive['id'] === $spaceId) {
                return ['id' => $path, 'name' => null];
            }

            if ('project' === $drive['type'] && $drive['id'] === $spaceId) {
                return [
                    'id'   => self::SPACE_PREFIX.$spaceId.('' === $path ? '' : '/'.$path),
                    'name' => '' === $path ? $drive['name'] : null,
                ];
            }

            if ('mountpoint' === $drive['type'] && str_contains($spaceId, '!')
                && str_ends_with($drive['id'], '!'.substr($spaceId, strpos($spaceId, '!') + 1))) {
                return [
                    'id'   => self::SHARES_FOLDER.'/'.$drive['name'].('' === $path ? '' : '/'.$path),
                    'name' => '' === $path ? $drive['name'] : null,
                ];
            }
        }

        return null;
    }

    /**
     * The spaces the user can reach: personal, project, and one "mountpoint"
     * per accepted share. A disabled project space still comes back, marked
     * deleted, and is left out — it cannot be opened.
     *
     * Asked of the Graph API when needed rather than stored on the connection:
     * it is one small GET per root listing or search, and a stored list would
     * go stale the moment someone is added to a space.
     *
     * @return list<array{id:string,type:string,name:string}>
     */
    private function drives(Integration $integration): array
    {
        $response = $this->request($integration, 'GET', $this->baseUrl($integration).self::MY_DRIVES, [
            'headers' => ['Accept' => 'application/json'],
        ]);

        try {
            $payload = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw $this->translate($e);
        }

        $drives = [];

        foreach (is_array($payload['value'] ?? null) ? $payload['value'] : [] as $drive) {
            $id = $drive['id'] ?? null;
            $type = $drive['driveType'] ?? null;
            $name = $drive['name'] ?? null;

            if (false === is_string($id) || false === $this->isDriveId($id) || false === is_string($type)
                || false === is_string($name) || true === isset($drive['root']['deleted'])) {
                continue;
            }

            $drives[] = ['id' => $id, 'type' => $type, 'name' => $name];
        }

        return $drives;
    }

    /**
     * Ids look like "{uuid}${uuid}", with "!…" and colons for a share. What
     * matters is that nothing in one can climb out of the URL segment it is
     * put into: no slash, and not a dot segment — "space:.." must not name
     * the spaces tree's parent.
     */
    private function isDriveId(string $id): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9$!:._-]+$/', $id) && 1 === preg_match('/[A-Za-z0-9]/', $id);
    }

    /**
     * A project space id split into the space and the path below it, or null
     * for an id in the personal tree.
     *
     * @return array{string,string}|null
     */
    private function splitSpace(string $id): ?array
    {
        if (false === str_starts_with($id, self::SPACE_PREFIX)) {
            return null;
        }

        [$drive, $path] = array_pad(explode('/', substr($id, strlen(self::SPACE_PREFIX)), 2), 2, '');

        if (false === $this->isDriveId($drive)) {
            throw new IntegrationException('OpenCloud could not find that folder or file.');
        }

        return [$drive, $path];
    }

    // ── Space-aware addressing ────────────────────────────────────────────────

    protected function davUrl(Integration $integration, string $path): string
    {
        $space = $this->splitSpace($path);

        if (null === $space) {
            return parent::davUrl($integration, $path);
        }

        [$drive, $rest] = $space;

        return $this->baseUrl($integration).self::SPACES_PATH.'/'.rawurlencode($drive).'/'
            .('' === $rest ? '' : $this->encodePath($rest));
    }

    protected function davRoot(Integration $integration, string $id = ''): string
    {
        $space = $this->splitSpace($id);

        if (null === $space) {
            return parent::davRoot($integration, $id);
        }

        return $this->basePath($integration).self::SPACES_PATH.'/'.$space[0].'/';
    }

    protected function qualify(string $listed, string $relative): string
    {
        $space = $this->splitSpace($listed);

        if (null === $space) {
            return $relative;
        }

        return self::SPACE_PREFIX.$space[0].('' === $relative ? '' : '/'.$relative);
    }

    protected function isTreeRoot(string $id): bool
    {
        $space = $this->splitSpace($id);

        return '' === $id || (null !== $space && '' === $space[1]);
    }

    /** @return list<Entry> */
    protected function breadcrumb(Integration $integration, string $folder): array
    {
        $space = $this->splitSpace($folder);

        if (null === $space) {
            return parent::breadcrumb($integration, $folder);
        }

        [$drive, $rest] = $space;
        $root = self::SPACE_PREFIX.$drive;
        $name = $drive;

        foreach ($this->drives($integration) as $known) {
            if ($known['id'] === $drive) {
                $name = $known['name'];
            }
        }

        $crumbs = [Entry::folder('', $this->provider()->label()), Entry::folder($root, $name)];
        $walked = $root;

        foreach ('' === $rest ? [] : explode('/', $rest) as $segment) {
            $walked .= '/'.$segment;
            $crumbs[] = Entry::folder($walked, $segment);
        }

        return $crumbs;
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

        if (true === $this->isTreeRoot($path)) {
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

    /** In a project space the path is from the space's top, named by space_ref. */
    protected function shareTarget(string $id): array
    {
        $space = $this->splitSpace($id);

        if (null === $space) {
            return parent::shareTarget($id);
        }

        return ['path' => '/'.$space[1], 'space_ref' => $space[0]];
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
