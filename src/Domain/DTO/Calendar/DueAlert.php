<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use DateTimeImmutable;

/**
 * One alert, on one occurrence, that is due now.
 *
 * What DueAlertReader produces and AlertDeliverer consumes. It carries the
 * event and the user rather than their ids alone because every channel needs
 * them whole — the push needs a title, the mail needs an address and a zone —
 * and it carries the ids as well because the claim is a raw INSERT that wants
 * integers and must not be the place that discovers an id was null.
 *
 * That redundancy is deliberate and is the reason this is a DTO rather than a
 * pair of entities passed around: the reader has already established that the
 * event has an id, an owner and an occurrence with a recurrence id, so nothing
 * downstream repeats those three checks or has to decide what to do when one
 * fails.
 *
 * **Not a Messenger payload**, and it must not become one. The alert sweep runs
 * in the command that found the work; sending this through a queue would mean
 * ids only, a second load, and a window between claiming an alert and delivering
 * it in which the worker can die — which is precisely the failure the claim is
 * there to make harmless, so it would be adding one to remove one.
 *
 * $startsAt and $endsAt are the OCCURRENCE's, not the series' — an instance
 * dragged to Thursday alerts about Thursday.
 */
final readonly class DueAlert
{
    public function __construct(
        public CalendarEvent     $event,
        public User              $user,
        public int               $eventId,
        public int               $userId,
        public EventAlert        $alert,
        public DateTimeImmutable $recurrenceId,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public DateTimeImmutable $triggerAt,
    ) {
    }
}
