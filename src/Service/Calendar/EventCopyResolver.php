<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\DTO\Calendar\EventCopy;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Repository\Calendar\CalendarRepository;

/**
 * Which calendars a meeting could be on, which of them it is on, and the rows a
 * save is therefore about to write.
 *
 * The editor asks one question — "which calendars is this on?" — and until now
 * it could only be answered about the calendars a copy already existed on. That
 * made the checkbox list able to say "stop updating this copy" and unable to
 * say "also put this on my work calendar", which is the thing people actually
 * want from a mail client that syncs calendars. So the list is now every
 * calendar the user owns, ticked where the meeting already is, and ticking an
 * empty one creates the copy there.
 *
 * **A copy carries the meeting's UID. It does not get one of its own.** This is
 * the decision the whole feature turns on, so here is the reasoning in full.
 * EventClusterer identifies a meeting by UID plus start instant, so two rows
 * with different UIDs are two meetings by construction: a copy minted its own
 * UID would draw a second chip on the same hour of the same day, forever, and
 * no later edit could ever merge them. The schema was built for the shared case
 * — uniq_calendar_event_calendar_uid scopes uniqueness to ONE calendar, and
 * every identity lookup in CalendarEventRepository is scoped to a calendar for
 * the same reason — so the same UID on a second calendar is legal rather than
 * tolerated. RFC 5546 already decided that a UID identifies the meeting across
 * calendars and mailboxes rather than identifying a row, which is why an
 * invitation's UID is stored verbatim; a copy of the meeting is the same
 * meeting, and re-minting would be claiming otherwise to every client that
 * reads the .ics. And it is what keeps updates working: a later message from
 * the organiser, an .ics re-import and a provider pull all match on UID, so a
 * copy with a private UID would look identical on screen while silently
 * receiving none of them.
 *
 * The cost is real and is accepted: two rows under one UID cannot be told apart
 * by UID alone. That was already true the day a meeting could arrive both
 * extracted from its invitation and mirrored from a provider, which is the case
 * this whole cluster mechanism exists for.
 *
 * **The UID is minted here, once per request, not by CalendarEventWriter per
 * row.** write() mints only for a row that has none, so creating one meeting on
 * three calendars would otherwise produce three UIDs and three chips the moment
 * it was saved — the exact bug the paragraph above exists to prevent.
 *
 * A calendar that already holds a row under this UID never gets a second one,
 * whatever the cluster thinks. A copy on a hidden calendar, or one that has
 * drifted out of agreement with the others, is not a member of the cluster and
 * so opens unticked — but it is still THE row for that calendar, so ticking it
 * writes it rather than inserting a duplicate that
 * uniq_calendar_event_calendar_uid would refuse with a 500.
 */
