<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\User\User;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;

/**
 * Tells the JMAP change log that a reader's unified inboxes changed (#265), so
 * a phone that is pushed the new state asks UnifiedInbox/changes and fetches
 * them again.
 *
 * The inboxes are the user's, the change log is per account: recorded once on
 * each of the user's accounts, as InboxTabChanges does for the tabs.
 */
final readonly class UnifiedInboxChanges
{
    public function __construct(
        private StateManager $stateManager,
    ) {
    }

    /** @param list<int> $ids */
    public function created(User $user, array $ids): void
    {
        $this->each($user, $ids, fn (int $account, string $id) => $this->stateManager->recordCreated($account, JmapObjectType::UnifiedInbox, $id));
    }

    /** @param list<int> $ids */
    public function updated(User $user, array $ids): void
    {
        $this->each($user, $ids, fn (int $account, string $id) => $this->stateManager->recordUpdated($account, JmapObjectType::UnifiedInbox, $id));
    }

    /** @param list<int> $ids */
    public function destroyed(User $user, array $ids): void
    {
        $this->each($user, $ids, fn (int $account, string $id) => $this->stateManager->recordDestroyed($account, JmapObjectType::UnifiedInbox, $id));
    }

    /** @param list<int> $ids */
    private function each(User $user, array $ids, callable $record): void
    {
        foreach ($user->accounts as $account) {
            foreach (array_unique($ids) as $id) {
                $record((int) $account->id, (string) $id);
            }
        }
    }
}
