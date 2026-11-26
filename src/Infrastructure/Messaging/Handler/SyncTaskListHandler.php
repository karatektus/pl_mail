<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Exception\CalendarSyncPermanentException;
use App\Infrastructure\Messaging\Message\SyncTaskListMessage;
use App\Repository\Task\TaskListRepository;
use App\Service\Task\Sync\TaskSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs one list's sync.
 *
 * A permanent failure is recorded and swallowed, as SyncCalendarHandler's is and
 * for its reasons: rethrown, Messenger logs it at CRITICAL on every attempt and
 * files a dead job nobody can usefully put back. A transient one escapes, because
 * there the retry ladder is the whole point.
 */
#[AsMessageHandler]
final readonly class SyncTaskListHandler
{
    public function __construct(
        private TaskListRepository $lists,
        private TaskSyncService    $sync,
        private LoggerInterface    $logger,
    ) {}

    public function __invoke(SyncTaskListMessage $message): void
    {
        $list = $this->lists->find($message->taskListId);

        if (null === $list || false === $list->isSynced()) {
            return;
        }

        try {
            $this->sync->sync($list);
        } catch (CalendarSyncPermanentException $e) {
            if (true === $list->syncFailureWasNews) {
                $this->logger->notice('TaskSync: stopped until this is fixed', [
                    'listId'   => $list->id,
                    'error'    => $e->getMessage(),
                    'failures' => $list->syncFailureCount,
                ]);
            }
        }
    }
}
