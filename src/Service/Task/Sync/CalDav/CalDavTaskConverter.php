<?php

declare(strict_types=1);

namespace App\Service\Task\Sync\CalDav;

use App\Domain\DTO\Task\RemoteTask;
use App\Domain\Enum\Task\TaskStatus;
use App\Entity\Task\Task;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VTodo;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as ICalDateTime;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Between a VTODO and a Task, in both directions.
 *
 * **Writing starts from what the server last sent.** A task created elsewhere
 * carries properties plMail has no column for (ATTACH, X-APPLE-SORT-ORDER, a
 * second VALARM, a recurring task's overridden instances), and CalDavEventConverter
 * rebuilds its VEVENT from nothing, which is why it drops them. Here the stored
 * ICS is parsed and only supported properties are touched, so what another client
 * put there goes back out as it came. Only a task the server has never seen is
 * built from nothing.
 *
 * **A property is rewritten only when it changed.** A DUE;TZID=Europe/Berlin that
 * still means the same instant stays as it is, instead of becoming UTC and taking
 * the VTIMEZONE it relied on with it on the next client's read.
 *
 * Dates keep their shape: DUE;VALUE=DATE is a date and stays one, never midnight
 * UTC. A floating time is read in the owner's zone, the only zone it could mean.
 */
final readonly class CalDavTaskConverter
{
    private const string PRODID = '-//plMail//Tasks//EN';

    /** What a parent's RELATED-TO counts as when it names no type: RFC 5545's default. */
    private const string RELTYPE_PARENT = 'PARENT';

    /** The address a kept file is linked at, past the install's own origin. */
    public const string OWN_ATTACHMENT_PATH = '/tasks/attachment/';

    /**
     * @return RemoteTask|null null for a resource holding no VTODO, which on a
     *                         mixed collection is every event
     */
    public function toRemoteTask(string $ics, string $href, ?string $etag, DateTimeZone $ownerZone): ?RemoteTask
    {
        $calendar = $this->parse($ics);
        $todo     = null === $calendar ? null : $this->masterOf($calendar);

        if (null === $todo) {
            return null;
        }

        [$dueDate, $dueAt, $dueZone]       = $this->readDate($todo, 'DUE', $ownerZone);
        [$startDate, $startAt, $startZone] = $this->readDate($todo, 'DTSTART', $ownerZone);

        return new RemoteTask(
            remoteId:       $href,
            etag:           $etag,
            uid:            $this->valueOf($todo, 'UID'),
            title:          $this->valueOf($todo, 'SUMMARY'),
            notes:          '' === $this->valueOf($todo, 'DESCRIPTION') ? null : $this->valueOf($todo, 'DESCRIPTION'),
            status:         $this->statusOf($todo),
            completedAt:    $this->instantOf($todo, 'COMPLETED', $ownerZone),
            dueDate:        $dueDate,
            dueAt:          $dueAt,
            startDate:      $startDate,
            startAt:        $startAt,
            timeZone:       $dueZone ?? $startZone,
            priority:       max(0, min(9, (int) $this->valueOf($todo, 'PRIORITY'))),
            reminderAt:     $this->reminderOf($todo, $dueAt, $startAt, $dueDate, $ownerZone),
            recurrenceRule: '' === $this->valueOf($todo, 'RRULE') ? null : $this->valueOf($todo, 'RRULE'),
            parentUid:      $this->parentUidOf($todo),
            payload:        $ics,
            links:          $this->linksOf($todo),
        );
    }

    /** The whole resource to send, built on the stored copy where there is one. */
    public function toIcs(Task $task, DateTimeImmutable $now, DateTimeZone $ownerZone, string $publicUrl = ''): string
    {
        $calendar = null === $task->remotePayload ? null : $this->parse($task->remotePayload);
        $todo     = null === $calendar ? null : $this->masterOf($calendar);

        if (null === $calendar || null === $todo) {
            $calendar = new VCalendar(['PRODID' => self::PRODID]);

            /** @var VTodo $todo */
            $todo = $calendar->add('VTODO', ['UID' => $task->uid]);
        }

        $this->setText($todo, 'SUMMARY', $task->title);
        $this->setText($todo, 'DESCRIPTION', $task->notes);
        $this->writeStatus($todo, $task);
        $this->writeDate($todo, 'DUE', $task->dueDate, $task->dueAt, $ownerZone);
        $this->writeRecurrence($todo, $task->recurrenceRule);
        $this->writeReminder($todo, $task, $ownerZone);
        $this->writeParent($todo, $task->parent?->uid);
        $this->writeOwnAttachments($todo, $task, $publicUrl);

        // Every revision says when it was made and that it is a new one, which
        // is what makes another client take it over the copy it holds.
        unset($todo->{'LAST-MODIFIED'}, $todo->DTSTAMP);
        $todo->add('DTSTAMP', $now->setTimezone(new DateTimeZone('UTC')));
        $todo->add('LAST-MODIFIED', $now->setTimezone(new DateTimeZone('UTC')));

        $sequence = (int) $this->valueOf($todo, 'SEQUENCE');
        unset($todo->SEQUENCE);
        $todo->add('SEQUENCE', $sequence + 1);

        return $calendar->serialize();
    }

    // ── Reading ───────────────────────────────────────────────────────────────

    /** One parameter of a property, or null. Same reason as valueOf(). */
    private function parameterOf(Property $property, string $name): ?string
    {
        $parameter = $property->parameters()[$name] ?? null;

        return null === $parameter ? null : $parameter->getValue();
    }

    /** A property's text, or nothing: sabre's magic access has no type to read. */
    private function valueOf(VTodo $todo, string $name): string
    {
        $property = $todo->select($name)[0] ?? null;

        return $property instanceof Property ? (string) $property : '';
    }

    private function parse(string $ics): ?VCalendar
    {
        try {
            $document = Reader::read($ics, Reader::OPTION_FORGIVING);
        } catch (Throwable) {
            return null;
        }

        return $document instanceof VCalendar ? $document : null;
    }

    /**
     * The VTODO that is the task itself. A recurring task's overridden instances
     * share its UID and carry a RECURRENCE-ID, and stay in the file untouched.
     */
    private function masterOf(VCalendar $calendar): ?VTodo
    {
        $first = null;

        foreach ($calendar->select('VTODO') as $todo) {
            if (false === $todo instanceof VTodo) {
                continue;
            }

            if (null === ($todo->{'RECURRENCE-ID'} ?? null)) {
                return $todo;
            }

            $first ??= $todo;
        }

        return $first;
    }

    private function statusOf(VTodo $todo): TaskStatus
    {
        $status = mb_strtolower($this->valueOf($todo, 'STATUS'));

        // A task with a completion date and no status is finished: several
        // clients write only one of the two.
        return TaskStatus::tryFrom($status)
            ?? (null !== ($todo->COMPLETED ?? null) ? TaskStatus::Completed : TaskStatus::NeedsAction);
    }

    /** @return array{?DateTimeImmutable, ?DateTimeImmutable, ?string} date, instant, zone */
    private function readDate(VTodo $todo, string $name, DateTimeZone $ownerZone): array
    {
        $property = $todo->{$name} ?? null;

        if (false === $property instanceof ICalDateTime) {
            return [null, null, null];
        }

        try {
            if (false === $property->hasTime()) {
                return [
                    new DateTimeImmutable($property->getDateTime()->format('Y-m-d'), new DateTimeZone('UTC')),
                    null,
                    null,
                ];
            }

            $instant = DateTimeImmutable::createFromInterface($property->getDateTime($ownerZone));
            $zone    = $this->parameterOf($property, 'TZID');

            return [null, $instant->setTimezone(new DateTimeZone('UTC')), $zone];
        } catch (Throwable) {
            return [null, null, null];
        }
    }

    private function instantOf(VTodo $todo, string $name, DateTimeZone $ownerZone): ?DateTimeImmutable
    {
        return $this->readDate($todo, $name, $ownerZone)[1];
    }

    /**
     * The first alarm, as one point in time, whichever way it was written: an
     * absolute TRIGGER, or a duration from the DUE or DTSTART it names. One per
     * task is all plMail shows; the others stay in the file.
     */
    private function reminderOf(
        VTodo $todo,
        ?DateTimeImmutable $dueAt,
        ?DateTimeImmutable $startAt,
        ?DateTimeImmutable $dueDate,
        DateTimeZone $ownerZone,
    ): ?DateTimeImmutable {
        foreach ($todo->select('VALARM') as $alarm) {
            $trigger = $alarm->TRIGGER ?? null;

            if (null === $trigger) {
                continue;
            }

            try {
                if ($trigger instanceof ICalDateTime) {
                    return DateTimeImmutable::createFromInterface($trigger->getDateTime($ownerZone))
                        ->setTimezone(new DateTimeZone('UTC'));
                }

                $related = mb_strtoupper($this->parameterOf($trigger, 'RELATED') ?? 'DUE');
                $anchor  = 'START' === $related
                    ? $startAt
                    : ($dueAt ?? (null === $dueDate ? null : new DateTimeImmutable($dueDate->format('Y-m-d'), $ownerZone)));

                if (null === $anchor) {
                    continue;
                }

                $offset = DateTimeParser::parseDuration((string) $trigger);

                return $anchor->setTimezone(new DateTimeZone('UTC'))->add($offset);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function parentUidOf(VTodo $todo): ?string
    {
        foreach ($todo->select('RELATED-TO') as $related) {
            $type = mb_strtoupper($this->parameterOf($related, 'RELTYPE') ?? self::RELTYPE_PARENT);

            if (self::RELTYPE_PARENT === $type && '' !== trim((string) $related)) {
                return trim((string) $related);
            }
        }

        return null;
    }

    /**
     * Files kept elsewhere, by address. Inline Base64 is skipped: it stays in the
     * payload untouched, and listing it would promise a download there is none of.
     * Our own links are skipped too, or a kept file would come back as a stranger.
     *
     * @return list<array{url: string, filename: string}>
     */
    private function linksOf(VTodo $todo): array
    {
        $links = [];

        foreach ($todo->select('ATTACH') as $attach) {
            $url = trim((string) $attach);

            if (false !== stripos((string) $this->parameterOf($attach, 'ENCODING'), 'BASE64')
                || 1 !== preg_match('#^https?://#i', $url)
                || true === str_contains($url, self::OWN_ATTACHMENT_PATH)) {
                continue;
            }

            $links[] = ['url' => $url, 'filename' => $this->filenameOf($attach, $url)];
        }

        return $links;
    }

    /** The name another client gave it, in any of its spellings, or the address's end. */
    private function filenameOf(Property $attach, string $url): string
    {
        foreach (['FILENAME', 'X-FILENAME', 'X-ORACLE-FILENAME'] as $parameter) {
            $name = trim((string) $this->parameterOf($attach, $parameter));

            if ('' !== $name) {
                return $name;
            }
        }

        $segment = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));

        return '' === $segment ? $url : $segment;
    }

    // ── Writing ───────────────────────────────────────────────────────────────

    /**
     * A link back here for each kept file. Rewritten whole because it is ours, and
     * only ours: a foreign ATTACH stays where it is. The link needs a plMail login
     * to open, which is the trade the design accepts over inlining 25 MB per sync.
     */
    private function writeOwnAttachments(VTodo $todo, Task $task, string $publicUrl): void
    {
        foreach ($todo->select('ATTACH') as $attach) {
            if (true === str_contains((string) $attach, self::OWN_ATTACHMENT_PATH)) {
                $todo->remove($attach);
            }
        }

        if ('' === $publicUrl) {
            return;
        }

        foreach ($task->attachments as $attachment) {
            if (true === $attachment->isStoredHere()) {
                $parameters = ['FILENAME' => $attachment->filename];

                if (null !== $attachment->contentType) {
                    $parameters['FMTTYPE'] = $attachment->contentType;
                }

                $todo->add(
                    'ATTACH',
                    sprintf('%s%s%d', rtrim($publicUrl, '/'), self::OWN_ATTACHMENT_PATH, (int) $attachment->id),
                    $parameters,
                );
            }
        }
    }

    private function setText(VTodo $todo, string $name, ?string $value): void
    {
        if (null === $value || '' === $value) {
            unset($todo->{$name});

            return;
        }

        if ($this->valueOf($todo, $name) !== $value) {
            unset($todo->{$name});
            $todo->add($name, $value);
        }
    }

    private function writeStatus(VTodo $todo, Task $task): void
    {
        // Left as it is while the two agree on being open or not: a client's
        // IN-PROCESS and its PERCENT-COMPLETE are worth keeping through a rename.
        $current = mb_strtolower($this->valueOf($todo, 'STATUS'));
        $changed = ('' === $current ? TaskStatus::NeedsAction->value : $current) !== $task->status->value;

        if (true === $changed) {
            unset($todo->STATUS);
            $todo->add('STATUS', mb_strtoupper($task->status->value));
        }

        if (TaskStatus::Completed === $task->status) {
            unset($todo->COMPLETED, $todo->{'PERCENT-COMPLETE'});
            $todo->add('COMPLETED', ($task->completedAt ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC')));
            $todo->add('PERCENT-COMPLETE', 100);

            return;
        }

        unset($todo->COMPLETED);

        // Only a finished task's 100: a client's 40% means something, and a task
        // reopened at 100% would come back finished on the client that reads it.
        if ('100' === $this->valueOf($todo, 'PERCENT-COMPLETE')) {
            unset($todo->{'PERCENT-COMPLETE'});
        }
    }

    private function writeDate(
        VTodo $todo,
        string $name,
        ?DateTimeImmutable $date,
        ?DateTimeImmutable $instant,
        DateTimeZone $ownerZone,
    ): void {
        [$currentDate, $currentInstant] = $this->readDate($todo, $name, $ownerZone);

        if ($currentDate?->format('Y-m-d') === $date?->format('Y-m-d')
            && $currentInstant?->getTimestamp() === $instant?->getTimestamp()) {
            return;
        }

        unset($todo->{$name});

        if (null !== $date) {
            $todo->add($name, $date->format('Ymd'), ['VALUE' => 'DATE']);
        } elseif (null !== $instant) {
            $todo->add($name, $instant->setTimezone(new DateTimeZone('UTC')));
        }
    }

    private function writeRecurrence(VTodo $todo, ?string $rule): void
    {
        if ($this->valueOf($todo, 'RRULE') === (string) $rule) {
            return;
        }

        unset($todo->RRULE);

        if (null !== $rule && '' !== $rule) {
            $todo->add('RRULE', $rule);
        }
    }

    /**
     * Only the first alarm is plMail's to change, and only when it moved: a
     * relative TRIGGER that still lands on the same moment stays relative, and
     * further alarms from other clients stay where they are.
     */
    private function writeReminder(VTodo $todo, Task $task, DateTimeZone $ownerZone): void
    {
        [$dueDate, $dueAt]  = $this->readDate($todo, 'DUE', $ownerZone);
        [, $startAt]        = $this->readDate($todo, 'DTSTART', $ownerZone);
        $current            = $this->reminderOf($todo, $dueAt, $startAt, $dueDate, $ownerZone);

        if ($current?->getTimestamp() === $task->reminderAt?->getTimestamp()) {
            return;
        }

        $alarm = null;

        foreach ($todo->select('VALARM') as $candidate) {
            if (null !== ($candidate->TRIGGER ?? null)) {
                $alarm = $candidate;

                break;
            }
        }

        if (null === $task->reminderAt) {
            if ($alarm instanceof Component) {
                $todo->remove($alarm);
            }

            return;
        }

        if (false === $alarm instanceof Component) {
            /** @var Component $alarm */
            $alarm = $todo->add('VALARM', ['ACTION' => 'DISPLAY', 'DESCRIPTION' => $task->title]);
        }

        unset($alarm->TRIGGER);
        $alarm->add('TRIGGER', $task->reminderAt->setTimezone(new DateTimeZone('UTC')), ['VALUE' => 'DATE-TIME']);
    }

    private function writeParent(VTodo $todo, ?string $parentUid): void
    {
        if ($this->parentUidOf($todo) === $parentUid) {
            return;
        }

        foreach ($todo->select('RELATED-TO') as $related) {
            if (self::RELTYPE_PARENT === mb_strtoupper($this->parameterOf($related, 'RELTYPE') ?? self::RELTYPE_PARENT)) {
                $todo->remove($related);
            }
        }

        if (null !== $parentUid) {
            $todo->add('RELATED-TO', $parentUid);
        }
    }
}
