<?php

declare(strict_types=1);

namespace App\Tests\Service\Task\Sync;

use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Enum\Task\TaskStatus;
use App\Entity\Task\Task;
use App\Entity\Task\TaskAttachment;
use App\Service\Task\Sync\CalDav\CalDavTaskConverter;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * A VTODO keeps what plMail did not change, and a date stays a date.
 *
 * The first half is the whole reason this converter parses the stored copy rather
 * than building a new one: CalDavEventConverter rebuilds, and that is how ATTACH
 * and X- properties get lost. These fixtures are written the way Nextcloud Tasks,
 * tasks.org and Apple Reminders are documented to write, but they are not captures
 * from those clients.
 */
final class CalDavTaskConverterTest extends TestCase
{
    private const string FOREIGN = <<<'ICS'
        BEGIN:VCALENDAR
        VERSION:2.0
        PRODID:-//Nextcloud Tasks//EN
        BEGIN:VTIMEZONE
        TZID:Europe/Berlin
        BEGIN:STANDARD
        DTSTART:19701025T030000
        TZOFFSETFROM:+0200
        TZOFFSETTO:+0100
        END:STANDARD
        END:VTIMEZONE
        BEGIN:VTODO
        UID:foreign-1
        SUMMARY:Water the plants
        DESCRIPTION:Both balconies
        STATUS:IN-PROCESS
        PERCENT-COMPLETE:40
        PRIORITY:3
        DUE;TZID=Europe/Berlin:20260615T090000
        DTSTART;VALUE=DATE:20260601
        CATEGORIES:Home,Garden
        RRULE:FREQ=WEEKLY
        URL:https://example.org/plants
        ATTACH:https://example.org/plants.pdf
        X-APPLE-SORT-ORDER:42
        SEQUENCE:7
        BEGIN:VALARM
        ACTION:DISPLAY
        DESCRIPTION:First
        TRIGGER;VALUE=DATE-TIME:20260615T060000Z
        END:VALARM
        BEGIN:VALARM
        ACTION:AUDIO
        TRIGGER:-PT5M
        END:VALARM
        END:VTODO
        END:VCALENDAR
        ICS;

