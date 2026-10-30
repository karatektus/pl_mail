<?php

declare(strict_types=1);

namespace App\Service\Calendar\Ics;

use App\Domain\DTO\Calendar\IcsImportResult;
use App\Domain\DTO\Calendar\RemoteEvent;
use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\Sync\CalDav\CalDavEventConverter;
use Psr\Log\LoggerInterface;

/**
 * An .ics somebody uploaded, put onto a calendar they picked.
 *
 * The reading is not here — IcsDocumentReader splits the file into per-meeting
 * resources and CalDavEventConverter turns each into the JSCalendar the engine
 * stores, both of them the same classes the feed driver and the CalDAV driver
 * use. What is here is the only question an import asks that a sync does not:
 * **is this meeting already on this person's calendar, and if so, where?**
 *
 * ── The three answers, and why the third one refuses ──────────────────────
 *
 * **On the target calendar under the same UID: it is updated.** The UID is the
 * identity and the database enforces it (uniq_calendar_event_calendar_uid), so
 * this is not a policy so much as the only thing that can happen — writing a
 * second row would be a constraint violation, which is how an import of a file
 * exported an hour ago would otherwise become a 500. This is also what makes
 * export→import a round trip rather than a duplication.
 *
 * **On another of this user's calendars under the same UID: it is left alone,
 * and counted.** That population is not hypothetical: an invitation extracted
 * from the mailbox lands on the user's default calendar carrying the
 * organiser's own UID, and the same meeting mirrored from a connected calendar
 * carries it too. A user who then imports the organiser's .ics is holding the
 * third copy of one meeting. Writing it would produce a row the calendar draws
 * beside the one already there — EventClusterer merges copies into one chip
 * only while they *agree*, and an import that re-derives times from the file
 * will disagree with an event the user has since edited.
 *
 * Not "update the copy elsewhere" either, which is the other tempting answer:
 * the user picked a calendar, and moving or rewriting a row on a different one
 * is not what they asked for — least of all when the other one is a mirror,
 * where the rewrite would be pushed out to the provider.
 *
 * **Nothing at all: it is created.**
 *
 * ── Deliberate absences ───────────────────────────────────────────────────
 *
 * **SEQUENCE is not consulted.** EventReconciler refuses a mail claim that
 * carries an older revision than the row it would overwrite, because mail
 * arrives out of order and nobody asked for it. An import is neither: a person
 * chose this file and pressed a button, and refusing half of it because the
 * exporter wrote a lower SEQUENCE than the invitation did would be the import
 * silently doing nothing on the events that matter most.
 *
 * **Dismissals are not consulted.** EventSuppression stops re-extraction from
 * putting back an event the user threw away, because extraction runs by itself.
 * An explicit import is the user asking for it back, and honouring a
 * suppression there would be a file that imports everything except the one
 * event somebody is looking for, with nothing on screen to say why.
 *
 * **A read-only calendar is refused outright**, not filtered down to nothing.
 * The engine's promise is that a driver is never asked to push to one, and an
 * import that quietly wrote rows there would either break that promise or
 * create rows that can never leave — see the throw in import().
 *
 * Does not flush; it joins the caller's unit of work, like everything else in
 * Service/Calendar.
 */
