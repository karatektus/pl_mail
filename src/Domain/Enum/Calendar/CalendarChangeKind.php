<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * What happened to an event, as a change log row records it.
 *
 * The three cases JMAP's `/changes` answer is shaped from and the two a CalDAV
 * `sync-collection` REPORT needs — a destroyed row becomes a `404` status in
 * the multistatus, a created or updated one becomes a `200` with an href the
 * client then fetches.
 *
 * Named for the calendar rather than reusing App\Jmap\State\ChangeType, which
 * carries the same three words. That enum belongs to a delivery layer; an
 * entity under App\Entity may not depend on one, and the log is written by a
 * Doctrine listener that has no idea whether the read will arrive over JMAP or
 * CalDAV. The duplication is three lines and it keeps the arrow pointing the
 * right way.
 */
enum CalendarChangeKind: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Destroyed = 'destroyed';
}
