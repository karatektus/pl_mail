<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * How much of the row the calendar has.
 *
 * Three states rather than the boolean this used to be, because the boolean
 * could not say the thing people wanted most: **give me the calendar, full
 * width, without leaving my mail behind**. Reaching that meant navigating to
 * /calendar, which is a different page — the mail is gone, the reading pane is
 * gone, and coming back is a second navigation. So the docked pane grew a third
 * position and the topbar control became a switch that cycles through all
 * three.
 *
 * `Split` is the interesting one and the reason a boolean was ever enough: it
 * only exists above lg. Below that the row cannot hold a sidebar, a usable
 * message list and a usable calendar at once, so Split renders exactly like
 * Calendar and the CSS says so in one media query rather than the controller
 * carrying a fourth state for it.
 *
 * The order of the cases is the order the switch cycles in, and it is the order
 * of how much calendar there is. A cycle that jumped is a cycle nobody can
 * predict from having pressed it once.
 */
enum CalendarPaneMode: string
{
    /** No calendar. The mail has the row, which is where everyone starts. */
    case Mail = 'mail';

    /** Both, divided by the drag handle. Above lg only. */
    case Split = 'split';

    /** The calendar has the row; the mail is still there, behind it. */
    case Calendar = 'calendar';

    /**
     * The next position of the switch.
     *
     * Wraps, so the control is reachable in one direction only and never has a
     * dead end — three presses always return you to where you were.
     */
    public function next(): self
    {
        return match ($this) {
            self::Mail     => self::Split,
            self::Split    => self::Calendar,
            self::Calendar => self::Mail,
        };
    }

    /** Whether the docked pane is rendered at all. */
    public function showsCalendar(): bool
    {
        return self::Mail !== $this;
    }

    /** Font Awesome icon for the switch, saying which position it is in. */
    public function icon(): string
    {
        return match ($this) {
            self::Mail     => 'fa-regular fa-calendar',
            self::Split    => 'fa-solid fa-table-columns',
            self::Calendar => 'fa-solid fa-calendar-days',
        };
    }

    /** Translation key for "what pressing this does next". */
    public function nextTransKey(): string
    {
        return 'calendar.pane.mode.' . $this->next()->value;
    }

    /**
     * Whatever was stored, read charitably — the settings bag is untyped and
     * may hold a value written by an older version, or nothing at all.
     */
    public static function fromSetting(mixed $value, self $default = self::Mail): self
    {
        return true === is_string($value) ? self::tryFrom($value) ?? $default : $default;
    }
}
