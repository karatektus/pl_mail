<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;

/**
 * Talks to one external file or photo service.
 *
 * Implementations are auto-tagged app.integration_driver and resolved through
 * IntegrationDriverRegistry, the same shape as AccountSyncerInterface. Adding
 * a service means writing one of these and flipping isImplemented() on its
 * Provider case — nothing else in the application changes.
 *
 * Two contracts every implementation owes its callers:
 *
 *   Folder and file ids are opaque. Whatever a driver puts in an Entry::$id
 *   comes back to it untouched; no controller, template or other driver may
 *   parse one. A WebDAV path and an Immich UUID are both just strings here.
 *
 *   Every failure is an IntegrationException with a message fit to show a
 *   user. Transport exceptions, JSON errors and non-2xx statuses all get
 *   translated at the driver boundary, so callers never see an HTTP concern
 *   and never have to guess whether a null meant "empty" or "broken".
 *
 * A driver only has to implement the operations its Provider declares in
 * capabilities(). Anything outside that set may throw — callers are expected
 * to have checked first, and the UI never offers it.
 *
 * `verify()` comes from VerifiableDriverInterface rather than being declared
 * here: it is the one operation a connection of any kind owes, and a calendar
 * driver has to answer it without pretending to hold files.
 */
interface IntegrationDriverInterface extends VerifiableDriverInterface
{
    /**
     * One page of a folder's contents. A null folder means the root, which for
     * a photo service is the album list rather than a directory.
     *
     * @throws IntegrationException
     */
    public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing;

    /**
     * Fetch a file's bytes. Callers enforce the size cap before calling — the
     * driver holds the whole file in memory.
     *
     * @throws IntegrationException
     */
    public function download(Integration $integration, string $fileId): RemoteFile;

    /**
     * Store a local file on the service and return its new remote id. Reads
     * from the path rather than taking bytes, so saving a large attachment
     * streams instead of buffering.
     *
     * @throws IntegrationException
     */
    public function upload(
        Integration $integration,
        string $absolutePath,
        string $filename,
        string $mime,
        ?string $folderId = null,
    ): string;

    /**
     * A public URL for an existing file, or null where the service cannot mint
     * one without a heavier side effect than attaching should have. Only
     * called for providers declaring Capability::ShareLink.
     *
     * @throws IntegrationException
     */
    public function shareLink(Integration $integration, string $fileId): ?string;

    /**
     * A small preview image, or null if this file has none.
     *
     * Returned as bytes rather than a URL because every service puts its
     * previews behind the same credential as the originals — a URL would
     * either leak the credential into markup or 401 in the browser. The picker
     * serves these back through its own route instead. Only called for
     * providers declaring Capability::Thumbnail.
     *
     * @throws IntegrationException
     */
    public function thumbnail(Integration $integration, string $fileId): ?RemoteFile;
}
