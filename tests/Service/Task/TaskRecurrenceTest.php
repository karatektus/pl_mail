<?php

declare(strict_types=1);

namespace App\Tests\Service\Task;

use App\Entity\Task\Task;
use App\Service\Task\TaskRecurrence;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Ticking off a repeating task moves it to a date in the future, the right one.
 *
 * Three ways this goes wrong without a word: landing on a date that is already
 * overdue, counting COUNT afresh on every move so a rule of five never ends, and
 * walking a daily 09:00 in UTC so that it shifts by an hour across a clock change.
 */
final class TaskRecurrenceTest extends TestCase
{
    private TaskRecurrence $recurrence;
    private DateTimeZone $berlin;

    protected function setUp(): void
    {
        $this->recurrence = new TaskRecurrence();
        $this->berlin     = new DateTimeZone('Europe/Berlin');
    }

    public function testAWeeklyDateMovesOneWeek(): void
    {
        $task = $this->dated('2026-06-15', 'FREQ=WEEKLY');

        self::assertTrue($this->recurrence->advance($task, $this->at('2026-06-15 10:00'), $this->berlin));
        self::assertSame('2026-06-22', $task->dueDate?->format('Y-m-d'));
    }

    public function testATaskTickedLateSkipsTheDatesAlreadyGone(): void
    {
        $task = $this->dated('2026-06-01', 'FREQ=WEEKLY');

        $this->recurrence->advance($task, $this->at('2026-06-20 10:00'), $this->berlin);

        self::assertSame('2026-06-22', $task->dueDate?->format('Y-m-d'));
    }

    public function testADailyTimeKeepsItsHourAcrossAClockChange(): void
    {
        // 09:00 in Berlin on the day before summer time, which is 08:00 UTC.
        $task                 = new Task();
        $task->dueAt          = new DateTimeImmutable('2026-03-28 08:00:00', new DateTimeZone('UTC'));
        $task->timeZone       = 'Europe/Berlin';
        $task->recurrenceRule = 'FREQ=DAILY';

        $this->recurrence->advance($task, $this->at('2026-03-28 09:00'), $this->berlin);

        self::assertSame('2026-03-29 07:00', $task->dueAt->format('Y-m-d H:i'), 'still 09:00 in Berlin, now 07:00 UTC');
    }

    public function testCountIsSpentSoARuleOfThreeEnds(): void
    {
        $task = $this->dated('2026-06-01', 'FREQ=WEEKLY;COUNT=3');

        self::assertTrue($this->recurrence->advance($task, $this->at('2026-06-01 10:00'), $this->berlin));
        self::assertSame('FREQ=WEEKLY;COUNT=2', $task->recurrenceRule);
        self::assertSame('2026-06-08', $task->dueDate?->format('Y-m-d'));

        self::assertTrue($this->recurrence->advance($task, $this->at('2026-06-08 10:00'), $this->berlin));
        self::assertSame('FREQ=WEEKLY;COUNT=1', $task->recurrenceRule);
        self::assertSame('2026-06-15', $task->dueDate->format('Y-m-d'));

        self::assertFalse($this->recurrence->advance($task, $this->at('2026-06-15 10:00'), $this->berlin), 'the third was the last');
    }

    public function testCountCoversTheOccurrencesASkipSteppedOver(): void
    {
        $task = $this->dated('2026-06-01', 'FREQ=WEEKLY;COUNT=5');

        $this->recurrence->advance($task, $this->at('2026-06-20 10:00'), $this->berlin);

        self::assertSame('2026-06-22', $task->dueDate->format('Y-m-d'));
        self::assertSame('FREQ=WEEKLY;COUNT=2', $task->recurrenceRule);
    }

    public function testUntilEndsIt(): void
    {
        $task = $this->dated('2026-06-01', 'FREQ=WEEKLY;UNTIL=20260610T000000Z');

        self::assertTrue($this->recurrence->advance($task, $this->at('2026-06-01 10:00'), $this->berlin));
        self::assertFalse($this->recurrence->advance($task, $this->at('2026-06-08 10:00'), $this->berlin));
    }

    public function testTheStartAndTheReminderKeepTheirDistance(): void
    {
        $task             = $this->dated('2026-06-15', 'FREQ=WEEKLY');
        $task->startDate  = new DateTimeImmutable('2026-06-13', new DateTimeZone('UTC'));
        $task->reminderAt = new DateTimeImmutable('2026-06-14 18:00:00', new DateTimeZone('UTC'));

        $this->recurrence->advance($task, $this->at('2026-06-15 10:00'), $this->berlin);

        self::assertSame('2026-06-20', $task->startDate->format('Y-m-d'));
        self::assertSame('2026-06-21 18:00', $task->reminderAt->format('Y-m-d H:i'));
    }

    public function testAnUnreadableRuleFinishesTheTaskRatherThanThrowing(): void
    {
        self::assertFalse($this->recurrence->advance($this->dated('2026-06-15', 'NOT A RULE'), $this->at('2026-06-15 10:00'), $this->berlin));
    }

    public function testATaskThatDoesNotRepeatDoesNotMove(): void
    {
        self::assertFalse($this->recurrence->advance($this->dated('2026-06-15', null), $this->at('2026-06-15 10:00'), $this->berlin));
    }

    public function testAMonthlyRuleLandsOnTheSameDay(): void
    {
        $task = $this->dated('2026-01-15', 'FREQ=MONTHLY');

        $this->recurrence->advance($task, $this->at('2026-01-15 10:00'), $this->berlin);

        self::assertSame('2026-02-15', $task->dueDate?->format('Y-m-d'));
    }

    private function dated(string $date, ?string $rule): Task
    {
        $task                 = new Task();
        $task->dueDate        = new DateTimeImmutable($date, new DateTimeZone('UTC'));
        $task->recurrenceRule = $rule;

        return $task;
    }

    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }
}
