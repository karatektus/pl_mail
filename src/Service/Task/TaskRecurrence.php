<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Entity\Task\Task;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\Recur\RRuleIterator;

/**
 * Moves a repeating task to its next date.
 *
 * Ticking one off does not finish it. The same row, the same UID, a new due date,
 * and no finished copy on the server, which would fill the list there (#75). What
 * was done stays visible through an entry TaskWriter leaves here, in plMail alone.
 *
 * **The next date is in the future.** A weekly task ticked three weeks late goes
 * to next week, not to a date that is already overdue.
 *
 * **COUNT has to be spent by hand.** The rule is read from the current due date
 * each time, so COUNT=5 would mean five more for ever. Each move takes off the
 * occurrences it stepped over, and a rule with nothing left means the task is
 * finished after all, which is the answer for UNTIL too.
 *
 * Walked in the zone it was written in, or a daily 09:00 would drift by an hour
 * across a clock change.
 */
final class TaskRecurrence
{
    /** A rule that never lands in the future must not loop inside a request. */
    private const int MAX_STEPS = 10000;

    /**
     * @return bool false when the rule has nothing left, and the task is finished
     */
    public function advance(Task $task, DateTimeImmutable $now, DateTimeZone $zone): bool
    {
        if (null === $task->recurrenceRule || '' === $task->recurrenceRule) {
            return false;
        }

        $isDate = null !== $task->dueDate || (null === $task->dueAt && null !== $task->startDate);
        $walk   = $isDate ? new DateTimeZone('UTC') : (null === $task->timeZone ? $zone : $this->zone($task->timeZone, $zone));
        $anchor = $this->anchorOf($task, $now, $walk);
        $floor  = $isDate
            ? new DateTimeImmutable($now->setTimezone($zone)->format('Y-m-d'), $walk)
            : $now;

        try {
            $occurrences = new RRuleIterator($task->recurrenceRule, $anchor);
        } catch (InvalidDataException) {
            return false;
        }

        $steps = 0;
        $next  = null;

        while ($occurrences->valid() && $steps < self::MAX_STEPS) {
            $candidate = DateTimeImmutable::createFromInterface($occurrences->current());

            if ($candidate > $anchor && $candidate > $floor) {
                $next = $candidate;

                break;
            }

            $occurrences->next();
            ++$steps;
        }

        if (null === $next) {
            return false;
        }

        $this->moveTo($task, $anchor, $next, $isDate);
        $task->recurrenceRule = $this->spendCount($task->recurrenceRule, $steps);

        return true;
    }

    /**
     * The first date the rule starts from: what the task is due on, or what it
     * starts on, or today for a repeating task that was given neither.
     */
    private function anchorOf(Task $task, DateTimeImmutable $now, DateTimeZone $walk): DateTimeImmutable
    {
        $date    = $task->dueDate ?? $task->startDate;
        $instant = $task->dueAt ?? $task->startAt;

        if (null !== $date) {
            return new DateTimeImmutable($date->format('Y-m-d'), $walk);
        }

        return ($instant ?? $now)->setTimezone($walk);
    }

    /** The start and the reminder keep their distance from the due date. */
    private function moveTo(Task $task, DateTimeImmutable $anchor, DateTimeImmutable $next, bool $isDate): void
    {
        $utc   = new DateTimeZone('UTC');
        $delta = $anchor->diff($next);

        if (true === $isDate) {
            $hasDue        = null !== $task->dueDate || null === $task->startDate;
            $task->dueDate = true === $hasDue ? new DateTimeImmutable($next->format('Y-m-d'), $utc) : null;

            if (null !== $task->startDate) {
                $task->startDate = $this->shifted($task->startDate, $delta);
            }
        } else {
            $task->dueAt = $next->setTimezone($utc);

            if (null !== $task->startAt) {
                $task->startAt = $this->shifted($task->startAt, $delta);
            }
        }

        if (null !== $task->reminderAt) {
            $task->reminderAt = $this->shifted($task->reminderAt, $delta);
        }
    }

    private function shifted(DateTimeImmutable $date, DateInterval $delta): DateTimeImmutable
    {
        return $date->add($delta);
    }

    /** RRULE's COUNT, less the occurrences just stepped over. The rest of it stays as written. */
    private function spendCount(string $rule, int $steps): string
    {
        // The anchor itself is one occurrence, so the move spends one more than
        // the iterator stepped past to find the next one.
        return (string) preg_replace_callback(
            '/(^|;)COUNT=(\d+)/i',
            static fn (array $match): string => sprintf('%sCOUNT=%d', $match[1], max(1, (int) $match[2] - max(1, $steps))),
            $rule,
        );
    }

    private function zone(string $name, DateTimeZone $fallback): DateTimeZone
    {
        try {
            return new DateTimeZone($name);
        } catch (\Exception) {
            return $fallback;
        }
    }
}
