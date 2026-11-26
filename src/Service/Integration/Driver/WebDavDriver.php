<?php

declare(strict_types=1);

namespace App\Service\Integration\Driver;

use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;

/**
 * Any WebDAV file server (#547): FileBrowser Quantum, a Synology box,
 * `rclone serve webdav`, Apache mod_dav.
 *
 * Everything a picker needs is already in AbstractWebDavDriver — PROPFIND to
 * list, GET to download, PUT and MKCOL to save — so what is left here is where
 * the tree starts and what is missing.
 *
 * The tree starts at the address the user typed. Nextcloud and OpenCloud hang
 * every user's files under /remote.php/dav/files/{user}, and the parent class
 * builds that path; a plain server has no such convention, and FileBrowser
 * Quantum serves each source at /dav/<source>/ under whatever prefix the admin
 * chose. So the full collection URL is the base address —
 * https://host:8080/dav/cargobay/Show%20files/ — and ids are paths below it.
 *
 * Only RFC 4918 class 1 methods are sent. LOCK and PROPPATCH would be the
 * natural next things to reach for, and FileBrowser Quantum refuses both; a
 * save that needed them would fail on exactly the server this was written for.
 *
 * Not searchable and not shareable, on purpose: see Provider::capabilities().
 * shareLink() and thumbnail() exist because the interface asks for them, and
 * the capability gate keeps the picker from calling either.
 */
final readonly class WebDavDriver extends AbstractWebDavDriver
{
    protected function provider(): Provider
    {
        return Provider::WebDav;
    }

    protected function credentialName(): string
    {
        return 'password or token';
    }

    /**
     * FileBrowser Quantum takes an API token as the password and ignores the
     * username; a server with no users at all ignores both. Basic auth then
     * goes out as ":<token>", which is what such a server expects.
     */
    protected function usernameIsOptional(): bool
    {
        return true;
    }

    /**
     * A PROPFIND on the address itself, like the parent's, with two answers
     * made specific to the way this connection goes wrong.
     *
     * A 404 on the root is almost always the path, not the server: on
     * FileBrowser Quantum a misspelt source name in /dav/<source>/ is the
     * usual one. And a multistatus with no entry for the address it was asked
     * about means the server answered for some other tree — every WebDAV
     * server lists the collection itself first at Depth 1 — so the listing
     * would come back empty forever rather than fail. Better to say so while
     * the user is still looking at the address field.
     */
    public function verify(Integration $integration): void
    {
        try {
            $items = $this->propfind($integration, '');
        } catch (IntegrationException $e) {
            if (404 === $e->getStatus()) {
                throw new IntegrationException(
                    'The WebDAV server has nothing at that address. Check the path, for example the source name in /dav/<source>/.',
                    404,
                    $e,
                );
            }

            throw $e;
        }

        if ([] === $items) {
            throw new IntegrationException(
                'The WebDAV server answered, but not for that address. Use the full address of the folder to connect, including its path.',
            );
        }
    }

    /** No public links in WebDAV; Capability::ShareLink is not declared. */
    public function shareLink(Integration $integration, string $fileId): ?string
    {
        return null;
    }

    /** No previews in WebDAV; the picker draws a file icon instead. */
    public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
    {
        return null;
    }

    /**
     * The decoded path of the base address, which every href in a
     * multistatus body is compared against after hrefToPath() decodes it.
     * Decoded because the address usually carries an escaped space
     * ("Show%20files") and servers differ in which characters they escape.
     */
    protected function davRoot(Integration $integration, string $id = ''): string
    {
        return rawurldecode($this->basePath($integration)).'/';
    }

    protected function davUrl(Integration $integration, string $path): string
    {
        return $this->baseUrl($integration).'/'.('' === $path ? '' : $this->encodePath($path));
    }

    /**
     * RFC 4918 §5.2 has a collection's URL end in a slash, and a server that
     * holds to it (Apache mod_dav) answers the bare form with a redirect
     * rather than the listing. The root already ends in one.
     */
    protected function collectionUrl(Integration $integration, string $path): string
    {
        return '' === $path ? $this->davUrl($integration, '') : $this->davUrl($integration, $path).'/';
    }

    /**
     * A refused token is the common case on a token-only server, and the
     * reason is nearly always that it expired or was revoked; a 403, by
     * contrast, comes with valid credentials and means the account may not
     * do this here.
     */
    protected function messageForStatus(int $status): string
    {
        return match ($status) {
            401     => 'The WebDAV server refused the password or token. A token may have expired or been revoked.',
            403     => 'The WebDAV server does not allow this account to do that here.',
            default => parent::messageForStatus($status),
        };
    }
}
