<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\Graph;

use DateTimeZone;
use IntlTimeZone;

/**
 * The name Microsoft calls a time zone, and the name everything else calls it.
 *
 * Graph's dateTimeTimeZone, originalStartTimeZone and
 * recurrence.range.recurrenceTimeZone all carry a **Windows** zone id —
 * "W. Europe Standard Time", "Pacific Standard Time", "GMT Standard Time" —
 * which is not an IANA name and which DateTimeZone refuses outright. Every
 * layer above this one stores IANA: Calendar::$timeZone, CalendarEvent::$timeZone
 * and JSCalendar's `timeZone` (RFC 8984 §4.7.1) are all defined as IANA names,
 * and sabre expands a recurrence in one. So the translation has to happen at
 * this boundary, and this is it.
 *
 * **Translated through ICU, not a table in this file.** ext-intl exposes CLDR's
 * windowsZones.xml — the mapping Microsoft and Unicode maintain together —
 * through IntlTimeZone::getIDForWindowsID() and ::getWindowsID(). A hand-written
 * array of the ~140 Windows zones was the obvious alternative and was rejected:
 * it is a copy of data that changes (Mexico dropped DST in 2022, Chile and Iran
 * have both moved since), and a copy that changes is a copy that goes stale
 * silently, putting a meeting an hour out for one country and nobody else.
 *
 * ext-intl is not in composer.json's require list, so its absence is handled
 * rather than assumed: an unconvertible name answers null, and the caller's
 * documented fallback applies. That degrades the *displayed* zone only —
 * GraphEventMapper reads instants from Graph's own UTC values, so no start time
 * moves because a zone name could not be resolved.
 *
 * No cache. Each lookup is an ICU hash probe against data already resident, and
 * a memo here would be the only mutable state in the whole driver.
 */
final readonly class GraphTimeZoneMapper
{
    /**
     * What ICU answers for the Windows zone "UTC", and what plMail spells that
     * as everywhere else — Calendar::$timeZone defaults to it and every column
     * comparison assumes it. Both are legal IANA; having two would mean two
     * calendars in the same zone rendering as though they were not.
     */
    private const string ICU_UTC = 'Etc/UTC';

    private const string UTC = 'UTC';

    /**
     * A Graph zone name as an IANA one, or null when it is neither.
     *
     * Answers null rather than guessing for the two things Graph sends that are
     * not zones at all: "Customized Time Zone" and "tzone://Microsoft/Custom",
     * both of which mean "this mailbox defined its own offset rules". There is
     * no IANA zone that means that, and picking the nearest one would be wrong
     * twice a year for whoever set it.
     */
    public function toIana(?string $graphZone): ?string
    {
        $name = trim((string) $graphZone);

        if ('' === $name) {
            return null;
        }

        if (self::UTC === strtoupper($name)) {
            return self::UTC;
        }

        $iana = $this->fromWindows($name);

        if (null !== $iana) {
            return self::ICU_UTC === $iana ? self::UTC : $iana;
        }

        // Graph does accept and echo IANA names, so a name ICU does not
        // recognise as a Windows id may already be one.
        return true === $this->isIana($name) ? $name : null;
    }

    /**
     * The zone to read a Graph local time in, with UTC as the floor.
     *
     * UTC rather than null or the server's zone: Graph answers every
     * dateTimeTimeZone in UTC unless asked otherwise, so the one name that is
     * certain to arrive here is "UTC" — and a name that cannot be resolved is
     * far more likely to be a custom Windows zone whose offset was UTC-based
     * than to be the container's locale.
     */
    public function zoneFor(?string $graphZone): DateTimeZone
    {
        $iana = $this->toIana($graphZone);

        return new DateTimeZone($iana ?? self::UTC);
    }

    /**
     * The name to send Graph for an IANA zone.
     *
     * Graph accepts IANA names on write, so this could be the identity
     * function. It is not, because Graph *stores* what it is given and answers
     * Windows ids to Outlook clients reading the same event — an event created
     * here with "Europe/Berlin" is one Outlook has to translate on every read,
     * and the round trip through originalStartTimeZone then comes back as
     * something this mapper has to recognise anyway. Sending the Windows id
     * makes plMail's events indistinguishable from Outlook's own.
     *
     * A zone ICU has no Windows counterpart for — Australia/Lord_Howe and the
     * handful like it — is sent verbatim rather than dropped: Graph will take
     * the IANA name, and an approximate zone is a meeting at the wrong hour.
     */
    public function toGraph(?string $ianaZone): string
    {
        $name = trim((string) $ianaZone);

        if ('' === $name) {
            return self::UTC;
        }

        if (false === class_exists(IntlTimeZone::class)) {
            return $name;
        }

        $windows = IntlTimeZone::getWindowsID($name);

        return false === $windows ? $name : $windows;
    }

    private function fromWindows(string $name): ?string
    {
        if (false === class_exists(IntlTimeZone::class)) {
            return null;
        }

        $iana = IntlTimeZone::getIDForWindowsID($name);

        return false === $iana ? null : $iana;
    }

    /**
     * Asked of DateTimeZone rather than of a list, because DateTimeZone is what
     * will have to accept the answer later — a name this says yes to and the
     * constructor then rejects would fail inside the materialiser, one layer
     * too far from the cause to be recognisable.
     */
    private function isIana(string $name): bool
    {
        try {
            new DateTimeZone($name);
        } catch (\Exception) {
            return false;
        }

        return true;
    }
}
