<?php

declare(strict_types=1);

namespace App\Domain\Enum\Integration;

/**
 * What an external service is *for*, which decides where it is offered.
 *
 * Integration started as one idea — "a file or photo service plMail can attach
 * from and save to" — and every UI that lists providers walked
 * `Provider::cases()` on that assumption. A calendar connection belongs on the
 * same entity (it is the same shape: a base URL, a credential, a health check,
 * a settings bag) and emphatically not in the same lists: a CalDAV server has
 * nothing to attach and must never appear in "Save to…".
 *
 * So the distinction is declared once here rather than inferred from an empty
 * capability list, which would be true today and quietly wrong the first time a
 * calendar service also served files.
 */
enum ServiceKind: string
{
    /** Files and photos: the picker, "Save to…", the attachment paths. */
    case Files = 'files';

    /** Calendars mirrored into plMail and written back to. */
    case Calendar = 'calendar';
}