    private CalDavTaskConverter $converter;
    private DateTimeZone $zone;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->converter = new CalDavTaskConverter();
        $this->zone      = new DateTimeZone('America/New_York');
        $this->now       = new DateTimeImmutable('2026-06-10 12:00:00', new DateTimeZone('UTC'));
    }

    public function testItReadsTheSupportedFields(): void
    {
        $remote = $this->converter->toRemoteTask(self::FOREIGN, 'https://dav/1.ics', '"e"', $this->zone);

        self::assertNotNull($remote);
        self::assertSame('foreign-1', $remote->uid);
        self::assertSame('Water the plants', $remote->title);
        self::assertSame('Both balconies', $remote->notes);
        self::assertSame(TaskStatus::InProcess, $remote->status);
        self::assertSame(3, $remote->priority);
        self::assertSame('FREQ=WEEKLY', $remote->recurrenceRule);
        self::assertSame('2026-06-15 07:00', $remote->dueAt->format('Y-m-d H:i'), 'an instant, in UTC');
        self::assertNull($remote->dueDate);
        self::assertSame('2026-06-01', $remote->startDate->format('Y-m-d'));
        self::assertSame('2026-06-15 06:00', $remote->reminderAt->format('Y-m-d H:i'));
    }

    public function testAnAllDayDueDateIsADateRatherThanMidnightUtc(): void
    {
        $remote = $this->converter->toRemoteTask($this->todo('DUE;VALUE=DATE:20260615'), 'h', null, $this->zone);

        self::assertNotNull($remote);
        self::assertSame('2026-06-15', $remote->dueDate?->format('Y-m-d'));
        self::assertNull($remote->dueAt);
    }

    public function testAFloatingTimeIsReadInTheOwnersZone(): void
    {
        $remote = $this->converter->toRemoteTask($this->todo('DUE:20260615T090000'), 'h', null, $this->zone);

        self::assertSame('2026-06-15 13:00', $remote?->dueAt?->format('Y-m-d H:i'));
    }

    public function testAResourceWithNoTodoIsNotATask(): void
    {
        $event = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:e\r\nDTSTART:20260615T090000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        self::assertNull($this->converter->toRemoteTask($event, 'h', null, $this->zone));
        self::assertNull($this->converter->toRemoteTask('not ical at all', 'h', null, $this->zone));
    }

    public function testATaskWithACompletionDateAndNoStatusIsFinished(): void
    {
        $remote = $this->converter->toRemoteTask($this->todo('COMPLETED:20260601T100000Z'), 'h', null, $this->zone);

        self::assertSame(TaskStatus::Completed, $remote?->status);
    }

    public function testARenameKeepsEverythingElseAsItCame(): void
    {
        $ics = $this->converter->toIcs($this->fromForeign(title: 'Renamed'), $this->now, $this->zone);

        foreach ([
            'SUMMARY:Renamed',
            'X-APPLE-SORT-ORDER:42',
            'ATTACH:https://example.org/plants.pdf',
            'URL;VALUE=URI:https://example.org/plants',
            'DUE;TZID=Europe/Berlin:20260615T090000',
            'DTSTART;VALUE=DATE:20260601',
            'STATUS:IN-PROCESS',
            'PERCENT-COMPLETE:40',
            'PRIORITY:3',
            'CATEGORIES:Home,Garden',
            'RRULE:FREQ=WEEKLY',
            'BEGIN:VTIMEZONE',
            'TRIGGER:-PT5M',
            'TRIGGER;VALUE=DATE-TIME:20260615T060000Z',
            'SEQUENCE:8',
        ] as $line) {
            self::assertStringContainsString($line, $this->unfolded($ics), $line);
        }

        self::assertStringNotContainsString('Water the plants', $ics);
    }

    public function testCompletingWritesAllThreeAndReopeningUndoesThem(): void
    {
        $task              = $this->fromForeign();
        $task->status      = TaskStatus::Completed;
        $task->completedAt = new DateTimeImmutable('2026-06-10 10:00:00', new DateTimeZone('UTC'));

        $done = $this->converter->toIcs($task, $this->now, $this->zone);

        self::assertStringContainsString('STATUS:COMPLETED', $done);
        self::assertStringContainsString('COMPLETED:20260610T100000Z', $done);
        self::assertStringContainsString('PERCENT-COMPLETE:100', $done);

        $task->remotePayload = $done;
        $task->status        = TaskStatus::NeedsAction;
        $task->completedAt   = null;

        $open = $this->converter->toIcs($task, $this->now, $this->zone);

        self::assertStringContainsString('STATUS:NEEDS-ACTION', $open);
        self::assertStringNotContainsString('COMPLETED:', str_replace('STATUS:COMPLETED', '', $open));
        self::assertStringNotContainsString('PERCENT-COMPLETE', $open);
    }

    public function testAMovedDueDateIsRewrittenAndAClearedOneRemoved(): void
    {
        $task          = $this->fromForeign();
        $task->dueAt   = null;
        $task->dueDate = new DateTimeImmutable('2026-07-01', new DateTimeZone('UTC'));

        self::assertStringContainsString('DUE;VALUE=DATE:20260701', $this->converter->toIcs($task, $this->now, $this->zone));

        $task->dueDate = null;

        self::assertStringNotContainsString('DUE', $this->converter->toIcs($task, $this->now, $this->zone));
    }

    public function testChangingTheReminderMovesOnlyTheFirstAlarm(): void
    {
        $task             = $this->fromForeign();
        $task->reminderAt = new DateTimeImmutable('2026-06-14 08:00:00', new DateTimeZone('UTC'));

        $ics = $this->unfolded($this->converter->toIcs($task, $this->now, $this->zone));

        self::assertStringContainsString('20260614T080000Z', $ics);
        self::assertStringNotContainsString('20260615T060000Z', $ics);
        self::assertStringContainsString('TRIGGER:-PT5M', $ics, 'the second alarm is another client\'s');
    }

    public function testANewTaskIsBuiltFromNothingWithAUidAndADate(): void
    {
        $task            = new Task();
        $task->uid       = 'local-1';
        $task->title     = 'New';
        $task->dueDate   = new DateTimeImmutable('2026-06-15', new DateTimeZone('UTC'));
        $task->syncState = SyncState::PendingCreate;

        $ics = $this->converter->toIcs($task, $this->now, $this->zone);

        self::assertStringContainsString('UID:local-1', $ics);
        self::assertStringContainsString('SUMMARY:New', $ics);
        self::assertStringContainsString('DUE;VALUE=DATE:20260615', $ics);
        self::assertStringContainsString('PRODID:-//plMail//Tasks//EN', $ics);
    }

    public function testAStepNamesItsParentAndAnotherClientsRelationStays(): void
    {
        $parent      = new Task();
        $parent->uid = 'parent-1';

        $task                = new Task();
        $task->uid           = 'step-1';
        $task->title         = 'Step';
        $task->parent        = $parent;
        $task->remotePayload = $this->todo('RELATED-TO;RELTYPE=SIBLING:other');

        $ics = $this->unfolded($this->converter->toIcs($task, $this->now, $this->zone));

        self::assertStringContainsString('RELATED-TO:parent-1', $ics);
        self::assertStringContainsString('RELATED-TO;RELTYPE=SIBLING:other', $ics);

        $remote = $this->converter->toRemoteTask($ics, 'h', null, $this->zone);

        self::assertSame('parent-1', $remote?->parentUid);
    }

    public function testAnOverriddenInstanceStaysInTheFileAndIsNotTheTask(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            . "BEGIN:VTODO\r\nUID:r\r\nRECURRENCE-ID:20260601T090000Z\r\nSUMMARY:Instance\r\nEND:VTODO\r\n"
            . "BEGIN:VTODO\r\nUID:r\r\nSUMMARY:Master\r\nRRULE:FREQ=DAILY\r\nEND:VTODO\r\n"
            . "END:VCALENDAR\r\n";

        self::assertSame('Master', $this->converter->toRemoteTask($ics, 'h', null, $this->zone)?->title);

        $task                 = new Task();
        $task->uid            = 'r';
        $task->title          = 'Renamed';
        $task->recurrenceRule = 'FREQ=DAILY';
        $task->remotePayload  = $ics;

        $written = $this->converter->toIcs($task, $this->now, $this->zone);

        self::assertStringContainsString('SUMMARY:Instance', $written);
        self::assertStringContainsString('SUMMARY:Renamed', $written);
    }

    public function testAKeptFileGoesOutAsALinkBackAndAForeignLinkStays(): void
    {
        $attachment              = new TaskAttachment();
        $attachment->filename    = 'notes.pdf';
        $attachment->contentType = 'application/pdf';
        $attachment->storedName  = 'kept';

        $task = $this->fromForeign();
        $task->attachments->add($attachment);

        $ics = $this->unfolded($this->converter->toIcs($task, $this->now, $this->zone, 'https://mail.example.org/'));

        self::assertStringContainsString('FILENAME=notes.pdf', $ics);
        self::assertStringContainsString('https://mail.example.org/tasks/attachment/', $ics);
        self::assertStringContainsString('ATTACH:https://example.org/plants.pdf', $ics, 'the other client\'s link is not ours to touch');
        self::assertStringNotContainsString('base64', mb_strtolower($ics), 'a link, never the file itself');
    }

    public function testSavingTwiceLeavesOneLinkBackPerFile(): void
    {
        $attachment             = new TaskAttachment();
        $attachment->filename   = 'notes.pdf';
        $attachment->storedName = 'kept';

        $task = $this->fromForeign();
        $task->attachments->add($attachment);

        $task->remotePayload = $this->converter->toIcs($task, $this->now, $this->zone, 'https://mail.example.org');
        $ics                 = $this->unfolded($this->converter->toIcs($task, $this->now, $this->zone, 'https://mail.example.org'));

        self::assertSame(1, substr_count($ics, 'mail.example.org/tasks/attachment/'));
    }

    public function testOnlyForeignAddressesAreReadAsLinks(): void
    {
        $remote = $this->converter->toRemoteTask($this->todo(
            'ATTACH;FILENAME=plan.pdf:https://files.example.org/x/plan.pdf',
            'ATTACH:https://mail.example.org/tasks/attachment/7',
            'ATTACH:https://other-plmail.example.net/tasks/attachment/9',
            'ATTACH;ENCODING=BASE64;VALUE=BINARY:aGVsbG8=',
            'ATTACH:ftp://files.example.org/y.pdf',
            'ATTACH:javascript:alert(1)',
        ), 'h', null, $this->zone, 'https://mail.example.org/');

        self::assertSame(
            [
                ['url' => 'https://files.example.org/x/plan.pdf', 'filename' => 'plan.pdf'],
                ['url' => 'https://other-plmail.example.net/tasks/attachment/9', 'filename' => '9'],
            ],
            $remote?->links,
            'our own link, inline data and any other scheme are not a foreign file to list; another plMail\'s link is',
        );
    }

    public function testAnotherSitesLinkWithTheSamePathStaysOnWrite(): void
    {
        $task                = $this->fromForeign();
        $task->remotePayload = str_replace(
            'ATTACH:https://example.org/plants.pdf',
            "ATTACH:https://example.org/plants.pdf\nATTACH:https://other-plmail.example.net/tasks/attachment/9",
            self::FOREIGN,
        );

        $ics = $this->unfolded($this->converter->toIcs($task, $this->now, $this->zone, 'https://mail.example.org'));

        self::assertStringContainsString('ATTACH:https://other-plmail.example.net/tasks/attachment/9', $ics);
    }

    public function testATasksStartIsWrittenAndMovesWithIt(): void
    {
        $task            = $this->fromForeign();
        $task->startDate = new DateTimeImmutable('2026-06-08', new DateTimeZone('UTC'));

        $ics = $this->converter->toIcs($task, $this->now, $this->zone);

        self::assertStringContainsString('DTSTART;VALUE=DATE:20260608', $ics);
        self::assertStringNotContainsString('20260601', $ics, 'the start the server had is replaced');
    }

    public function testARepeatingTaskWithNoStartIsSentItsDueDateAsOne(): void
    {
        $task                 = new Task();
        $task->uid            = 'local-2';
        $task->title          = 'Bins';
        $task->dueDate        = new DateTimeImmutable('2026-06-15', new DateTimeZone('UTC'));
        $task->recurrenceRule = 'FREQ=WEEKLY';
        $task->syncState      = SyncState::PendingCreate;

        $ics = $this->converter->toIcs($task, $this->now, $this->zone);

        self::assertStringContainsString('DTSTART;VALUE=DATE:20260615', $ics, 'what other clients anchor the RRULE on');
        self::assertStringContainsString('RRULE:FREQ=WEEKLY', $ics);

        $task->recurrenceRule = null;

        self::assertStringNotContainsString('DTSTART', $this->converter->toIcs($task, $this->now, $this->zone), 'a task that does not repeat needs none');
    }

    private function fromForeign(string $title = 'Water the plants'): Task
    {
        $remote = $this->converter->toRemoteTask(self::FOREIGN, 'h', null, $this->zone);

        self::assertNotNull($remote);

        $task                 = new Task();
        $task->uid            = 'foreign-1';
        $task->title          = $title;
        $task->notes          = $remote->notes;
        $task->status         = $remote->status;
        $task->dueAt          = $remote->dueAt;
        $task->startDate      = $remote->startDate;
        $task->startAt        = $remote->startAt;
        $task->reminderAt     = $remote->reminderAt;
        $task->recurrenceRule = $remote->recurrenceRule;
        $task->remotePayload  = self::FOREIGN;

        return $task;
    }

    private function todo(string ...$lines): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:u\r\nSUMMARY:T\r\n"
            . implode("\r\n", $lines) . "\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
    }

    /** iCalendar folds long lines; what is asserted is the property, not the fold. */
    private function unfolded(string $ics): string
    {
        return str_replace(["\r\n ", "\r\n\t"], '', $ics);
    }
}
