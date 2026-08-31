<?php

declare(strict_types=1);

namespace App\Entity\Calendar;

use App\Domain\Enum\Calendar\CalendarChangeKind;
use App\Domain\Trait\TimestampableTrait;
use App\Repository\Calendar\CalendarChangeLogRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only log of what happened to a user's events, and the state token both
 * calendar protocols count from.
 *
 * The autoincrement primary key *is* the token, exactly as in jmap_change_log:
 * a reader's position is the highest sequence it has seen, and "what changed"
 * is `sequence > since`. CalendarState's docblock asks for this table in so many
 * words — it explains that calendars have no recorder, that a log covering some
 * of the writers "is a lie with a number on it", and that the fixed state string
 * exists only until one arrives.
 *
 * ── Why not jmap_change_log, which CalendarState suggested ────────────────
 *
 * That table is keyed `(account_id, object_type, sequence)`, and neither half of
 * the key fits a calendar.
 *
 * **A calendar has no account.** CalendarAccountResolver says it outright: a
 * plMail Calendar is the user's, and the mail account is only ever an optional
 * owner of the one calendar extraction files into. It also names the state that
 * breaks the reuse — "a user can delete their last account and keep a calendar".
 * Keyed by account_id, that user's calendar becomes untrackable, and CalDAV,
 * which has no notion of a mail account at all, would stop working for them.
 *
 * **CalDAV counts per collection, not per account.** RFC 6578 runs
 * sync-collection against one collection, so the log has to answer "what changed
 * in calendar 4 since token 91". jmap_change_log has nowhere to put the 4.
 *
 * One table answers both readers because they filter the same rows differently:
 * CalDAV by calendar_id, JMAP by user_id across all of them. Two tables would
 * have to agree about ordering, and two sequences cannot.
 *
 * ── Why a tombstone carries the uid ───────────────────────────────────────
 *
 * A CalDAV client names a resource by a URL built from the event's UID, so a
 * deletion has to report the UID of a row that no longer exists to be read. The
 * same is true of calendar_id: after the event is gone there is nothing left to
 * join to. Both are therefore copied here at write time rather than resolved at
 * read time — this row is the only surviving record of a deleted event, which is
 * the whole reason it is written.
 *
 * Ids are scalars, not relations, for the reason jmap_change_log gives: these
 * rows are written from a Doctrine listener during flush, where holding entity
 * references is the documented footgun. A plain id sidesteps it, and a
 * ManyToOne to an event being deleted in the same flush could not be held at
 * all.
 */
#[ORM\Entity(repositoryClass: CalendarChangeLogRepository::class)]
#[ORM\Table(name: 'calendar_change_log')]
// The CalDAV read: one collection, everything after a token, in order. Calendar
// leads because sync-collection always names one and never scans across them.
#[ORM\Index(name: 'idx_calendar_change_log_calendar_sequence', columns: ['calendar_id', 'sequence'])]
// The JMAP read: CalendarEvent/changes spans every calendar the user has, so it
// filters on the user and takes the same ordering from the primary key.
#[ORM\Index(name: 'idx_calendar_change_log_user_sequence', columns: ['user_id', 'sequence'])]
#[ORM\HasLifecycleCallbacks]
class CalendarChangeLog
{
    use TimestampableTrait;

    /**
     * The token. Integer and unpruned, matching ChangeLog — see its note on the
     * 2.1-billion ceiling and on what switching to bigint would cost. Calendars
     * write far fewer rows than mail does, so whichever limit mail meets first
     * will be met here long after.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public private(set) ?int $sequence = null;

    #[ORM\Column(name: 'user_id')]
    public private(set) int $userId;

    /**
     * The collection the change happened in. An event moved between calendars
     * writes two rows — destroyed in the old, created in the new — because that
     * is what each collection's readers have to be told, and a single "updated"
     * row would leave the old collection claiming a resource it no longer has.
     */
    #[ORM\Column(name: 'calendar_id')]
    public private(set) int $calendarId;

    #[ORM\Column(name: 'event_id')]
    public private(set) int $eventId;

    /** RFC 5545 UID, copied so a tombstone still names its resource. */
    #[ORM\Column(name: 'event_uid', length: 255)]
    public private(set) string $eventUid;

    #[ORM\Column(name: 'change_kind', length: 16, enumType: CalendarChangeKind::class)]
    public private(set) CalendarChangeKind $changeKind;

    public function __construct(
        int $userId,
        int $calendarId,
        int $eventId,
        string $eventUid,
        CalendarChangeKind $changeKind,
    ) {
        $this->userId     = $userId;
        $this->calendarId = $calendarId;
        $this->eventId    = $eventId;
        $this->eventUid   = $eventUid;
        $this->changeKind = $changeKind;
        $this->createdAt  = new DateTimeImmutable();
    }
}
