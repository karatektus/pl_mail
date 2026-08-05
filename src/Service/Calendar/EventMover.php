<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use DateTimeImmutable;

/**
 * Putting an event at a different time, when that is the only thing that
 * changed.
 *
 * A drag on the time-grid is an edit with no form behind it: the user has said
 * where the block now starts and how long it now is, and nothing else. That
 * makes it a different shape of write from the editor's save, which posts every
 * field and reads them back. Expressing a drag as "a save whose other fields
 * happen to equal the stored ones" is the obvious shortcut and it is how a
 * description gets dropped, or an all-day flag flipped, because the drag had no
 * field for it and the writer rebuilds the canonical object from what it was
 * given. So the unchanged fields are carried across here, explicitly, in one
 * place both scopes go through.
 *
 * **It is the same two answers the editor gives, on purpose.** Dragging one
 * occurrence of a series raises exactly the question the editor raises — this
 * one, or all of them — and it is answered by the same two services, so a drag
 * and a save that mean the same thing cannot produce different data:
 *
 *   This occurrence  → EventInstanceEditor::edit(), which files a patch keyed
 *                      on where the RULE put the instance. Nothing about the
 *                      series row changes.
 *
 *   All of them      → CalendarEventWriter::write() with the times run through
 *                      EventInstanceEditor::seriesTimesFor(), which applies the
 *                      DIFFERENCE the drag made rather than its absolute value.
 *                      Writing the dragged block's times as the series' own is
 *                      the bug that once rebased a weekly meeting onto whatever
 *                      day its fifth occurrence was clicked on, and dragging is
 *                      a far easier way to trigger it than the editor was.
 *
 * **Every path marks the event for push.** A drag that changes the database and
 * never reaches Google is not a small bug — it is a silent revert, because the
 * next pull finds a remote that still says the old time and writes it back over
 * the local row. edit() marks the event itself; the series branch marks it here
 * by the same call a form save makes, so there is one answer to "was this
 * queued" rather than one per entry point.
 *
 * Does not flush, like everything else in this layer; it joins the caller's
 * unit of work.
 */
final readonly class EventMover
{
    public function __construct(
        private CalendarEventWriter $writer,
        private EventInstanceEditor $instances,
    ) {
    }

    /**
     * Move and/or resize one event.
     *
     * $instance is the occurrence that was dragged, or null when the event has
     * none to speak of — a one-off, or a request that named an instance this
     * series does not have. $seriesScope is the user's answer to the this-or-all
     * question, and it is only ever consulted when there is an instance for it
     * to be about: with none, "this occurrence" and "the series" name the same
     * thing and the series branch is the one that can actually write.
     */
    public function move(
        CalendarEvent            $event,
        ?CalendarEventOccurrence $instance,
        bool                     $seriesScope,
        DateTimeImmutable        $startsAt,
        DateTimeImmutable        $endsAt,
    ): void {
        // Before either branch, and unconditionally: a drag is a decision the
        // user made, and an extracted event that has been moved by hand must
        // stop being overwritten by the next mail about the same booking. This
        // is the same call the editor's save makes for the same reason.
        $this->writer->markUserEdited($event);

        if (false === $seriesScope && null !== $instance) {
            // The instance's OWN title, not the series'. An instance that was
            // renamed and then dragged would otherwise have the rename quietly
            // undone by the move — edit() writes the title it is handed, and
            // handing it the series' would be a claim that the rename never
            // happened. titleOf() answers the series' title when there is no
            // rename, so the ordinary case is unaffected.
            $this->instances->edit(
                $event,
                $instance,
                $this->instances->titleOf($event, $instance),
                $startsAt,
                $endsAt,
            );

            return;
        }

        [$seriesStart, $seriesEnd] = null === $instance
            ? [$startsAt, $endsAt]
            : $this->instances->seriesTimesFor($event, $instance, $startsAt, $endsAt);

        $this->writer->write(
            event:          $event,
            // Where it already is. A drag moves an event in time and never
            // between calendars — the grid has no axis for that — so passing
            // anything else here would be inventing a change the user did not
            // make.
            calendar:       $event->calendar ?? throw new \LogicException('An event being moved has no calendar.'),
            user:           $event->usr ?? throw new \LogicException('An event being moved has no owner.'),
            title:          (string) $event->title,
            startsAt:       $seriesStart,
            endsAt:         $seriesEnd,
            timeZone:       $event->timeZone,
            isAllDay:       $event->isAllDay,
            location:       $event->location,
            // Read back out of the canonical object, because write() rebuilds
            // that object from its arguments and drops anything not passed. The
            // column-backed fields above survive by being properties; the
            // description only exists in the JSCalendar and would be erased by
            // a move that did not carry it.
            description:    $this->descriptionOf($event),
            status:         $event->status,
            recurrenceRule: $this->recurrenceRuleOf($event),
        );

        $this->writer->markLocallyChanged($event);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function descriptionOf(CalendarEvent $event): ?string
    {
        $description = $event->jscalendar['description'] ?? null;

        return true === is_string($description) && '' !== $description ? $description : null;
    }

    /**
     * The rule this series repeats by, or null for a one-off.
     *
     * Dropping this is how a drag would turn a weekly standup into a single
     * meeting: write() states `recurrenceRules` only when it is handed one, so
     * a move that did not carry the rule forward would silently un-repeat the
     * event and take every future occurrence off the calendar with it.
     *
     * @return array<string,mixed>|null
     */
    private function recurrenceRuleOf(CalendarEvent $event): ?array
    {
        $rules = $event->jscalendar['recurrenceRules'] ?? null;

        if (false === is_array($rules)) {
            return null;
        }

        $first = reset($rules);

        return true === is_array($first) ? $first : null;
    }
}
