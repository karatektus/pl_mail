<?php

declare(strict_types=1);

namespace App\Service\Task\Sync\Graph;

use App\Domain\DTO\Task\RemoteTask;
use App\Domain\Enum\Task\TaskStatus;
use App\Entity\Task\Task;
use App\Service\Calendar\RecurrenceRuleConverter;
use App\Service\Calendar\Sync\Graph\GraphRecurrenceMapper;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Between a Microsoft To Do task and a Task, in both directions.
 *
 * What Microsoft does with these fields was tried against a real account before
 * any of this was written, and the answers are not the ones the reference implies:
 *
 * - **A due date has no time.** A due of 15:30 comes back as midnight of that day
 *   in the zone it was sent in, written in UTC (23:00 the day before, for Berlin).
 *   A repeating task's due comes back as 00:00 UTC instead. dateOf() reads both.
 * - **The body is text.** HTML sent in is stored as its text.
 * - **A reminder keeps its time**, and is the only field here that does.
 *
 * **Only what changed goes out.** The stored payload is the last copy Microsoft
 * sent, and a field is written only when the task now says something different
 * from what that copy says. A title edit therefore leaves the categories, the
 * importance, the start date and any linked resources another client put there
 * exactly as they were, and never sends a body somebody wrote as HTML back as text.
 *
 * Steps are a task's `checklistItems`, not tasks of their own, so they travel
 * inside it: each becomes a RemoteTask whose id is the task's id and the item's,
 * and the task says which steps it has, so one dropped elsewhere is dropped here.
 */
