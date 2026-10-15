<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\User\User;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;

/**
 * Tells JMAP that the reader's inbox tabs changed.
 *
 * The tabs are the user's and JMAP state is the account's, so a change is
 * recorded once for each of their accounts — the way MailChangeRecorder records
 * a label in every account it is bound to. A phone watching any of them is
 * pushed an InboxTab state and asks InboxTab/changes what moved.
 *
 * Nothing here flushes: the change-log rows ride on the caller's flush, which
 * is the write they describe.
 */
final readonly class InboxTabChanges
{
    public function __construct(
        private StateManager $stateManager,
    ) {
    }

    /** @param list<string> $keys */
    public function created(User $user, array $keys): void
    {
        $this->each($user, $keys, fn (int $account, string $key) => $this->stateManager->recordCreated($account, JmapObjectType::InboxTab, $key));
    }

    /** @param list<string> $keys */
    public function updated(User $user, array $keys): void
    {
        $this->each($user, $keys, fn (int $account, string $key) => $this->stateManager->recordUpdated($account, JmapObjectType::InboxTab, $key));
    }

    /** @param list<string> $keys */
    public function destroyed(User $user, array $keys): void
    {
        $this->each($user, $keys, fn (int $account, string $key) => $this->stateManager->recordDestroyed($account, JmapObjectType::InboxTab, $key));
    }

    /**
     * @param list<string>                 $keys
     * @param callable(int, string): void  $record
     */
    private function each(User $user, array $keys, callable $record): void
    {
        foreach ($user->accounts as $account) {
            foreach (array_unique($keys) as $key) {
                $record((int) $account->id, $key);
            }
        }
    }
}
