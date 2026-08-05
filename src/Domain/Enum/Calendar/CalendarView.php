<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * The four ways of looking at a calendar.
 *
 * A view is a route segment rather than client-side state, which keeps the
 * range a single indexed query and makes every view linkable. The docked pane
 * and the full page differ only in which of these they open on — a 380px pane
 * has no business rendering a month grid.
 */
enum CalendarView: string
{
    case Day    = 'day';
    case Week   = 'week';
    case Month  = 'month';
    case Agenda = 'agenda';

    /** Route requirement, so the controller and the enum cannot disagree. */
    public static function routePattern(): string
    {
        return implode('|', array_column(self::cases(), 'value'));
    }

    /**
     * Whether this view has a vertical time axis — hours down the side, an
     * event drawn where it actually is and as long as it actually is — rather
     * than a list of what a day holds.
     *
     * Day and week, and only those two. A month cell is a couple of square
     * centimetres of a six-week grid and has no room to say where in the day
     * anything is; agenda is a rolling list whose entire value is that it skips
     * the empty time between entries, which is the axis this would draw.
     *
     * This is NOT the question "does the pane draw a time-grid". The pane
     * renders week and day too and deliberately keeps the column list at 380px.
     * That choice is made where pane-ness is known — CalendarController passes
     * `isPane` into the render — because this enum knows what a view is and
     * nothing about the chrome around it.
     */
    public function isTimeGrid(): bool
    {
        return match ($this) {
            self::Day, self::Week     => true,
            self::Month, self::Agenda => false,
        };
    }

    /**
     * How far either side of the anchor date this view reaches, before the
     * timezone padding the reader adds. Month is asked for six weeks because a
     * month grid always shows the days spilling in from its neighbours.
     */
    public function range(\DateTimeImmutable $anchor): array
    {
        return match ($this) {
            self::Day => [
                $anchor->setTime(0, 0),
                $anchor->modify('+1 day')->setTime(0, 0),
            ],
            self::Week => [
                $anchor->modify('monday this week')->setTime(0, 0),
                $anchor->modify('monday this week')->modify('+7 days')->setTime(0, 0),
            ],
            self::Month => [
                $anchor->modify('first day of this month')->modify('monday this week')->setTime(0, 0),
                $anchor->modify('first day of this month')->modify('monday this week')->modify('+42 days')->setTime(0, 0),
            ],
            self::Agenda => [
                $anchor->setTime(0, 0),
                $anchor->modify('+30 days')->setTime(0, 0),
            ],
        };
    }
}