final readonly class IcsImporter
{
    public function __construct(
        private IcsDocumentReader       $reader,
        private CalDavEventConverter    $converter,
        private CalendarEventRepository $events,
        private CalendarEventWriter     $writer,
        private LoggerInterface         $logger,
    ) {
    }

    /**
     * @param string $ics the uploaded file's bytes
     *
     * @throws CalendarSyncException when the bytes are not a calendar at all,
     *                               or the target does not accept writes —
     *                               both carry a sentence written for the
     *                               person who pressed the button
     */
    public function import(Calendar $calendar, User $user, string $ics): IcsImportResult
    {
        if (true === $calendar->isReadOnly) {
            throw new CalendarSyncPermanentException(
                'This calendar is a read-only mirror, so nothing can be added to it. Choose another calendar.',
            );
        }

        $document = $this->reader->read($ics);

        $imported = 0;
        $updated  = 0;
        $elsewhere = 0;
        $skipped  = 0;

        foreach ($this->reader->resources($document) as $uid => $resource) {
            // The UID is the resource id here, and legitimately so: a file has
            // no addresses in it, and the UID is the only name every component
            // in the group shares. Null etag, because a file has no version
            // marker either — which is why nothing below tries to skip an
            // unchanged event the way a pull does.
            $remote = $this->converter->toRemoteEvent($resource, $uid, null);

            if (null === $remote) {
                ++$skipped;

                continue;
            }

            $existing = $this->events->findOneByUid($calendar, $uid);

            if (null === $existing && true === $this->isOnAnotherCalendar($user, $calendar, $uid)) {
                ++$elsewhere;

                continue;
            }

            if (false === $this->write($calendar, $user, $existing, $remote)) {
                ++$skipped;

                continue;
            }

            null === $existing ? ++$imported : ++$updated;
        }

        return new IcsImportResult($imported, $updated, $elsewhere, $skipped);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Whether this meeting is already on some other calendar of this user's.
     *
     * findByUidForUser() rather than a second query per calendar: it is the
     * plural of findOneByUid() and the same query the duplicate-meeting merge
     * already rests on, so "what counts as the same meeting" has one answer
     * here and in the editor rather than two that can drift.
     *
     * The target calendar is excluded from the comparison rather than trusted
     * to be absent. The caller has already looked there and found nothing, but
     * this method is one line away from being called before that lookup, and a
     * row on the target counting as "elsewhere" would make the import a no-op
     * on exactly the events it is supposed to update.
     */
    private function isOnAnotherCalendar(User $user, Calendar $calendar, string $uid): bool
    {
        foreach ($this->events->findByUidForUser($user, $uid) as $copy) {
            if ($copy->calendar !== $calendar) {
                return true;
            }
        }

        return false;
    }

    /**
     * One meeting written onto the calendar, or refused as unusable.
     *
     * @return bool false when the resource said nothing that can be drawn —
     *              the converter promises an object and two instants together
     *              or not at all, and a row with neither is one no range query
     *              can ever return
     */
    private function write(
        Calendar       $calendar,
        User           $user,
        ?CalendarEvent $existing,
        RemoteEvent    $remote,
    ): bool {
        $jscalendar = $remote->jscalendar;
        $startsAt   = $remote->startsAt;
        $endsAt     = $remote->endsAt;

        if (null === $jscalendar || null === $startsAt || null === $endsAt) {
            $this->logger->info('IcsImport: skipped a component with nothing to draw', [
                'calendarId' => $calendar->id,
                'uid'        => $remote->uid,
            ]);

            return false;
        }

        $event = $existing ?? new CalendarEvent();

        // Before write(), which mints a UID for an event that has none. A
        // locally minted UID on an event the file already identifies would make
        // the row unmatchable by every other client — including the invitation
        // for the same meeting sitting in the mailbox, and including a second
        // import of the same file, which would then duplicate it.
        $event->uid = $remote->uid;

        $isNew = null === $event->id;

        $this->writer->write(
            event:             $event,
            calendar:          $calendar,
            user:              $user,
            title:             $this->title($jscalendar),
            startsAt:          $startsAt,
            endsAt:            $endsAt,
            timeZone:          $this->stringOrNull($jscalendar['timeZone'] ?? null),
            isAllDay:          true === ($jscalendar['showWithoutTime'] ?? false),
            location:          $this->location($jscalendar),
            description:       $this->stringOrNull($jscalendar['description'] ?? null),
            status:            EventStatus::tryFrom((string) ($jscalendar['status'] ?? '')) ?? EventStatus::Confirmed,
            recurrenceRule:    $this->recurrenceRule($jscalendar),
            jscalendarOverlay: $jscalendar,
        );

        // Ics rather than Manual: nobody typed this, an iCalendar document said
        // it, and the difference is what lets a later invitation about the same
        // meeting revise the row (EventSource::mayBeRewrittenByMail). Marked
        // Manual it would be frozen against the organiser's own updates.
        $event->source = EventSource::Ics;

        // After write(), so the event carries the calendar the mark is decided
        // against. Both are no-ops on a calendar that mirrors nothing, which is
        // where an import usually lands — but importing into a writable mirror
        // has to reach the provider, or the events exist here alone until the
        // next full read quietly deletes them again.
        true === $isNew
            ? $this->writer->markLocallyCreated($event)
            : $this->writer->markLocallyChanged($event);

        return true;
    }

    /**
     * JSCalendar makes title optional; a calendar row with no label is not
     * usable. The same fallback CalendarPuller uses, and the same word, so an
     * event that arrives by two routes is not called two things.
     *
     * @param array<string,mixed> $jscalendar
     */
    private function title(array $jscalendar): string
    {
        return $this->stringOrNull($jscalendar['title'] ?? null) ?? 'Untitled';
    }

    /**
     * The first named location, which is what CalendarEvent::$location projects
     * — JSCalendar allows several and the column holds one.
     *
     * @param array<string,mixed> $jscalendar
     */
    private function location(array $jscalendar): ?string
    {
        $locations = $jscalendar['locations'] ?? null;

        if (false === is_array($locations)) {
            return null;
        }

        foreach ($locations as $location) {
            if (false === is_array($location)) {
                continue;
            }

            $name = $this->stringOrNull($location['name'] ?? null);

            if (null !== $name) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $jscalendar
     *
     * @return array<string,mixed>|null
     */
    private function recurrenceRule(array $jscalendar): ?array
    {
        $rules = $jscalendar['recurrenceRules'] ?? null;

        if (false === is_array($rules) || false === is_array($rules[0] ?? null)) {
            return null;
        }

        return $rules[0];
    }

    /**
     * A JSCalendar object is decoded from somebody else's file, so any key can
     * hold anything — an uploaded .ics is one bad cast away from a TypeError
     * that turns a bad file into a 500 on a form.
     */
    private function stringOrNull(mixed $value): ?string
    {
        if (false === is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }
}
