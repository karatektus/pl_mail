<?php

declare(strict_types=1);

namespace App\Domain\Enum\Integration;

/**
 * What a driver can actually do against its service.
 *
 * Every affordance in the UI keys off this rather than off the provider, so a
 * service that gains or loses an ability changes in exactly one place. A
 * provider without Upload never appears in the "Save to…" menu or in the
 * saveToIntegration filter action; one without ShareLink offers "attach a
 * copy" only, which is why Immich files above the attachment cap simply cannot
 * be attached.
 */
enum Capability: string
{
    /** Can enumerate folders/albums and their contents. */
    case Browse = 'browse';

    /** Can fetch a file's bytes. */
    case Download = 'download';

    /** Can store a new file. */
    case Upload = 'upload';

    /** Can mint a public URL for an existing file. */
    case ShareLink = 'shareLink';

    /**
     * Can render a cheap preview image without fetching the original. The
     * picker shows a grid of these instead of a list of filenames, which is
     * the difference between usable and useless on a photo service.
     */
    case Thumbnail = 'thumbnail';

    /**
     * Can find files by a text query rather than only by walking folders.
     *
     * Gates the picker's search box. A driver declaring this must implement
     * SearchableDriverInterface — the capability is what the UI asks, the
     * interface is what the code calls.
     */
    case Search = 'search';
}
