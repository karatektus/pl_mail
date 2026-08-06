<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * The four ways of looking at a calendar.
 *
 * A view is a route segment rather than client-side state, which keeps the
 * range a single indexed query and makes every view linkable. The docked pane
 * and the full page differ only in which of these they open on: the pane starts
 * on the agenda, which is the one that reads well in a narrow column, and every
 * other view is a click away in its toolbar and draws exactly what the page
 * draws.
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
     * This is the WHOLE question. The docked pane renders exactly what the page
     * does — it used to keep a column list of its own at 380px, and
     * _grid.html.twig says why it no longer does — so there is nothing about
     * the chrome around a view left for this enum to not know about.
     */
    public function isTimeGrid(): bool
    {
        return match ($this) {
            self::Day, self::Week     => true,
            self::Month, self::Agenda => false,
        };
    }

    /**
     * The narrowest the docked pane may be while showing this view, in pixels.
     *
     * A view is not equally happy at every width, and the two that draw seven
     * columns are not happy at 320: the columns divide whatever there is, so a
     * week in a narrow pane is seven slivers and a month grid is worse. Rather
     * than refusing the view — the pane and the page draw the same calendar now,
     * and a pane that offered fewer of them would be the old asymmetry back —
     * picking one WIDENS the pane to fit it. ui--split does the widening, and
     * only ever upward: a user who has dragged their pane wider than this keeps
     * it, and switching back to Day does not take the width away again.
     *
     * 720 is seven columns at about 95px plus the time gutter, which is where a
     * chip stops being able to say "9:00 Standup" and starts saying "9:0…".
     * Day and agenda are one column and a list, and are legible at the pane's
     * own floor — CALENDAR_PANE_MIN_WIDTH, which this must never go below and
     * which the controller clamps against anyway.
     */
    public function minimumPaneWidth(): int
    {
        return match ($this) {
            self::Day, self::Agenda   => 320,
            self::Week, self::Month   => 720,
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
