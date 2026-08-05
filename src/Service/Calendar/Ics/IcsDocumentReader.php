<?php

declare(strict_types=1);

namespace App\Service\Calendar\Ics;

use App\Domain\Exception\CalendarSyncPermanentException;
use App\Service\Calendar\Sync\CalDav\CalDavEventConverter;
use DateTimeZone;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * One iCalendar file, taken apart into the resources the rest of the calendar
 * already knows how to read.
 *
 * A published feed and an uploaded .ics are the same artefact — one VCALENDAR
 * holding every event there is — and the engine's whole vocabulary is built
 * around the opposite shape: CalDAV's, where a resource is *one meeting*, its
 * master and every instance somebody edited, in a document of its own. So this
 * turns the first into a stream of the second, and everything downstream
 * (CalDavEventConverter, RemoteEvent, CalendarPuller, CalendarEventWriter) then
 * works unchanged.
 *
 * That is the reason this class is one hundred lines of grouping rather than a
 * second VEVENT mapper. There already is a mapper — the one that reads a CalDAV
 * resource — and a second one would be a second opinion about what
 * `showWithoutTime`, `duration` and `participants` mean for the same bytes. The
 * same meeting reaches plMail by as many as four routes (an invitation in the
 * mailbox, a CalDAV server, a subscribed feed, a file somebody exported), and
 * CalendarPuller matches them by UID: two mappings that disagreed would present
 * as an event that flickers between two shapes depending on which route ran
 * last.
 *
 * ── What a resource carries with it ───────────────────────────────────────
 *
 * **Every VTIMEZONE in the document goes into every resource.** A `TZID=` is a
 * reference into the file it was written in, and sabre resolves one against the
 * root component the property is asked through: a name PHP knows resolves on
 * its own, a Windows name ("W. Europe Standard Time") resolves through sabre's
 * own map, and everything else — a publisher's private label — resolves only
 * from the `X-LIC-LOCATION` inside the matching VTIMEZONE. Google's exports are
 * full of the third kind. Split the document without carrying the definitions
 * across and those fall back to the process default, so
 * `DTSTART;TZID=My Zone:20260810T100000` is read as 10:00 UTC rather than 10:00
 * in Berlin — two hours wrong for every event in the feed, in the direction
 * nobody notices until a meeting is missed.
 *
 * Cloned into a fresh VCALENDAR rather than moved into one: Component::add()
 * re-parents the node it is given, so handing the same VTIMEZONE to a thousand
 * resources would leave it belonging to the last of them, and the source
 * document — which the caller may still be reading — quietly rearranged
 * underneath. Cloning one meeting plus the zone table per resource is linear;
 * cloning the whole document per meeting, which is the other obvious way to do
 * this, is quadratic in a size a stranger chose.
 *
 * ── Deliberate absences ───────────────────────────────────────────────────
 *
 * VTODO, VJOURNAL and VFREEBUSY are dropped without a word. plMail has no model
 * for any of them, and a task list imported as a wall of zero-length events is
 * worse than a task list that did not import.
 *
 * The document is parsed whole, into memory, and that is not an oversight.
 * sabre has no incremental component API — Reader::read() reads a stream but
 * returns one finished tree — so the honest options were "hold the tree" or
 * "write a second iCalendar parser", and the second is exactly what the
 * codebase does not want. The size is bounded instead, at both doors:
 * IcsFeedClient::MAX_BYTES on the way in from a URL and the upload limit on the
 * way in from a form. Per *resource* the memory is then one meeting at a time,
 * because resources() is a generator and the caller writes each one before
 * asking for the next.
 */
