<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Repository\Calendar\CalendarEventOccurrenceRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Changing one occurrence of a recurring event without changing the rest.
 *
 * A series is stored as one row and a rule, so "this event" cannot be an edit
 * of anything — there is no row for the third Tuesday to edit. It is a patch
 * filed against the series under the LocalDateTime the rule originally put that
 * instance at, which is what RFC 8984 calls a recurrenceOverride and what
 * RecurrenceMaterialiser reads when it draws the occurrences. So this class
 * writes patches and nothing else; CalendarEventWriter::overrideInstances() is
 * the primitive, and going through it is what keeps the occurrences in step
 * with the object they are drawn from.
 *
 * **A patch is a partial, and that is the whole discipline here.** It carries
 * `start`, `duration`, `title` and `status` — the four things an occurrence row
 * can actually draw — and nothing else. Writing a whole event object into the
 * map, which is the obvious shortcut when the editor has already posted every
 * field, would state a location, a description and an all-day flag for that one
 * instance that nothing reads and that the next reader has no way to tell from a
 * decision the user made. `title` is written only when it differs from the
 * series', for the same reason: a patch that repeats the series' own title is a
 * claim that this instance was renamed.
 *
 * The key is the instance's ORIGINAL start, never where it was moved to, and it
 * is spelled by RecurrenceRuleConverter::overrideKey() rather than here. That is
 * what makes a second edit of the same instance update its patch instead of
 * stacking a new one beside it — the original start does not move, so the second
 * edit lands on the same key and array_merge replaces it.
 *
 * Cancelling one instance is `{"excluded": true}`, and that spelling belongs to
 * RecurrenceRuleConverter::exclusionOverrides() too. Writing it by hand here
 * would be the second place that has to be right about the one override value
 * whose only job is to be exactly right.
 *
 * **What reaches a remote, today.** The patch lives in the master's JSCalendar,
 * so marking the event locally changed is what queues the push, and that is done
 * here rather than by the caller: unlike write(), nothing on this path is ever
 * the sync engine applying what it just read — CalendarPuller calls
 * overrideInstances() directly — so there is no case where marking would push a
 * remote's own data back at it. What the push then carries depends entirely on
 * the provider:
 *
 *   CalDAV round-trips it. CalDavEventConverter::toIcs() writes each patch as an
 *   override VEVENT carrying the same UID and a RECURRENCE-ID, and each
 *   exclusion as an EXDATE on the master, so a moved or cancelled instance
 *   arrives at the server as the instance it is.
 *
 *   Google and Graph do not, yet. Both model a moved instance as a separate
 *   resource — a Google instance under the series' id, a Graph exception under
 *   the series' event — and neither GoogleEventMapper::toGoogleEvent() nor
 *   GraphEventMapper::toGraphEvent() writes one: they send the master's fields
 *   and its recurrence rule alone. So a per-instance change on such a calendar
 *   is pushed as a series update that says nothing about the instance, the
 *   remote leaves every occurrence where it was, and the change is visible in
 *   plMail only. It is not lost — CalendarEventWriter::write() preserves
 *   recurrenceOverrides, and a pull that brings no instance exceptions for the
 *   series never calls overrideInstances() at all — but a later FULL read that
 *   does carry an exception for the same series replaces the whole map
 *   (overrideInstances($master, $patches, replaceExisting: true)) and the local
 *   patch goes with it. Closing that means writing the instance resource in each
 *   driver, which is those drivers' work and not this class's.
 */
