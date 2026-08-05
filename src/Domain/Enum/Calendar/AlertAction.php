<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * What an alert does when it comes due.
 *
 * The two RFC 8984 §4.5.2 defines, and only those. The values are the ones
 * stored verbatim inside `jscalendar.alerts[…].action`, so an alert that arrived
 * from Google, Graph or a CalDAV server keeps the spelling it came with and
 * travels back out unchanged — a value translated on the way in is a value that
 * eventually gets stored untranslated.
 *
 * There is deliberately no `Audio` or `Procedure` case even though iCalendar's
 * VALARM has both. JSCalendar dropped them, a browser notification already makes
 * a sound if the device says it may, and a case that exists but is never
 * honoured is worse than an unknown value — an unknown one reads as Display,
 * which is the harmless answer.
 */
enum AlertAction: string
{
    case Display = 'display';
    case Email   = 'email';

    /**
     * Whatever was stored, read charitably.
     *
     * Display for anything unrecognised, including absent: RFC 8984 makes
     * `action` optional with "display" as the default, and an alarm imported
     * from an .ics carrying `ACTION:AUDIO` is still an alarm somebody set.
     */
    public static function fromJsCalendar(?string $value): self
    {
        if (null === $value) {
            return self::Display;
        }

        return self::tryFrom(mb_strtolower(trim($value))) ?? self::Display;
    }

    /** What the editor calls it, and what a delivered alert is labelled with. */
    public function label(): string
    {
        return match ($this) {
            self::Display => 'calendar.event.alert.action.display',
            self::Email   => 'calendar.event.alert.action.email',
        };
    }

    /**
     * The iCalendar ACTION this is written as inside a VALARM.
     *
     * Upper case because RFC 5545 property values are, and sabre writes them
     * through verbatim.
     */
    public function icalAction(): string
    {
        return match ($this) {
            self::Display => 'DISPLAY',
            self::Email   => 'EMAIL',
        };
    }
}
