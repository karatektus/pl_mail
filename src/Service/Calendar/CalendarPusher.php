<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Interface\CalendarSyncDriverInterface;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Repository\Calendar\CalendarEventRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * Sends the local rows that owe the remote a write.
 *
 * Runs before the pull, always, and that ordering is the load-bearing decision
 * of the whole engine rather than an implementation detail. Pushing first means
 * the question the pull then answers — "did the remote change too?" — is asked
 * with the local edit already applied there, so the etag that comes back is the
 * etag of the merged state and the common case resolves to no conflict at all.
 * Pulling first would make every local edit a conflict with its own echo.
 *
 * A create and an update are one operation here, and the difference is read off
 * CalendarEvent::$remoteId rather than passed as a flag: the id is the fact, and
 * a flag beside it is a second copy of that fact that can disagree with it.
 *
 * Failures are per event. One event the remote refuses — a malformed recurrence
 * rule it will refuse forever, an attendee it will not accept — must not stop
 * the other nineteen from going out, and must not fail the run, because a run
 * that fails is a run that is retried, and the retry meets the same event.
 * Throttling and resync are the exceptions and are allowed through: both mean
 * the *connection* is unusable, not this row.
 *
 * Does not flush — it joins the caller's unit of work.
 */
final readonly class CalendarPusher
{
    public function __construct(
        private CalendarEventRepository $events,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
    ) {
    }

    /**
     * @return int how many events the remote accepted
     *
     * @throws LogicException when the calendar does not accept writes
     */
    public function push(Calendar $calendar, CalendarSyncDriverInterface $driver): int
    {
        // A LogicException rather than a CalendarSyncException, because this is
        // not a thing that can go wrong at a remote — it is this application
        // asking to write somewhere it has already recorded that it cannot. The
        // assertion lives here rather than only in the caller so that a second
        // caller, added later, cannot quietly skip the check: read-only is a
        // property of the calendar and the guard belongs where the writing is.
        if (true === $calendar->isReadOnly) {
            throw new LogicException(sprintf(
                'Calendar #%d is read-only and must never be pushed to.',
                (int) $calendar->id,
            ));
        }

        $pending = $this->events->findPendingSync($calendar);

        if ([] === $pending) {
            return 0;
        }

        $pushed = 0;

        foreach ($pending as $event) {
            try {
                if (true === $this->pushOne($calendar, $driver, $event)) {
                    ++$pushed;
                }
            } catch (CalendarSyncPermanentException $e) {
                // Only the permanent classification is swallowed. A throttle or
                // a dead sync token says the connection is unusable, not this
                // row, and carrying on through the remaining nineteen events
                // would spend the rest of a quota that is already exhausted.
                $this->abandon($event, $e->getMessage());
            }
        }

        return $pushed;
    }

    private function pushOne(Calendar $calendar, CalendarSyncDriverInterface $driver, CalendarEvent $event): bool
    {
        if (SyncState::PendingDelete === $event->syncState) {
            return $this->pushDelete($calendar, $driver, $event);
        }

        $result = $driver->push($calendar, $event);

        // Both stored even on an update: a provider that re-keys an event when
        // it is edited would otherwise leave the local row pointing at an id
        // that no longer resolves, and the next pull would treat the same
        // meeting as a stranger and write a second copy.
        $event->remoteId   = $result->remoteId;
        $event->remoteEtag = $result->etag;
        $event->syncState  = SyncState::Clean;
        $event->syncedAt   = new DateTimeImmutable();

        return true;
    }

    /**
     * A local delete, told to the remote and only then made final.
     *
     * The row survives until here precisely so this can happen — see
     * SyncState::PendingDelete. An event with no remote id never reached the
     * remote at all, so there is nothing to tell and the row simply goes.
     */
    private function pushDelete(Calendar $calendar, CalendarSyncDriverInterface $driver, CalendarEvent $event): bool
    {
        if (null !== $event->remoteId) {
            $driver->delete($calendar, $event);
        }

        $this->em->remove($event);

        return true;
    }

    /**
     * Records that one event will never be accepted, and lets the rest go.
     *
     * Stays here rather than in the caller so the decision is next to the loop
     * it protects. The row is left Clean rather than pending: an event the
     * remote has refused permanently is an event that would be re-offered on
     * every sweep forever, and a queue that retries something known to be
     * impossible is a queue that eventually retries nothing else.
     *
     * The whole JSCalendar object goes into the log line, for the same reason
     * CalendarPuller logs a discarded edit in full — this is the moment the
     * local change stops being a change that will ever leave, and the row will
     * be overwritten by the remote's version the next time its etag moves. A
     * line saying an edit was abandoned without saying which edit answers no
     * question anybody will actually ask.
     */
    private function abandon(CalendarEvent $event, string $reason): void
    {
        $this->logger->error('CalendarSync: the remote refused an event permanently, giving up on it', [
            'calendarId' => $event->calendar?->id,
            'eventId'    => $event->id,
            'uid'        => $event->uid,
            'syncState'  => $event->syncState->value,
            'reason'     => $reason,
            'discarded'  => $event->jscalendar,
        ]);

        $event->syncState = SyncState::Clean;
    }
}
