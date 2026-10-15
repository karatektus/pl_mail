<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Calendar;

use App\Domain\Enum\Calendar\CalendarPaneMode;
use App\Domain\Enum\Calendar\CalendarSwitchSkip;
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
     * With a position skipped the switch is the mail and ONE other position.
     * One press from the mail is that one; one press from anywhere else is the
     * mail — the skipped position included, which is only ever the current one
     * when it was stored before the setting went on or reached with the handle.
     *
     * @return iterable<string, array{CalendarSwitchSkip, CalendarPaneMode, CalendarPaneMode}>
     */
    public static function skippedSteps(): iterable
    {
        yield 'skip split: mail opens the full calendar' => [CalendarSwitchSkip::Split, CalendarPaneMode::Mail, CalendarPaneMode::Calendar];
        yield 'skip split: the calendar goes back to the mail' => [CalendarSwitchSkip::Split, CalendarPaneMode::Calendar, CalendarPaneMode::Mail];
        yield 'skip split: a stored split goes to the mail, not on to the calendar' => [CalendarSwitchSkip::Split, CalendarPaneMode::Split, CalendarPaneMode::Mail];

        yield 'skip calendar: mail opens the split' => [CalendarSwitchSkip::Calendar, CalendarPaneMode::Mail, CalendarPaneMode::Split];
        yield 'skip calendar: the split goes back to the mail' => [CalendarSwitchSkip::Calendar, CalendarPaneMode::Split, CalendarPaneMode::Mail];
        yield 'skip calendar: a stored full calendar goes to the mail, not on to the split' => [CalendarSwitchSkip::Calendar, CalendarPaneMode::Calendar, CalendarPaneMode::Mail];
    }

    #[DataProvider('skippedSteps')]
    public function testASkippingSwitchIsTheMailAndOneOtherPositionAndNothingBetween(
        CalendarSwitchSkip $skip,
        CalendarPaneMode $from,
        CalendarPaneMode $to,
    ): void {
        self::assertSame($to, $from->next($skip));
    }

    /** The screen-reader label names the position a press leads to, whichever switch it is. */
    public function testTheLabelNamesWhereThePressLeads(): void
    {
        self::assertSame('calendar.pane.mode.split', CalendarPaneMode::Mail->nextTransKey());
        self::assertSame('calendar.pane.mode.calendar', CalendarPaneMode::Mail->nextTransKey(CalendarSwitchSkip::Split));
        self::assertSame('calendar.pane.mode.mail', CalendarPaneMode::Split->nextTransKey(CalendarSwitchSkip::Split));
        self::assertSame('calendar.pane.mode.split', CalendarPaneMode::Mail->nextTransKey(CalendarSwitchSkip::Calendar));
        self::assertSame('calendar.pane.mode.mail', CalendarPaneMode::Calendar->nextTransKey(CalendarSwitchSkip::Calendar));
    }
}
