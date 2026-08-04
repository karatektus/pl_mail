<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use DateTimeImmutable;

/**
 * One event as it exists at the remote, or the news that it no longer does.
 *
 * Carries JSCalendar (RFC 8984) rather than each provider's own shape, and that
 * is the single decision this whole contract rests on. CalendarEvent already
 * stores JSCalendar as its canonical truth, CalendarEventWriter already
 * projects columns out of one, and iCalendar converts into one in both
 * directions — so a driver that maps Google's `Event` resource or a VEVENT into
 * this hands the engine something it can write unchanged. The alternative, a
 * lowest-common-denominator struct of title/start/end, throws away
 * participants, alerts, links and recurrenceOverrides on the way in, and the
 * loss is silent, which is the worst kind.
 *
 * A deletion is a RemoteEvent too, with isDeleted true and no object. Every
 * provider reports removals inside the same change feed as edits, and a second
 * list of ids would let the two arrive in the wrong order — an event deleted
 * and re-created with the same uid then applies its own tombstone last.
 *
 * startsAt and endsAt sit beside the object although both are derivable from
 * it. JSCalendar times are LocalDateTime plus a zone name and a duration, and
 * deriving instants from that means parsing an ISO 8601 duration and resolving
 * a zone — the driver has already done it to read the provider's response, and
 * making the engine do it again is two implementations of one conversion.
 *
 * **One instance of a series is not an event of its own.** Google returns a
 * moved occurrence as its own resource carrying `recurringEventId`, Graph as a
 * calendarView entry of `type: exception`, and both of them mean "the series is
 * still one event; this one instance is different". Said with $seriesRemoteId
 * and $recurrenceId, which turn it into a JSCalendar recurrenceOverride on the
 * master rather than a second row — a second row is a duplicate on the day the
 * instance moved to, next to a series that still draws it at its original time.
 */
final readonly class RemoteEvent
{
    /**
     * @param string                   $remoteId   opaque at the provider, stable
     *                                             across edits, and the identity
     *                                             the engine matches on first
     * @param string|null              $etag       the remote's own version
     *                                             marker — an ETag, Google's
     *                                             `etag`, Graph's
     *                                             `@odata.etag`, a CalDAV
     *                                             getetag. Opaque: compared for
     *                                             equality, never ordered.
     *                                             Null where the provider has
     *                                             none, which makes every pull
     *                                             of that event a write.
     * @param string                   $uid        the iCalendar UID. Distinct
     *                                             from $remoteId on purpose:
     *                                             the UID is what an invite in
     *                                             the mailbox and the same
     *                                             meeting on the calendar have
     *                                             in common, and it is how the
     *                                             engine avoids a duplicate
     *                                             when both arrive.
     * @param bool                     $isDeleted  true means gone at the remote;
     *                                             $jscalendar is then null and
     *                                             only $remoteId and $uid are
     *                                             meaningful
     * @param array<string,mixed>|null $jscalendar the canonical object, null iff
     *                                             $isDeleted
     * @param DateTimeImmutable|null   $startsAt   UTC instant, null iff
     *                                             $isDeleted
     * @param DateTimeImmutable|null   $endsAt     UTC, exclusive, null iff
     *                                             $isDeleted
     * @param string|null              $seriesRemoteId
     *                                             the $remoteId of the recurring
     *                                             event this is one instance of.
     *                                             Non-null makes this an
     *                                             override: the engine files it
     *                                             under the master rather than
     *                                             writing a row for it, and
     *                                             never creates the master from
     *                                             it. Null for everything else,
     *                                             including the master itself.
     * @param DateTimeImmutable|null   $recurrenceId
     *                                             the instance's ORIGINAL start
     *                                             as a UTC instant — where the
     *                                             rule put it, never where it
     *                                             was moved to, because that is
     *                                             the only name it keeps.
     *                                             Required with
     *                                             $seriesRemoteId and
     *                                             meaningless without it.
     */
    public function __construct(
        public string             $remoteId,
        public ?string            $etag,
        public string             $uid,
        public bool               $isDeleted = false,
        public ?array             $jscalendar = null,
        public ?DateTimeImmutable $startsAt = null,
        public ?DateTimeImmutable $endsAt = null,
        public ?string            $seriesRemoteId = null,
        public ?DateTimeImmutable $recurrenceId = null,
    ) {
    }

    /**
     * Whether this describes one instance of a series rather than an event.
     *
     * A method rather than a property because it is a question about two fields
     * at once, and because the pair is the fact: an override with no recurrence
     * id has no key to be filed under, and a recurrence id with no series has no
     * event to be filed on. Either alone is a driver bug, and answering false to
     * both is what makes it a row the engine can still write instead of a patch
     * it silently drops.
     */
    public function isSeriesInstance(): bool
    {
        return null !== $this->seriesRemoteId && null !== $this->recurrenceId;
    }

    /**
     * A tombstone, for the common case where a driver knows nothing about a
     * removed event but its id.
     *
     * The UID defaults to the remote id rather than being required. Google's
     * delta feed answers a cancelled event with its resource id and a status,
     * and no iCalendar UID at all — demanding one would make every driver
     * invent the same placeholder.
     */
    public static function deleted(string $remoteId, string $uid = ''): self
    {
        return new self(
            remoteId:  $remoteId,
            etag:      null,
            uid:       '' === $uid ? $remoteId : $uid,
            isDeleted: true,
        );
    }

    /**
     * One instance of a series is off, and the rest of it is not.
     *
     * Distinct from deleted() because the recovery is the opposite one: a
     * deletion removes the local row, and this must not — the series is alive
     * and only one of its instances is cancelled, so it becomes
     * `{"excluded": true}` in the master's recurrenceOverrides. Passed to
     * deleted() by mistake it would find no row (an instance has never been
     * one), do nothing, and leave the cancelled instance on screen for good.
     *
     * Carries no etag: the version marker belongs to the resource the provider
     * removed, and nothing will ever compare against it again.
     */
    public static function deletedInstance(
        string            $remoteId,
        string            $seriesRemoteId,
        DateTimeImmutable $recurrenceId,
    ): self {
        return new self(
            remoteId:       $remoteId,
            etag:           null,
            uid:            $remoteId,
            isDeleted:      true,
            seriesRemoteId: $seriesRemoteId,
            recurrenceId:   $recurrenceId,
        );
    }
}