final readonly class EventCopyResolver
{
    public function __construct(
        private CalendarRepository      $calendars,
        private CalendarEventRepository $events,
        private EventClusterer          $clusterer,
        private CalendarEventWriter     $writer,
    ) {
    }

    /**
     * Every calendar the user has, each with this meeting's row on it.
     *
     * $event is null for an event that does not exist yet, and that is a
     * different question rather than a degenerate one: there is nothing to be a
     * copy of, so nothing is on any calendar and the tick marks where a new
     * event would land instead.
     *
     * Hidden calendars are listed. copiesOf() deliberately leaves a copy on one
     * out of the cluster — it was never drawn, so writing it would be an edit to
     * something not on screen — and that rule is kept by leaving the box
     * unticked, not by leaving the calendar out. Leaving it out is what would
     * turn "put this on my archive calendar too" into an insert that violates
     * uniq_calendar_event_calendar_uid.
     *
     * @return list<EventCopy>
     */
    public function optionsFor(?CalendarEvent $event, User $user): array
    {
        $calendars = $this->calendars->findForUser($user);
        $rows      = $this->rowsByCalendar($event, $user);
        $merged    = $this->mergedCalendarIds($event, $user);
        $landing   = null === $event ? $this->landingCalendar($calendars) : null;
        $uid       = $this->uidFor($event);

        $options = [];

        foreach ($calendars as $calendar) {
            $isChosen = null === $event
                ? $calendar === $landing
                : true === in_array((int) $calendar->id, $merged, true);

            $options[] = new EventCopy(
                $calendar,
                $rows[(int) $calendar->id] ?? $this->rowFor($calendar, $user, $uid, $event),
                // A read-only copy is offered and never ticked, so an ordinary
                // submit cannot name one. chosen() refuses it a second time.
                false === $calendar->isReadOnly && true === $isChosen,
            );
        }

        return $options;
    }

    /**
     * The copies a save is for: the calendars the user ticked, minus the ones
     * nothing may write.
     *
     * The read-only filter is here and not only in the template, for the reason
     * EventClusterer::chosen() gives — a disabled checkbox is a statement to a
     * browser, not a guarantee to a server, and a mirror of somewhere that does
     * not accept writes back must refuse a crafted request as flatly as it
     * refuses a click.
     *
     * The posted values are CALENDAR ids, where the old checkbox list posted
     * event ids. That rename is load-bearing rather than cosmetic: an editor
     * rendered before this change and submitted after it carries event ids in a
     * field nothing reads any more, so the save refuses with "nothing was
     * chosen" instead of reading row 42 as calendar 42 and writing a copy of the
     * meeting onto whatever calendar that turned out to be.
     *
     * @param list<EventCopy> $options
     * @param array<mixed>    $chosenIds as posted, so entirely untrusted
     *
     * @return list<EventCopy>
     */
    public function chosen(array $options, array $chosenIds): array
    {
        $wanted = [];

        foreach ($chosenIds as $chosenId) {
            if (true === is_scalar($chosenId)) {
                $wanted[] = (int) $chosenId;
            }
        }

        $chosen = [];

        foreach ($options as $option) {
            if (true === $option->calendar->isReadOnly) {
                continue;
            }

            if (true === in_array((int) $option->calendar->id, $wanted, true)) {
                $chosen[] = $option;
            }
        }

        return $chosen;
    }

    /**
     * The same set, reduced to the rows that exist — what a delete can act on.
     *
     * A ticked calendar with no copy on it has nothing to delete. Creating the
     * row in order to remove it is absurd, and refusing the whole delete because
     * one empty calendar happened to be ticked would be worse: the ticks are the
     * editor's default state, so a user who ticked a second calendar, changed
     * their mind and pressed Delete would be told to go and untick something
     * before the meeting could go away.
     *
     * @param list<EventCopy> $copies
     *
     * @return list<CalendarEvent>
     */
    public function existing(array $copies): array
    {
        $rows = [];

        foreach ($copies as $copy) {
            if (true === $copy->isNew()) {
                continue;
            }

            $rows[] = $copy->event;
        }

        return $rows;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Every row this user holds under the meeting's UID, keyed by the calendar
     * it is on.
     *
     * Unfiltered, unlike copiesOf(): this answers "is that calendar taken?",
     * which the unique constraint asks and which visibility and agreement have
     * no bearing on.
     *
     * @return array<int, CalendarEvent>
     */
    private function rowsByCalendar(?CalendarEvent $event, User $user): array
    {
        if (null === $event || '' === $event->uid) {
            return [];
        }

        $rows = [];

        foreach ($this->events->findByUidForUser($user, $event->uid) as $row) {
            if (null !== $row->calendar) {
                $rows[(int) $row->calendar->id] = $row;
            }
        }

        // The opened event wins its own calendar's slot outright. The query
        // above returns the same managed instance in every ordinary case, and
        // saying so costs nothing and removes the one case where it might not —
        // a row written and not yet flushed within this request.
        if (null !== $event->calendar) {
            $rows[(int) $event->calendar->id] = $event;
        }

        return $rows;
    }

    /**
     * The calendars holding a copy the chip actually merged, which is what opens
     * ticked.
     *
     * Through EventClusterer rather than through the rows above, so the list the
     * editor ticks is exactly the set of chips the user clicked on. Two
     * implementations of "the same meeting" would agree until one of them
     * learned about a sixth field.
     *
     * @return list<int>
     */
    private function mergedCalendarIds(?CalendarEvent $event, User $user): array
    {
        if (null === $event) {
            return [];
        }

        $ids = [];

        foreach ($this->clusterer->copiesOf($event, $user) as $copy) {
            if (null !== $copy->calendar) {
                $ids[] = (int) $copy->calendar->id;
            }
        }

        return $ids;
    }

    /**
     * Where a brand-new event lands: the default calendar, or the first one that
     * accepts writes.
     *
     * The fallback is not decoration. The tick is the only thing that says where
     * a new event goes now that the dropdown is gone, so a user whose default
     * flag never got set — an install predating the provisioner, a calendar
     * deleted since — would otherwise open the editor with nothing ticked and be
     * told "nothing was chosen" for pressing Save on a form they did not touch.
     *
     * **A hidden calendar is the last resort and never a preference**, which is
     * the whole of the silent-save defect. `findForUser()` lists every calendar
     * including the hidden ones — deliberately, so an existing copy on one can
     * still be reasoned about — but the views read `findVisibleForUser()`. So
     * where this method's fallback landed on a hidden calendar, Save wrote a
     * perfectly good row, answered 302, closed the dialog and put the user back
     * on a calendar that does not read the row it had just made. No error, no
     * refusal, nothing to see: the event existed and was unreachable through
     * every view in the application.
     *
     * Settings already refuses to hide the calendar that HOLDS the default flag,
     * for exactly this reason (CalendarSettingsController::toggleVisibility).
     * That guard is worth nothing to an account with no default flag at all,
     * because it is the flag it is written in terms of — and that is the same
     * state that had these accounts drawing the grid in UTC. Preferring a
     * visible calendar here closes the case the flag cannot speak for.
     *
     * A hidden calendar is still returned when there is no visible writable one,
     * because the alternative is an editor with nothing ticked and "nothing was
     * chosen" on Save. The save says so out loud instead — see
     * CalendarController::eventSave().
     *
     * @param list<Calendar> $calendars
     */
    private function landingCalendar(array $calendars): ?Calendar
    {
        $visible = null;
        $hidden  = null;

        foreach ($calendars as $calendar) {
            if (true === $calendar->isReadOnly) {
                continue;
            }

            if (false === $calendar->isVisible) {
                $hidden ??= $calendar;

                continue;
            }

            if (true === $calendar->isDefault) {
                return $calendar;
            }

            $visible ??= $calendar;
        }

        return $visible ?? $hidden;
    }

    /**
     * The UID every copy of this meeting shares — see the class docblock for why
     * a copy must not have one of its own.
     */
    private function uidFor(?CalendarEvent $event): string
    {
        if (null === $event || '' === $event->uid) {
            return $this->writer->newUid();
        }

        return $event->uid;
    }

    /**
     * The row this meeting would have on a calendar it is not on yet.
     *
     * Complete before anything writes it — calendar, owner and UID — so that
     * every reader gets the same answer to "what is the copy on this calendar?"
     * whether or not it has been persisted. Not persisted here and not added to
     * the calendar's collection, so an editor that is opened and closed leaves
     * nothing behind.
     *
     * **Its PROVENANCE is copied**, and only that: source, kind and confidence.
     * A copy of a meeting read out of a mail is the same meeting from the same
     * mail, and a row that says otherwise is cut off from its own updates —
     * EventReconciler refuses to revise anything whose source says a person
     * decided on it (EventSource::mayBeRewrittenByMail), so a copy defaulting to
     * Manual took the user's tick as "this is now mine" and then quietly ignored
     * the organiser's next reschedule. It also decides whether the copy can be
     * marked user-edited at all, and whether the chip wears the extraction icon
     * its sibling does.
     *
     * It carries nothing else, and the absence is deliberate. The participants
     * are not copied: a row on a synced calendar is pushed to the provider, and
     * pushing an attendee list is how a provider decides to send the invitation
     * again to everyone on it. Neither are recurrenceOverrides — the new copy
     * starts as the plain series, and an instance moved on the original before
     * the copy existed is drawn as its own chip until the user moves it on both,
     * which is the same honest disagreement any two copies show.
     */
    private function rowFor(Calendar $calendar, User $user, string $uid, ?CalendarEvent $of): CalendarEvent
    {
        $event           = new CalendarEvent();
        $event->uid      = $uid;
        $event->usr      = $user;
        $event->calendar = $calendar;

        if (null !== $of) {
            $event->source     = $of->source;
            $event->kind       = $of->kind;
            $event->confidence = $of->confidence;
        }

        return $event;
    }
}