final readonly class EventInstanceEditor
{
    public function __construct(
        private CalendarEventOccurrenceRepository $occurrences,
        private CalendarEventWriter               $writer,
        private RecurrenceMaterialiser            $materialiser,
        private RecurrenceRuleConverter           $recurrence,
    ) {
    }

    /**
     * The instance a request names, or null when it names none.
     *
     * $recurrenceId is the instance's original start as a UTC instant, which is
     * how a chip carries it in the editor's URL and how the editor posts it
     * back. Total, because all of it arrives from a request: a value that is not
     * an instant, or is one that this series has no occurrence at, answers null
     * and every caller falls back to meaning the whole series. That fallback is
     * the safe direction — the series edit is what this application did before
     * "this event" existed, so a stale form or a hand-edited parameter gets the
     * old behaviour rather than a patch keyed on a guess.
     */
    public function instance(CalendarEvent $event, string $recurrenceId): ?CalendarEventOccurrence
    {
        if ('' === $recurrenceId || false === $event->isRecurring) {
            return null;
        }

        try {
            $instant = new DateTimeImmutable($recurrenceId, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        // Converted as well as constructed in UTC, and both are needed: the
        // constructor's zone applies only to a string that names none of its
        // own, so a value arriving with an offset on it would otherwise be
        // handed to Doctrine in that offset and match no row.
        return $this->occurrences->findOneByRecurrence($event, $instant->setTimezone(new DateTimeZone('UTC')));
    }

    /**
     * How one instance is named on the wire — in the URL a chip opens the editor
     * with, and in the hidden field the editor posts back.
     *
     * Its ORIGINAL start, as a UTC instant; the empty string for no instance,
     * which is what a one-off event and the new-event form both are. instance()
     * reads anything PHP recognises as an instant, so the format below is a
     * spelling rather than a contract — what is a contract is that the value is
     * UTC and is where the rule put the instance, never where it was moved to.
     * calendar/_event_chip.html.twig writes the same spelling with Twig's date
     * filter, which is the only other producer.
     */
    public function identify(?CalendarEventOccurrence $instance): string
    {
        $recurrenceId = $instance?->recurrenceId;

        if (null === $recurrenceId) {
            return '';
        }

        return $recurrenceId->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * What the editor shows in its title field for one instance: the name this
     * instance was given, or the series' when it was never given one.
     *
     * Read off the stored patch rather than off the occurrence row, because the
     * row has no title column — an occurrence carries where it is, not what it
     * is called. Without this, opening a renamed instance a second time shows
     * the series' title and saving it writes that title back as the instance's,
     * quietly undoing the rename.
     */
    public function titleOf(CalendarEvent $event, CalendarEventOccurrence $instance): string
    {
        $patch = $this->patchFor($event, $instance);
        $title = $patch['title'] ?? null;

        return true === is_string($title) && '' !== $title ? $title : (string) $event->title;
    }

    /**
     * The series' new times when the editor was opened on ONE occurrence and
     * the user chose "all events" anyway.
     *
     * The editor is prefilled with the instance's times, because it was opened
     * from that instance's chip and "this event" has to move the occurrence
     * that was clicked. That makes the posted fields useless as the series'
     * times: saving them verbatim rebases the whole series onto the clicked
     * day, so renaming a weekly meeting from its fifth occurrence quietly moved
     * it four weeks later. Every calendar application answers this by applying
     * the DIFFERENCE the user made rather than the absolute value they saw, and
     * so does this.
     *
     * The dominant case falls out exactly: change only the title and the shift
     * is zero, so the series keeps the times it already had, to the second.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public function seriesTimesFor(
        CalendarEvent           $event,
        CalendarEventOccurrence $instance,
        DateTimeImmutable       $startsAt,
        DateTimeImmutable       $endsAt,
    ): array {
        // Timestamps rather than DateInterval: both sides are instants, the
        // answer wanted is an elapsed amount, and a wall-clock interval across
        // a DST boundary is not the same quantity going in as coming out.
        $shift    = $startsAt->getTimestamp() - $instance->startsAt->getTimestamp();
        $duration = $endsAt->getTimestamp() - $startsAt->getTimestamp();

        $seriesStart = $event->startsAt->modify(sprintf('%+d seconds', $shift));

        return [$seriesStart, $seriesStart->modify(sprintf('%+d seconds', $duration))];
    }

    /**
     * Move, lengthen or rename one instance.
     *
     * $startsAt and $endsAt are where the instance is going, as instants; the
     * patch states the start as a LocalDateTime in the SERIES' zone, because
     * that is the zone RecurrenceMaterialiser reads it back in. An instance
     * moved while its editor was open in another zone would otherwise land at
     * the right wall-clock time in the wrong place.
     */
    public function edit(
        CalendarEvent           $event,
        CalendarEventOccurrence $instance,
        string                  $title,
        DateTimeImmutable       $startsAt,
        DateTimeImmutable       $endsAt,
    ): void {
        $zone = $this->materialiser->zoneOf($event);

        // A JSCalendar LocalDateTime — no offset, no trailing Z (RFC 8984
        // §4.1.2) — which is the same shape overrideKey() writes and the same
        // shape CalendarPuller::patchOf() gives an instance arriving from a
        // remote. RecurrenceMaterialiser reads all of them the same way.
        $patch = [
            '@type'    => 'Event',
            'start'    => $startsAt->setTimezone($zone)->format('Y-m-d\TH:i:s'),
            'duration' => $this->writer->isoDuration($endsAt->getTimestamp() - $startsAt->getTimestamp()),
        ];

        // Only when it differs. A patch that repeats the series' own title says
        // this instance was renamed, and a later rename of the series would then
        // leave this one instance still carrying the old name with nothing on
        // screen to explain why.
        if ($title !== (string) $event->title) {
            $patch['title'] = $title;
        }

        // Merged, not replaced: the other instances' patches are other
        // decisions the user made and this edit says nothing about them.
        $this->writer->overrideInstances($event, [$this->keyOf($event, $instance, $zone) => $patch]);
        $this->writer->markLocallyChanged($event);
    }

    /**
     * Take one instance off the series.
     *
     * The exclusion is built by RecurrenceRuleConverter, which owns the spelling
     * — `{"excluded": true}` is the one override value that has to be exactly
     * right, because anything else in that slot is an instance that keeps being
     * drawn after it was called off.
     */
    public function cancel(CalendarEvent $event, CalendarEventOccurrence $instance): void
    {
        $this->writer->overrideInstances($event, $this->recurrence->exclusionOverrides(
            [$this->originalStartOf($event, $instance)],
            $this->materialiser->zoneOf($event),
        ));

        $this->writer->markLocallyChanged($event);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The patch already filed against one instance, if there is one.
     *
     * @return array<string,mixed>
     */
    private function patchFor(CalendarEvent $event, CalendarEventOccurrence $instance): array
    {
        $overrides = $event->jscalendar['recurrenceOverrides'] ?? null;

        if (false === is_array($overrides)) {
            return [];
        }

        $patch = $overrides[$this->keyOf($event, $instance, $this->materialiser->zoneOf($event))] ?? null;

        return is_array($patch) ? $patch : [];
    }

    private function keyOf(CalendarEvent $event, CalendarEventOccurrence $instance, DateTimeZone $zone): string
    {
        return $this->recurrence->overrideKey($this->originalStartOf($event, $instance), $zone);
    }

    /**
     * Where the rule put this instance, before anything moved it.
     *
     * An instance that somehow has no recurrence id falls back to the series'
     * own start — the first instance, and the only honest answer available. The
     * column is NOT NULL in the database; the property is nullable because
     * Doctrine has to construct the object before it fills it in, and the
     * fallback exists so that fact does not become a null check at four call
     * sites.
     */
    private function originalStartOf(CalendarEvent $event, CalendarEventOccurrence $instance): DateTimeImmutable
    {
        return $instance->recurrenceId ?? $event->startsAt ?? new DateTimeImmutable('@0');
    }
}
