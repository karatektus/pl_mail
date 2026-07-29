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
}
