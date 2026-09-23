<?php

declare(strict_types=1);

namespace App\Service\Calendar\Ics;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;

/**
 * The VTIMEZONE a file has to carry for every TZID it names.
 *
 * RFC 5545 §3.6.5 requires one, and "every reader resolves an IANA name
 * anyway" is true only of the readers somebody tested: a strict importer
 * rejects the file, and one that cannot resolve the name falls back to
 * floating time and moves every meeting by the reader's offset.
 *
 * sabre/vobject reads VTIMEZONEs and writes none, so the definition is built
 * here from PHP's own zone table — the same table every instant in plMail is
 * computed with, which is what makes the file agree with the calendar. One
 * STANDARD or DAYLIGHT block per transition inside the range the events
 * actually cover, each with an explicit DTSTART, rather than a recurring rule:
 * a rule is a guess about the future that the zone table already answers, and
 * written out this way the definition is exactly right for every instant the
 * file mentions — including the years a zone changed its rules.
 *
 * The range is padded a day either side so an instant on the boundary is
 * covered on both sides of its own offset.
 */
final readonly class VTimeZoneBuilder
{
    public function build(
        VCalendar         $calendar,
        string            $tzid,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): ?Component {
        try {
            $zone = new DateTimeZone($tzid);
        } catch (\Exception) {
            return null;
        }

        $begin = $from->modify('-1 day')->getTimestamp();
        $end   = max($begin, $to->modify('+1 day')->getTimestamp());

        $transitions = $zone->getTransitions($begin, $end);

        if (false === $transitions || [] === $transitions) {
            return null;
        }

        $component = $calendar->createComponent('VTIMEZONE', ['TZID' => $tzid], false);

        // The first entry is not a transition but the state at $begin, and it
        // is what an instant before the first real change is read against.
        $previous = (int) $transitions[0]['offset'];

        foreach ($transitions as $index => $transition) {
            $offset = (int) $transition['offset'];
            $at     = 0 === $index ? $begin : (int) $transition['ts'];

            $component->add($calendar->createComponent(
                true === (bool) $transition['isdst'] ? 'DAYLIGHT' : 'STANDARD',
                [
                    // Local time as it was just BEFORE the change, which is how
                    // RFC 5545 defines a sub-component's onset.
                    'DTSTART'      => gmdate('Ymd\THis', $at + $previous),
                    'TZOFFSETFROM' => $this->offset($previous),
                    'TZOFFSETTO'   => $this->offset($offset),
                    'TZNAME'       => (string) $transition['abbr'],
                ],
                false,
            ));

            $previous = $offset;
        }

        return $component;
    }

    /** ±HHMM, with seconds only for the historical zones that had them. */
    private function offset(int $seconds): string
    {
        $sign    = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);
        $hours   = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest    = $seconds % 60;

        return sprintf('%s%02d%02d', $sign, $hours, $minutes) . (0 === $rest ? '' : sprintf('%02d', $rest));
    }
}
