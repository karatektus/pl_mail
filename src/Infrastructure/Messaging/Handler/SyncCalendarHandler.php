<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Exception\CalendarSyncException;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Repository\Calendar\CalendarRepository;
use App\Service\Calendar\CalendarSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs one calendar's sync.
 *
 * Thin on purpose: it resolves the row, refuses the two cases that are not
 * work, and lets CalendarSyncService decide everything else. The retry policy
 * is expressed entirely through the exception the driver raised — permanent
 * ones dead-letter, throttled ones come back with their own delay, and anything
 * unclassified falls to the ingest transport's strategy. Catching here would
 * take that decision away from the class that made it.
 *
 * The one thing it does catch is the permanent case, and only to say so: the
 * envelope still fails, because Messenger's failure transport is where a
 * calendar that can never sync belongs, but a log line naming the calendar is
 * what makes it findable without reading the failed queue.
 */
#[AsMessageHandler]
final readonly class SyncCalendarHandler
{
    public function __construct(
        private CalendarRepository  $calendars,
        private CalendarSyncService $sync,
        private LoggerInterface     $logger,
    ) {
    }

    public function __invoke(SyncCalendarMessage $message): void
    {
        $calendar = $this->calendars->find($message->calendarId);

        if (null === $calendar) {
            // Deleted or unsubscribed between the dispatch and the run. Normal,
            // not an error: the sweep queues work while the user keeps clicking.
            return;
        }

        if (false === $calendar->isSynced()) {
            // Unsubscribed rather than deleted — the calendar is still there,
            // the remote binding is not. Same answer, different route to it.
            return;
        }

        try {
            $touched = $this->sync->sync($calendar);
        } catch (CalendarSyncException $e) {
            // Logged when the failure is NEWS, not on every attempt. The
            // service has just written the failure down, and
            // Calendar::recordSyncFailure() left its answer on the entity: true
            // for the first failure of a run, for a message that differs from
            // the stored one, and for the first attempt after a backoff window
            // expires — false only while this calendar is repeating something
            // it is already backing off through.
            //
            // The first occurrence is never suppressed, and neither is a
            // CHANGE. A calendar that was failing on a dead sign-in and starts
            // failing on a deleted remote says so immediately, because the
            // stored message no longer matches. That is the case a plain "log
            // once per hour" throttle would have hidden, and it is the one that
            // means something different has gone wrong.
            if (true === $calendar->syncFailureWasNews) {
                $this->logger->error('CalendarSync: sync failed', [
                    'calendarId' => $calendar->id,
                    'error'      => $e->getMessage(),
                    'exception'  => $e,
                    'failures'   => $calendar->syncFailureCount,
                    'nextTry'    => $calendar->syncBackoffUntil?->format(DATE_ATOM),
                ]);
            }

            throw $e;
        }

        if (0 === $touched) {
            return;
        }

        $this->logger->info('CalendarSync: calendar synced', [
            'calendarId' => $calendar->id,
            'events'     => $touched,
        ]);
    }
}