final readonly class IcsDocumentReader
{
    /**
     * The suffix on a UID this class had to invent.
     *
     * Visible on purpose: an event whose UID plMail made up cannot be matched
     * by any other client, and a reader looking at the row later deserves to
     * know which of the two happened. Also the marker a later re-keying
     * backfill would look for.
     */
    public const string SYNTHETIC_UID_SUFFIX = '@ics-import.plmail';

    /**
     * Where a publisher writes the calendar's own name and zone.
     *
     * Not standard — neither appears in RFC 5545 — and universal anyway: Apple
     * invented them, Google, Outlook, Fastmail and every feed generator emit
     * them, and they are the only thing in a feed that says what the calendar
     * is called. A feed without them is named after its URL instead, which is
     * an address rather than a name and is why these are worth reading.
     *
     * Public because IcsExporter writes the same two, and a name plMail reads
     * under one spelling and writes under another is a calendar that loses its
     * name on a round trip through its own export.
     */
    public const string NAME_PROPERTY = 'X-WR-CALNAME';

    public const string TIME_ZONE_PROPERTY = 'X-WR-TIMEZONE';

    /**
     * The one usable document, or a refusal phrased for the person who supplied
     * it.
     *
     * Permanent, because neither a feed that serves HTML nor a file that is not
     * a calendar becomes one by being fetched again — and both are the common
     * failure: a "subscribe" link that actually points at a web page is the
     * single most likely thing a user pastes into the feed form.
     *
     * OPTION_FORGIVING, matching IcsEventExtractor and CalDavEventConverter.
     * Real calendars in the wild are full of unescaped commas and lines longer
     * than the spec allows, and refusing a feed over one is refusing the feed.
     *
     * @throws CalendarSyncPermanentException
     */
    public function read(string $ics): VCalendar
    {
        if ('' === trim($ics)) {
            throw new CalendarSyncPermanentException(
                'There is nothing at this address, or the file is empty.',
            );
        }

        try {
            $document = Reader::read($ics, Reader::OPTION_FORGIVING);
        } catch (\Throwable $e) {
            throw new CalendarSyncPermanentException(
                'This is not a calendar file. Check that the address points at an .ics file rather than at a web page.',
                0,
                $e,
            );
        }

        if (false === $document instanceof VCalendar) {
            throw new CalendarSyncPermanentException(
                'This is not a calendar file. Check that the address points at an .ics file rather than at a web page.',
            );
        }

        return $document;
    }

    /**
     * Every meeting in the document, each as a resource of its own, keyed by
     * its UID.
     *
     * A generator rather than an array: the caller writes one meeting and
     * discards it before the next is built, so a feed with ten thousand events
     * costs ten thousand small documents in sequence instead of ten thousand at
     * once. The serialisation is what the resource is — CalDavEventConverter
     * takes bytes — so building them all first would be building the same file
     * again beside the one already held.
     *
     * **Components sharing a UID come out together, in one resource.** That is
     * the shape the rest of the calendar means by "one event": the master, the
     * VEVENTs carrying RECURRENCE-ID for the instances somebody moved, and the
     * master's EXDATEs for the ones they cancelled. Emitting them separately
     * would put a moved instance on the calendar as an event of its own, next
     * to a series still drawing it at its original time.
     *
     * @return iterable<string,string> UID => the .ics for that meeting
     */
    public function resources(VCalendar $document): iterable
    {
        $timeZones = $this->timeZoneComponents($document);
        $grouped   = [];

        foreach ($document->children() as $component) {
            if (false === $component instanceof VEvent) {
                continue;
            }

            $grouped[$this->uidOf($component)][] = $component;
        }

        foreach ($grouped as $uid => $components) {
            yield (string) $uid => $this->resource($timeZones, $components, (string) $uid);
        }
    }

    /**
     * What the publisher calls this calendar, trimmed to the column's width.
     *
     * Null when the document does not say, and the caller names it after the
     * address instead. Deliberately not defaulted to "Calendar" here: this
     * class knows the file and not the URL, and the caller knows both.
     */
    public function nameOf(VCalendar $document): ?string
    {
        $name = trim((string) ($document->{self::NAME_PROPERTY} ?? ''));

        return '' === $name ? null : mb_substr($name, 0, 120);
    }

    /**
     * The zone the publisher says the calendar is read in, or null for anything
     * PHP does not recognise.
     *
     * Null rather than a guess for a Windows zone name ("W. Europe Standard
     * Time"), for the reason CalDavEventConverter gives about the same
     * question: the caller falls back to the user's own zone, which is a better
     * answer than a wrong zone. This is only the calendar's display default —
     * each event's own times are resolved by sabre through the VTIMEZONE the
     * resource carries, so getting this wrong never moves an event.
     */
    public function timeZoneOf(VCalendar $document): ?string
    {
        $tzid = trim((string) ($document->{self::TIME_ZONE_PROPERTY} ?? ''));

        if ('' === $tzid) {
            return null;
        }

        try {
            return new DateTimeZone($tzid)->getName();
        } catch (\Exception) {
            return null;
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @return list<Component>
     */
    private function timeZoneComponents(VCalendar $document): array
    {
        $timeZones = [];

        foreach ($document->children() as $component) {
            if ($component instanceof Component && 'VTIMEZONE' === $component->name) {
                $timeZones[] = $component;
            }
        }

        return $timeZones;
    }

    /**
     * One meeting as a standalone calendar object.
     *
     * No METHOD, whatever the source document carried. A METHOD is iTIP's — it
     * says "this is an invitation being delivered" — and a feed or an export
     * that happens to carry `METHOD:REQUEST` must not turn every event in it
     * into an invitation the user appears to owe an answer to. The same reason
     * CalDavEventConverter refuses to write one.
     *
     * @param list<Component> $timeZones
     * @param list<VEvent>    $components
     * @param string          $uid        written onto any component that has
     *                                    none, because the resource has to
     *                                    *carry* its identity rather than merely
     *                                    be filed under one — everything
     *                                    downstream reads the UID out of the
     *                                    bytes, so a synthetic id kept only as a
     *                                    map key is an event that silently fails
     *                                    to import
     */
    private function resource(array $timeZones, array $components, string $uid): string
    {
        // The one PRODID plMail writes anywhere, borrowed rather than spelled
        // again: a second literal is a second product this install appears to
        // be, and support threads about a feed start with the PRODID.
        $resource = new VCalendar(['PRODID' => CalDavEventConverter::PRODUCT_ID]);

        foreach ($timeZones as $timeZone) {
            $resource->add(clone $timeZone);
        }

        foreach ($components as $component) {
            $clone = clone $component;

            if ('' === trim((string) ($clone->UID ?? ''))) {
                // add() rather than an assignment, which is what sabre's magic
                // setter would be: the property does not exist yet, so there is
                // nothing to assign to, and the library's own way of creating
                // one is the method that also gives it a root to serialise
                // against.
                $clone->add('UID', $uid);
            }

            $resource->add($clone);
        }

        return $resource->serialize();
    }

    /**
     * The component's own UID, or one derived from what it says about itself.
     *
     * A VEVENT with no UID violates RFC 5545 and arrives anyway — hand-written
     * files, a few older exporters, and anything that has been through a
     * spreadsheet. Two answers were possible and only one of them is safe to
     * repeat: refusing the event (which loses it silently, in a file the user
     * chose to import) or inventing an identity.
     *
     * Invented, and invented **from the event's own content** rather than
     * randomly, which is the whole point. sabre's own splitter mints
     * `sha1(microtime())` for this case; a random id means importing the same
     * file twice produces two copies of every UID-less event, and re-polling a
     * feed that has any produces a fresh set on every sweep — a calendar that
     * grows without bound while nothing at the far end changes.
     *
     * Hashed over what identifies the appointment to a person: when it starts,
     * when it ends, what it is called and where it is. Not over the serialised
     * component, because DTSTAMP is in there and changes on every export, which
     * would make the id stable against a re-import of the same file and
     * unstable against a re-publish of the same calendar.
     */
    private function uidOf(VEvent $component): string
    {
        $uid = trim((string) ($component->UID ?? ''));

        if ('' !== $uid) {
            return $uid;
        }

        $fingerprint = implode("\x1f", [
            trim((string) ($component->DTSTART ?? '')),
            trim((string) ($component->DTEND ?? '')),
            trim((string) ($component->DURATION ?? '')),
            trim((string) ($component->SUMMARY ?? '')),
            trim((string) ($component->LOCATION ?? '')),
        ]);

        return sha1($fingerprint) . self::SYNTHETIC_UID_SUFFIX;
    }
}
