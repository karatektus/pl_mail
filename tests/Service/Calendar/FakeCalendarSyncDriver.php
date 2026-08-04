<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\DTO\Calendar\CalendarChangeSet;
use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Calendar\RemoteCalendar;
use App\Domain\DTO\Calendar\RemoteWriteResult;
use App\Domain\Interface\CalendarSyncDriverInterface;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;

/**
 * A remote calendar that lives in an array.
 *
 * The seam the engine is testable through, and the reason the interface exists
 * in the shape it does. Every collaborator in Service/Calendar is final, so
 * none of them can be doubled; the one axis that has to be controlled from a
 * test is what the remote says, and that is exactly what this interface is for.
 * Writing a fake against it is also the first real check that the contract is
 * implementable without reaching for a provider concept — if the fake needed
 * one, so would Google's driver.
 *
 * Records what it was asked to do, because half the claims here are about
 * calls that must NOT happen: no push to a read-only calendar, no second pull
 * after a resync that succeeded, no write for an unchanged etag.
 */
final class FakeCalendarSyncDriver implements CalendarSyncDriverInterface
{
    /**
     * Queued answers to pull(), consumed in order. The last one repeats, so a
     * test that only cares about the first window does not have to enumerate
     * the rest.
     *
     * @var list<CalendarChangeSet>
     */
    public array $changeSets = [];

    /** @var list<RemoteCalendar> */
    public array $calendars = [];

    /**
     * Every call in the order it arrived — 'push', 'pull', 'delete'. The push-
     * before-pull rule is an ordering claim and nothing else records ordering.
     *
     * @var list<string>
     */
    public array $calls = [];

    /**
     * The tokens pull() was handed, in order. `[null]` after a resync means the
     * engine really did forget the dead one.
     *
     * @var list<string|null>
     */
    public array $pulledWith = [];

    /** @var list<CalendarEvent> */
    public array $pushed = [];

    /** @var list<CalendarEvent> */
    public array $deleted = [];

    /** What push() hands back. Null means "mint an id from the event's uid". */
    public ?RemoteWriteResult $writeResult = null;

    /** Thrown by push() when set, to exercise the per-event failure paths. */
    public ?\Throwable $pushThrows = null;

    public bool $supportsEverything = true;

    public function supports(CalendarSource $source): bool
    {
        return $this->supportsEverything;
    }

    public function discover(CalendarSource $source): array
    {
        return $this->calendars;
    }

    public function pull(Calendar $calendar, ?string $syncToken): CalendarChangeSet
    {
        $this->calls[]      = 'pull';
        $this->pulledWith[] = $syncToken;

        if ([] === $this->changeSets) {
            return CalendarChangeSet::unchanged($syncToken);
        }

        return 1 === count($this->changeSets)
            ? $this->changeSets[0]
            : array_shift($this->changeSets);
    }

    public function push(Calendar $calendar, CalendarEvent $event): RemoteWriteResult
    {
        $this->calls[] = 'push';

        if (null !== $this->pushThrows) {
            throw $this->pushThrows;
        }

        $this->pushed[] = $event;

        return $this->writeResult ?? new RemoteWriteResult(
            remoteId: 'remote-' . $event->uid,
            etag:     'etag-' . count($this->pushed),
        );
    }

    public function delete(Calendar $calendar, CalendarEvent $event): void
    {
        $this->calls[]   = 'delete';
        $this->deleted[] = $event;
    }
}