final readonly class GraphTaskMapper
{
    private const array STATUS_IN = [
        'notstarted'      => TaskStatus::NeedsAction,
        'inprogress'      => TaskStatus::InProcess,
        'completed'       => TaskStatus::Completed,
        'waitingonothers' => TaskStatus::InProcess,
        'deferred'        => TaskStatus::NeedsAction,
    ];

    /** Cancelled has no word at Microsoft, and finished is what it is closest to. */
    private const array STATUS_OUT = [
        TaskStatus::NeedsAction->value => 'notStarted',
        TaskStatus::InProcess->value   => 'inProgress',
        TaskStatus::Completed->value   => 'completed',
        TaskStatus::Cancelled->value   => 'completed',
    ];

    /** RFC 5545's scale, which Task::$priority uses: 1 is highest, 0 unset. */
    private const array IMPORTANCE = [
        'high'   => 1,
        'normal' => 0,
        'low'    => 9,
    ];

    public function __construct(
        private GraphRecurrenceMapper   $recurrence,
        private RecurrenceRuleConverter $rules,
    ) {}

    /** The separator between a task's id and a step's in a step's remote id. */
    public const string STEP_SEPARATOR = '/';

    /**
     * One task as Graph sends it, and its steps after it.
     *
     * @param array<string,mixed> $item
     *
     * @return list<RemoteTask>
     */
    public function fromGraph(array $item, DateTimeZone $ownerZone): array
    {
        $id = trim((string) ($item['id'] ?? ''));

        if ('' === $id) {
            return [];
        }

        if (true === array_key_exists('@removed', $item)) {
            return [RemoteTask::deleted($id)];
        }

        $items = true === is_array($item['checklistItems'] ?? null) ? array_values($item['checklistItems']) : [];
        $steps = [];

        foreach ($items as $position => $checklistItem) {
            $step = true === is_array($checklistItem) ? $this->stepOf($id, $checklistItem, $position) : null;

            if (null !== $step) {
                $steps[] = $step;
            }
        }

        $task = new RemoteTask(
            remoteId:       $id,
            etag:           is_string($item['@odata.etag'] ?? null) ? $item['@odata.etag'] : null,
            title:          (string) ($item['title'] ?? ''),
            notes:          $this->notesOf($item),
            status:         $this->statusOf($item),
            completedAt:    $this->instantOf($item['completedDateTime'] ?? null),
            dueDate:        $this->dateOf($item['dueDateTime'] ?? null, $ownerZone),
            startDate:      $this->dateOf($item['startDateTime'] ?? null, $ownerZone),
            priority:       self::IMPORTANCE[strtolower((string) ($item['importance'] ?? 'normal'))] ?? 0,
            reminderAt:     true === ($item['isReminderOn'] ?? false) ? $this->instantOf($item['reminderDateTime'] ?? null) : null,
            recurrenceRule: $this->ruleOf($item),
            payload:        $this->encode($item),
            stepRemoteIds:  array_map(static fn (RemoteTask $step): string => $step->remoteId, $steps),
        );

        return [$task, ...$steps];
    }

    /**
     * What a new task is created with: only what it has, since everything left out
     * is Microsoft's default.
     *
     * @return array<string,mixed>
     */
    public function forCreate(Task $task, DateTimeZone $ownerZone, DateTimeImmutable $today): array
    {
        $body = ['title' => $task->title];

        if (null !== $task->notes) {
            $body['body'] = ['content' => $task->notes, 'contentType' => 'text'];
        }

        if (TaskStatus::NeedsAction !== $task->status) {
            $body['status'] = self::STATUS_OUT[$task->status->value];
        }

        $due = $this->dueDayOf($task, $ownerZone, $today);

        if (null !== $due) {
            $body['dueDateTime'] = $this->dueOut($due, $ownerZone);
        }

        $body += $this->reminderOut($task);

        $recurrence = $this->recurrenceOut($task, $due);

        if (null !== $recurrence) {
            $body['recurrence'] = $recurrence;
        }

        return $body;
    }

    /**
     * What an existing task is patched with, as one PATCH or two.
     *
     * Two when the due date of a repeating task moves. Microsoft answers a PATCH of
     * dueDateTime on a repeating task by leaving an extra open copy behind, every
     * time and also with the recurrence in the same body. Taking the recurrence
     * off with the date and putting it back after leaves none, which is why the
     * second body exists and is sent only after the first worked.
     *
     * @return list<array<string,mixed>> empty when nothing changed
     */
    public function forUpdate(Task $task, ?string $payload, DateTimeZone $ownerZone, DateTimeImmutable $today): array
    {
        $before = $this->decode($payload);
        $body   = [];

        if ($task->title !== (string) ($before['title'] ?? '')) {
            $body['title'] = $task->title;
        }

        if ($task->notes !== $this->notesOf($before)) {
            $body['body'] = ['content' => (string) $task->notes, 'contentType' => 'text'];
        }

        if ($task->status !== $this->statusOf($before)) {
            $body['status'] = self::STATUS_OUT[$task->status->value];
        }

        $reminder = $this->reminderOut($task);

        if ($this->instantOf($reminder['reminderDateTime'] ?? null) != $this->reminderOf($before)) {
            $body += $reminder;
        }

        $due        = $this->dueDayOf($task, $ownerZone, $today);
        $dueBefore  = $this->dateOf($before['dueDateTime'] ?? null, $ownerZone);
        $dueMoved   = $due?->format('Y-m-d') !== $dueBefore?->format('Y-m-d');
        $ruleBefore = $this->ruleOf($before);
        $ruleMoved  = $task->recurrenceRule !== $ruleBefore;
        $repeats    = null !== $task->recurrenceRule || null !== $ruleBefore;

        if (true === $dueMoved && true === $repeats) {
            $first = $body + ['dueDateTime' => null === $due ? null : $this->dueOut($due, $ownerZone)];

            if (null !== $ruleBefore) {
                $first['recurrence'] = null;
            }

            $recurrence = $this->recurrenceOut($task, $due);

            return null === $recurrence ? [$first] : [$first, ['recurrence' => $recurrence]];
        }

        if (true === $dueMoved) {
            $body['dueDateTime'] = null === $due ? null : $this->dueOut($due, $ownerZone);
        }

        if (true === $ruleMoved) {
            $body['recurrence'] = $this->recurrenceOut($task, $due);
        }

        return [] === $body ? [] : [$body];
    }

    /**
     * A step's create or update body.
     *
     * @return array<string,mixed>
     */
    public function forStep(Task $step): array
    {
        return [
            'displayName' => $step->title,
            'isChecked'   => false === $step->status->isOpen(),
        ];
    }

    /**
     * The day a due stands for.
     *
     * 00:00 UTC is the day itself: that is how a repeating task's due comes back.
     * Anything else is a midnight somewhere, written in UTC, and only the owner's
     * zone turns 23:00 on the 30th back into the 1st they picked.
     *
     * @param mixed $value a dateTimeTimeZone
     */
    public function dateOf(mixed $value, DateTimeZone $ownerZone): ?DateTimeImmutable
    {
        $instant = $this->instantOf($value);

        if (null === $instant) {
            return null;
        }

        $day = '00:00:00' === $instant->format('H:i:s')
            ? $instant->format('Y-m-d')
            : $instant->setTimezone($ownerZone)->format('Y-m-d');

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));

        return false === $date ? null : $date;
    }

    /**
     * A dateTimeTimeZone as an instant, in UTC. Graph answers in UTC unless asked
     * otherwise; a zone it names is honoured all the same.
     *
     * @param mixed $value
     */
    public function instantOf(mixed $value): ?DateTimeImmutable
    {
        if (false === is_array($value) || false === is_string($value['dateTime'] ?? null) || '' === $value['dateTime']) {
            return null;
        }

        try {
            $zone = new DateTimeZone(is_string($value['timeZone'] ?? null) && '' !== $value['timeZone'] ? $value['timeZone'] : 'UTC');
        } catch (Throwable) {
            $zone = new DateTimeZone('UTC');
        }

        // Seven decimal places, which DateTimeImmutable does not parse.
        $text = preg_replace('/(\.\d{6})\d+$/', '$1', $value['dateTime']) ?? $value['dateTime'];

        try {
            return (new DateTimeImmutable($text, $zone))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    // ── Reading ───────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $item one checklistItem
     */
    private function stepOf(string $taskId, array $item, int $position): ?RemoteTask
    {
        $id = trim((string) ($item['id'] ?? ''));

        if ('' === $id) {
            return null;
        }

        $checked = true === ($item['isChecked'] ?? false);

        return new RemoteTask(
            remoteId:    $taskId . self::STEP_SEPARATOR . $id,
            title:       (string) ($item['displayName'] ?? ''),
            status:      true === $checked ? TaskStatus::Completed : TaskStatus::NeedsAction,
            completedAt: true === $checked ? $this->instantOf(['dateTime' => $item['checkedDateTime'] ?? null, 'timeZone' => 'UTC']) : null,
            parentUid:   $taskId,
            position:    $position,
            payload:     $this->encode($item),
        );
    }

    /** @param array<string,mixed> $item */
    private function notesOf(array $item): ?string
    {
        $body = $item['body'] ?? null;

        if (false === is_array($body) || false === is_string($body['content'] ?? null)) {
            return null;
        }

        $text = $body['content'];

        if ('html' === strtolower((string) ($body['contentType'] ?? ''))) {
            $text = html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>#i', "\n", $text) ?? $text), ENT_QUOTES | ENT_HTML5);
        }

        $text = trim(str_replace("\r\n", "\n", $text));

        return '' === $text ? null : $text;
    }

    /** @param array<string,mixed> $item */
    private function statusOf(array $item): TaskStatus
    {
        return self::STATUS_IN[strtolower((string) ($item['status'] ?? ''))] ?? TaskStatus::NeedsAction;
    }

    /** @param array<string,mixed> $item */
    private function reminderOf(array $item): ?DateTimeImmutable
    {
        return true === ($item['isReminderOn'] ?? false) ? $this->instantOf($item['reminderDateTime'] ?? null) : null;
    }

    /**
     * As an RRULE, through JSCalendar, the shape both converters speak. A pattern
     * neither can say is no rule, which the editor shows as "does not repeat"; the
     * stored payload still has it, and it is not written over unless the repeat is
     * changed here.
     *
     * @param array<string,mixed> $item
     */
    private function ruleOf(array $item): ?string
    {
        $recurrence = $item['recurrence'] ?? null;

        if (false === is_array($recurrence)) {
            return null;
        }

        $rule = $this->recurrence->toJsCalendar($recurrence);

        return null === $rule ? null : $this->rules->toRrule($rule);
    }

    // ── Writing ───────────────────────────────────────────────────────────────

    /**
     * The task's due day, or today for a repeating task that has none: Microsoft
     * keeps a repeat on a date, and a repeat with no date to start from is not
     * one it takes.
     */
    private function dueDayOf(Task $task, DateTimeZone $ownerZone, DateTimeImmutable $today): ?DateTimeImmutable
    {
        $due = $task->dueDay($ownerZone);

        if (null === $due && null !== $task->recurrenceRule) {
            $due = $today->setTimezone($ownerZone)->format('Y-m-d');
        }

        if (null === $due) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $due, new DateTimeZone('UTC'));

        return false === $date ? null : $date;
    }

    /** @return array{dateTime: string, timeZone: string} midnight of the day, in the owner's zone */
    private function dueOut(DateTimeImmutable $day, DateTimeZone $ownerZone): array
    {
        return ['dateTime' => $day->format('Y-m-d') . 'T00:00:00', 'timeZone' => $ownerZone->getName()];
    }

    /** @return array<string,mixed> */
    private function reminderOut(Task $task): array
    {
        if (null === $task->reminderAt) {
            return ['isReminderOn' => false, 'reminderDateTime' => null];
        }

        return [
            'isReminderOn'     => true,
            'reminderDateTime' => [
                'dateTime' => $task->reminderAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s'),
                'timeZone' => 'UTC',
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null null for no repeat, or one Graph cannot say
     */
    private function recurrenceOut(Task $task, ?DateTimeImmutable $due): ?array
    {
        if (null === $task->recurrenceRule || null === $due) {
            return null;
        }

        $rule = $this->rules->fromRrule($task->recurrenceRule);

        return null === $rule ? null : $this->recurrence->toGraph($rule, $due);
    }

    /** @return array<string,mixed> */
    private function decode(?string $payload): array
    {
        if (null === $payload || '' === $payload) {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $item */
    private function encode(array $item): string
    {
        return json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
