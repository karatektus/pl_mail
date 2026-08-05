<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sharing;

use App\Domain\DTO\Calendar\SharedCalendarView;
use App\Domain\DTO\Calendar\SharedOccurrence;
use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;

/**
 * A shared window as the .ics somebody subscribes to or downloads.
 *
 * **Deliberately not IcsExporter, and that is the whole design.** IcsExporter's
 * own docblock argues that a second exporter is a mistake because the per-event
 * mapping is a pile of hard-won decisions that must not be made twice. That
 * argument is about exporting a CalendarEvent, and this does not export one: it
 * takes SharedOccurrence — the redaction — and serialises exactly the fields it
 * carries. Reusing IcsExporter would mean handing it events and then removing
 * things from the file it produced, which is the leaky shape SharedOccurrence
 * exists to make impossible. A busy/free link's file cannot contain a title
 * here, because there is no title in the objects being written.
 *
 * The price is real: this file has no participants back out as ATTENDEE, no
 * recurrence rule, no moved instances. It is a flat list of opaque blocks, and
 * that is what a free/busy publication is. A recipient who wants the owner's
 * real calendar object should be sent an invitation, not a link.
 *
 * ── What is written, and what is not ──────────────────────────────────────
 *
 * **Times go out as UTC instants with a Z**, not as zoned local times. There is
 * no VTIMEZONE in the file for the reason IcsExporter gives, and unlike that
 * exporter this one has no obligation to preserve the owner's zone — a busy
 * block means the same interval whichever clock it is read on, and a bare UTC
 * instant is the one spelling no reader can get wrong.
 *
 * **No METHOD**, for IcsExporter's reason exactly: a downloaded calendar is not
 * an invitation, and a client that decided otherwise would try to deliver it.
 *
 * **No ORGANIZER and no X-WR-CALNAME carrying the owner's name.** The document
 * is named after the link's window, not after whose diary it is. A recipient
 * who has the URL already knows who sent it; a file that leaks past them should
 * not say.
 *
 * **A SUMMARY is always present**, and on a busy/free link it is the word
 * "Busy" rather than an empty property. An event with no SUMMARY renders as an
 * untitled bar in most clients and as nothing at all in some, and the point of
 * the file is that the blocks are visible.
 */
final readonly class SharedIcsBuilder
{
    /** iCalendar's line break — CRLF, RFC 5545 §3.1. Same reason IcsExporter names it. */
    private const string LINE_BREAK = "\r\n";

    /**
     * How an instant is spelled in a UTC-anchored property.
     *
     * Written by hand rather than through sabre's DateTime helper because the
     * properties below are built from scalars: there is no VEvent object being
     * assembled per entry, only a small component whose values are already
     * decided.
     */
    private const string UTC_FORMAT = 'Ymd\THis\Z';

    /**
     * The one document-level property besides the envelope: what a client calls
     * the subscription in its sidebar.
     *
     * A constant rather than the link's name, which is the owner's private
     * label for it ("recruiters, Q3") and has no business travelling to the
     * recipient.
     */
    private const string CALENDAR_NAME = 'Shared calendar';

    /**
     * What a redacted block is called when there is no title to give it.
     *
     * Not translated, and that is a decision rather than an oversight: an .ics
     * is read by a machine in whatever locale its owner runs, days or months
     * after it was fetched, and a German file appearing in an English client is
     * worse than one English word. The HTML page beside it is fully translated,
     * because that is rendered per request for a person who is present.
     */
    private const string BUSY_SUMMARY = 'Busy';

    /**
     * The whole window as one file.
     *
     * A string rather than the generator IcsExporter yields, and the difference
     * is the bound: ShareLinkReader caps a window at a couple of thousand
     * entries, each of which is four short lines, so the file is measured in
     * hundreds of kilobytes at worst. IcsExporter streams because a whole
     * calendar has no bound at all.
     */
    public function build(SharedCalendarView $view): string
    {
        $document = 'BEGIN:VCALENDAR' . self::LINE_BREAK;

        foreach ($this->envelope()->children() as $property) {
            $document .= $property->serialize();
        }

        foreach ($view->days as $entries) {
            foreach ($entries as $entry) {
                $document .= $this->component($entry);
            }
        }

        return $document . 'END:VCALENDAR' . self::LINE_BREAK;
    }

    /** What the download is called. One name for every link, for the reason CALENDAR_NAME is. */
    public function fileName(): string
    {
        return 'shared-calendar.ics';
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * VERSION, PRODID, CALSCALE and the display name, built as a real VCalendar
     * so the values go through sabre's escaping — the same reason IcsExporter
     * builds its envelope rather than writing the lines.
     */
    private function envelope(): VCalendar
    {
        $envelope = new VCalendar(['PRODID' => '-//plMail//Shared Calendar//EN']);

        $envelope->add('X-WR-CALNAME', self::CALENDAR_NAME);

        return $envelope;
    }

    /**
     * One VEVENT.
     *
     * Assembled from the DTO's own fields and nothing else. Every property
     * below is either a time, the synthetic UID, or a value the link was ticked
     * to reveal — there is no branch that reaches an event, which is what makes
     * "a busy/free link's .ics contains no titles" a property of the code
     * rather than a thing to remember.
     *
     * DTSTAMP is now rather than the event's own timestamps: it means "when
     * this object was produced", and the row's created_at would leak when the
     * owner made the meeting.
     */
    private function component(SharedOccurrence $entry): string
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:' . $entry->uid,
            'DTSTAMP:' . new DateTimeImmutable('now', new DateTimeZone('UTC'))->format(self::UTC_FORMAT),
            'DTSTART:' . $entry->startsAt->setTimezone(new DateTimeZone('UTC'))->format(self::UTC_FORMAT),
            'DTEND:' . $entry->endsAt->setTimezone(new DateTimeZone('UTC'))->format(self::UTC_FORMAT),
            'SUMMARY:' . $this->escape($entry->title ?? self::BUSY_SUMMARY),
            // Free/busy is what this file is for, and TRANSP is how a client is
            // told so. Without it a subscribed calendar shows the blocks and
            // still reports the owner as free to anyone asking its availability.
            'TRANSP:OPAQUE',
        ];

        if (null !== $entry->location) {
            $lines[] = 'LOCATION:' . $this->escape($entry->location);
        }

        if (null !== $entry->description) {
            $lines[] = 'DESCRIPTION:' . $this->escape($entry->description);
        }

        if ([] !== $entry->participants) {
            // As a comment property rather than as ATTENDEE. An ATTENDEE
            // carries a mailto: URI and a participation status, and the DTO has
            // neither — inventing them would put addresses in the file that the
            // page deliberately reduced to display names, and would make some
            // clients offer to reply to a meeting nobody was invited to.
            $lines[] = 'X-PLMAIL-PARTICIPANTS:' . $this->escape(implode(', ', $entry->participants));
        }

        $lines[] = 'END:VEVENT';

        return implode(self::LINE_BREAK, $lines) . self::LINE_BREAK;
    }

    /**
     * iCalendar TEXT escaping — RFC 5545 §3.3.11.
     *
     * Written here because the properties are assembled as strings rather than
     * as sabre components. The backslash goes first: escaping it after the
     * others would go back over the backslashes this function had just added
     * and double them, so a location containing a semicolon would arrive with a
     * stray one.
     *
     * Newlines become the literal two characters `\n`, which is what the format
     * means by a line break inside a value — a real newline there ends the
     * property and turns the rest of a description into an unparseable line.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value,
        );
    }
}
