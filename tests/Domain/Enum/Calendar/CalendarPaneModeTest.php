<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Calendar;

use App\Domain\Enum\Calendar\CalendarPaneMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalendarPaneModeTest extends TestCase
{
    /**
     * The three-way cycle is what the switch has always done, and it stays the
     * default: every caller that does not say otherwise gets it.
     */
    public function testTheSwitchCyclesThroughAllThreePositionsByDefault(): void
    {
        self::assertSame(CalendarPaneMode::Split, CalendarPaneMode::Mail->next());
        self::assertSame(CalendarPaneMode::Calendar, CalendarPaneMode::Split->next());
        self::assertSame(CalendarPaneMode::Mail, CalendarPaneMode::Calendar->next());
    }

    /**
     * Direct: one press from the mail is the full-width calendar, and one press
     * from anywhere the calendar is showing — Split included, which is only
     * ever stored from before the setting was turned on — goes back to the mail.
     *
     * @return iterable<string, array{CalendarPaneMode, CalendarPaneMode}>
     */
    public static function directSteps(): iterable
    {
        yield 'mail opens the full calendar, skipping split' => [CalendarPaneMode::Mail, CalendarPaneMode::Calendar];
        yield 'calendar goes back to the mail' => [CalendarPaneMode::Calendar, CalendarPaneMode::Mail];
        yield 'a stored split goes to the mail, not on to the calendar' => [CalendarPaneMode::Split, CalendarPaneMode::Mail];
    }

    #[DataProvider('directSteps')]
    public function testADirectSwitchIsMailAndTheFullCalendarAndNothingBetween(CalendarPaneMode $from, CalendarPaneMode $to): void
    {
        self::assertSame($to, $from->next(direct: true));
    }

    /** The screen-reader label names the position a press leads to, in either mode. */
    public function testTheLabelNamesWhereThePressLeads(): void
    {
        self::assertSame('calendar.pane.mode.split', CalendarPaneMode::Mail->nextTransKey());
        self::assertSame('calendar.pane.mode.calendar', CalendarPaneMode::Mail->nextTransKey(direct: true));
        self::assertSame('calendar.pane.mode.mail', CalendarPaneMode::Split->nextTransKey(direct: true));
        self::assertSame('calendar.pane.mode.mail', CalendarPaneMode::Calendar->nextTransKey(direct: true));
    }
}
