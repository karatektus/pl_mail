<?php

declare(strict_types=1);

namespace App\Service\Calendar\Ics;

use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Service\Calendar\Sync\CalDav\CalDavEventConverter;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * A calendar, or one event on it, as the .ics a person downloads.
 *
 * **The per-event mapping is not here.** CalDavEventConverter::toIcs() already
 * turns one CalendarEvent into an iCalendar object — times in the shape the
 * event's zone calls for, an all-day pair written as DATEs, participants back
 * out as ORGANIZER and ATTENDEE with their roles intact, recurrenceOverrides
 * back out as override VEVENTs and EXDATEs — and every one of those is a
 * decision that took a bug to get right. Writing a second exporter would mean
 * making all of them again, differently, and the difference would show up as an
 * event that survives a CalDAV round trip and loses its moved instances on a
 * download. So this class composes documents out of that one and does nothing
 * else with a VEVENT.
 *
 * The composition goes through the library rather than through string surgery.
 * The converter answers with a whole VCALENDAR, so its output is read back and
 * each VEVENT re-serialised on its own — one small parse per event, in exchange
 * for never owning an opinion about where a document's header ends. Cutting the
 * envelope off with substr() is the obvious cheaper way and it breaks the day
 * the converter adds a VTIMEZONE, silently, by exporting half a file.
 *
 * ── Why a whole calendar is a generator ───────────────────────────────────
 *
 * document() yields chunks and the controller streams them. A decade of a busy
 * calendar is tens of thousands of events, and building the file as one string
 * would hold all of it in memory at once — twice, once as the string and once
 * as the response body — to produce a download that starts only when the last
 * event has been formatted. Yielded, the peak is one event, and the browser
 * starts receiving immediately.
 *
 * The caller supplies the events as an iterable for the same reason, so a
 * repository can hand them over one at a time rather than hydrating the
 * calendar whole.
 *
 * ── Deliberate absences ───────────────────────────────────────────────────
 *
 * **No VTIMEZONE is written.** A zoned DTSTART goes out as
 * `TZID=Europe/Berlin`, with no definition of that zone in the file. RFC 5545
 * asks for one; every reader that matters — Apple, Google, Outlook, sabre
 * itself, and therefore plMail's own import — resolves an IANA name without it,
 * and the alternative is generating transition rules for a zone table, which is
 * a library plMail does not have and would be wrong in the interesting years.
 * plMail only ever stores IANA names (CalendarEventWriter writes
 * CalendarEvent::$timeZone from a validated zone), so a name a reader cannot
 * resolve is not a case this can produce.
 *
 * **No METHOD.** An exported file is a calendar object, not an invitation.
 * Writing `METHOD:REQUEST` on one would make some clients try to deliver it,
 * and a user who downloaded their own calendar would find they had emailed
 * themselves four hundred meeting requests.
 *
 * **No VALARM.** plMail does not store alarms on an event yet, so exporting one
 * would mean inventing it. When it does, this needs nothing: the alarm will be
 * in the JSCalendar object and the converter is where it turns into a
 * component.
 */
final readonly class IcsExporter
{
    /**
     * iCalendar's line break, which is CRLF and is not negotiable — RFC 5545
     * §3.1. Named because the BEGIN and END markers below are the only two
     * lines this class writes itself, and a bare "\n" on them produces a file
     * that Outlook refuses and everything else silently accepts, which is the
     * worst way to find out.
     */
    private const string LINE_BREAK = "\r\n";

    public function __construct(
        private CalDavEventConverter $converter,
    ) {
    }

    /**
     * One event, as a file.
     *
     * A series comes out whole — its rule, its moved instances and its
     * cancelled ones — because that is what the event is. Exporting the
     * occurrence somebody clicked would produce a file that re-imports as a
     * one-off meeting and quietly loses the repeat.
     */
    public function one(CalendarEvent $event): string
    {
        return $this->converter->toIcs($event);
    }

    /**
     * A whole calendar, in the order the caller hands the events over.
     *
     * @param iterable<CalendarEvent> $events
     *
     * @return iterable<string> chunks of the file, in order
     */
    public function document(Calendar $calendar, iterable $events): iterable
    {
        yield 'BEGIN:VCALENDAR' . self::LINE_BREAK;

        foreach ($this->envelope($calendar)->children() as $property) {
            yield $property->serialize();
        }

        foreach ($events as $event) {
            foreach ($this->componentsOf($event) as $component) {
                yield $component;
            }
        }

        yield 'END:VCALENDAR' . self::LINE_BREAK;
    }

    /**
     * What the download is called.
     *
     * ASCII, lower case, no spaces. Not because the name cannot carry more —
     * Content-Disposition has RFC 5987 encoding for exactly that, and the
     * controller uses it — but because this is the *fallback* filename a client
     * that does not implement it falls back to, and "Urlaub &amp; Reisen.ics"
     * arriving as a file with a question mark in the name is worse than
     * arriving as "urlaub-reisen.ics". A name that reduces to nothing becomes
     * "calendar", so the download is never called ".ics".
     */
    public function fileNameFor(string $name): string
    {
        $ascii = (string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name));
        $slug  = trim($ascii, '-');

        return ('' === $slug ? 'calendar' : mb_substr($slug, 0, 60)) . '.ics';
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The document's own properties: VERSION, PRODID, CALSCALE and the two
     * X- properties every reader shows the calendar's name and zone from.
     *
     * Built as a real VCalendar and its properties serialised individually,
     * rather than written as literal lines, so the name goes through sabre's
     * escaping and folding. A calendar called "Work, home; both" written by
     * hand would produce a property with two unescaped delimiters in it, which
     * every parser reads as a different value than the one stored.
     */
    private function envelope(Calendar $calendar): VCalendar
    {
        $envelope = new VCalendar(['PRODID' => CalDavEventConverter::PRODUCT_ID]);

        $envelope->add(IcsDocumentReader::NAME_PROPERTY, $calendar->name);
        $envelope->add(IcsDocumentReader::TIME_ZONE_PROPERTY, $calendar->timeZone);

        return $envelope;
    }

    /**
     * The VEVENT blocks for one event: the series, plus one per instance that
     * differs from it.
     *
     * @return list<string>
     */
    private function componentsOf(CalendarEvent $event): array
    {
        $document = Reader::read($this->converter->toIcs($event), Reader::OPTION_FORGIVING);

        if (false === $document instanceof VCalendar) {
            // Unreachable through the converter, which builds the document
            // rather than parsing one. Kept because the alternative is a
            // download that ends mid-file on a defect nobody would look for
            // here, and skipping one event is a loss a reader can see.
            return [];
        }

        $blocks = [];

        foreach ($document->children() as $component) {
            if (true === $component instanceof VEvent) {
                $blocks[] = $component->serialize();
            }
        }

        return $blocks;
    }
}
