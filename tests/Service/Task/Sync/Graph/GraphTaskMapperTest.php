<?php

declare(strict_types=1);

namespace App\Tests\Service\Task\Sync\Graph;

use App\Domain\Enum\Task\TaskStatus;
use App\Entity\Task\Task;
use App\Service\Calendar\RecurrenceRuleConverter;
use App\Service\Calendar\Sync\Graph\GraphRecurrenceMapper;
use App\Service\Task\Sync\Graph\GraphTaskMapper;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The shapes Microsoft To Do answered with when it was tried against a real
 * account, held against what plMail makes of them. See GraphTaskMapper.
 */
final class GraphTaskMapperTest extends TestCase
{
    private GraphTaskMapper $mapper;
    private DateTimeZone $berlin;
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->mapper = new GraphTaskMapper(new GraphRecurrenceMapper(), new RecurrenceRuleConverter());
        $this->berlin = new DateTimeZone('Europe/Berlin');
        $this->today  = new DateTimeImmutable('2026-11-22 10:00:00', new DateTimeZone('UTC'));
    }

    /** What a due of the 1st sent from Berlin comes back as, and what a repeating task's does. */
    public function testADueComesBackAsTheDayItWasWhicheverWayMicrosoftWroteIt(): void
    {
        $midnightBerlin = ['dateTime' => '2026-11-30T23:00:00.0000000', 'timeZone' => 'UTC'];
        $repeating      = ['dateTime' => '2026-12-01T00:00:00.0000000', 'timeZone' => 'UTC'];

        self::assertSame('2026-12-01', $this->mapper->dateOf($midnightBerlin, $this->berlin)?->format('Y-m-d'));
        self::assertSame('2026-12-01', $this->mapper->dateOf($repeating, $this->berlin)?->format('Y-m-d'));
        self::assertSame('2026-12-01', $this->mapper->dateOf(['dateTime' => '2026-12-01T05:00:00.0000000', 'timeZone' => 'UTC'], new DateTimeZone('America/New_York'))?->format('Y-m-d'));
        self::assertNull($this->mapper->dateOf(null, $this->berlin));
    }

    public function testATaskArrivesWithItsStepsAndSaysWhichStepsItHas(): void
    {
        [$task, $first, $second] = $this->mapper->fromGraph([
            'id'               => 'T1',
            '@odata.etag'      => 'W/"1"',
            'title'            => 'Weekly review',
            'status'           => 'notStarted',
            'importance'       => 'high',
            'body'             => ['content' => "<p>Bring <b>numbers</b></p>", 'contentType' => 'html'],
            'dueDateTime'      => ['dateTime' => '2026-12-01T00:00:00.0000000', 'timeZone' => 'UTC'],
            'isReminderOn'     => true,
            'reminderDateTime' => ['dateTime' => '2026-12-01T08:15:00.0000000', 'timeZone' => 'UTC'],
            'recurrence'       => [
                'pattern' => ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => ['tuesday'], 'firstDayOfWeek' => 'monday'],
                'range'   => ['type' => 'noEnd', 'startDate' => '2026-12-01'],
            ],
            'categories'     => ['Someone else\'s'],
            'checklistItems' => [
                ['id' => 'c1', 'displayName' => 'Numbers', 'isChecked' => true, 'checkedDateTime' => '2026-11-22T10:08:37Z'],
                ['id' => 'c2', 'displayName' => 'Send', 'isChecked' => false],
            ],
        ], $this->berlin);

        self::assertSame('T1', $task->remoteId);
        self::assertSame('Bring numbers', $task->notes);
        self::assertSame(1, $task->priority);
        self::assertSame('2026-12-01', $task->dueDate?->format('Y-m-d'));
        self::assertNull($task->dueAt, 'To Do keeps no time of day');
        self::assertSame('2026-12-01 08:15', $task->reminderAt?->format('Y-m-d H:i'));
        self::assertStringStartsWith('FREQ=WEEKLY', (string) $task->recurrenceRule);
        self::assertSame(['T1/c1', 'T1/c2'], $task->stepRemoteIds);

        self::assertSame('T1', $first->parentUid);
        self::assertSame(TaskStatus::Completed, $first->status);
        self::assertSame(TaskStatus::NeedsAction, $second->status);
        self::assertSame(1, $second->position);
    }

    /** With $expand, a task without steps carries no checklistItems at all. */
    public function testATaskWithoutStepsSaysItHasNone(): void
    {
        [$task] = $this->mapper->fromGraph(['id' => 'T2', 'title' => 'Plain'], $this->berlin);

        self::assertSame([], $task->stepRemoteIds);
    }

    public function testARemovedTaskIsATombstone(): void
    {
        [$task] = $this->mapper->fromGraph(['id' => 'T3', '@removed' => ['reason' => 'deleted']], $this->berlin);

        self::assertTrue($task->isDeleted);
    }

    /** Regression guard against a title edit taking the categories, the importance or an HTML body with it. */
    public function testAnUpdateSendsOnlyWhatChanged(): void
    {
        $payload = $this->payload([
            'title'      => 'Old',
            'body'       => ['content' => '<p>Formatted</p>', 'contentType' => 'html'],
            'categories' => ['Kept'],
            'importance' => 'high',
        ]);

        $task                = $this->task('New');
        $task->notes         = 'Formatted';
        $task->remotePayload = $payload;

        self::assertSame([['title' => 'New']], $this->mapper->forUpdate($task, $payload, $this->berlin, $this->today));
    }

    public function testAnUnchangedTaskSendsNothing(): void
    {
        $payload = $this->payload(['title' => 'Same']);

        self::assertSame([], $this->mapper->forUpdate($this->task('Same'), $payload, $this->berlin, $this->today));
    }

    /** Microsoft leaves an extra copy for a moved due date on a repeating task; two writes leave none. */
    public function testMovingARepeatingTasksDueTakesTheRepeatOffAndPutsItBack(): void
    {
        $payload = $this->payload([
            'title'       => 'Repeats',
            'dueDateTime' => ['dateTime' => '2026-12-01T00:00:00.0000000', 'timeZone' => 'UTC'],
            'recurrence'  => [
                'pattern' => ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => ['tuesday']],
                'range'   => ['type' => 'noEnd', 'startDate' => '2026-12-01'],
            ],
        ]);

        [$before] = $this->mapper->fromGraph(json_decode($payload, true) + ['id' => 'T'], $this->berlin);

        $task                 = $this->task('Repeats');
        $task->dueDate        = new DateTimeImmutable('2026-12-08', new DateTimeZone('UTC'));
        $task->recurrenceRule = $before->recurrenceRule;

        $writes = $this->mapper->forUpdate($task, $payload, $this->berlin, $this->today);

        self::assertCount(2, $writes);
        self::assertNull($writes[0]['recurrence']);
        self::assertSame(['dateTime' => '2026-12-08T00:00:00', 'timeZone' => 'Europe/Berlin'], $writes[0]['dueDateTime']);
        self::assertSame('2026-12-08', $writes[1]['recurrence']['range']['startDate']);
    }

    public function testTickingAndTakingTheReminderOffAreSaid(): void
    {
        $payload = $this->payload([
            'title'            => 'Tick',
            'status'           => 'notStarted',
            'isReminderOn'     => true,
            'reminderDateTime' => ['dateTime' => '2026-12-01T08:15:00.0000000', 'timeZone' => 'UTC'],
        ]);

        $task         = $this->task('Tick');
        $task->status = TaskStatus::Completed;

        self::assertSame(
            [['status' => 'completed', 'isReminderOn' => false, 'reminderDateTime' => null]],
            $this->mapper->forUpdate($task, $payload, $this->berlin, $this->today),
        );
    }

    /** A repeat is kept on a date at Microsoft, so a repeating task with none starts today. */
    public function testANewRepeatingTaskWithoutADateIsGivenToday(): void
    {
        $task                 = $this->task('Daily');
        $task->recurrenceRule = 'FREQ=DAILY';

        $body = $this->mapper->forCreate($task, $this->berlin, $this->today);

        self::assertSame(['dateTime' => '2026-11-22T00:00:00', 'timeZone' => 'Europe/Berlin'], $body['dueDateTime']);
        self::assertSame('daily', $body['recurrence']['pattern']['type']);
        self::assertSame(['isReminderOn' => false, 'reminderDateTime' => null], array_intersect_key($body, ['isReminderOn' => 1, 'reminderDateTime' => 1]));
    }

    private function task(string $title): Task
    {
        $task        = new Task();
        $task->title = $title;

        return $task;
    }

    /** @param array<string,mixed> $item */
    private function payload(array $item): string
    {
        return json_encode($item, JSON_THROW_ON_ERROR);
    }
}
