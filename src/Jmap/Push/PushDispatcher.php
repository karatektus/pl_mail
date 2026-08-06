<?php

declare(strict_types=1);

namespace App\Jmap\Push;

use App\Entity\User\PushSubscription;
use App\Jmap\Protocol\StateChangeBuilder;
use App\Repository\User\PushSubscriptionRepository;
use App\Repository\User\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * Turns "these accounts changed" into one StateChange per subscribed device.
 *
 * Called once per request/handler, never per changed row: StateManager
 * accumulates the dirty (account, type) pairs in memory and this drains them,
 * so a Gmail batch that imports 50 messages produces one notification rather
 * than fifty.
 *
 * **The transport is per subscription, not per install.** A user with a phone
 * on FCM and a desktop browser on Web Push has both told, from the same drained
 * set, in the same pass — the registry answers with the sender each row asks
 * for. An unconfigured transport skips its own rows and leaves the others
 * alone, which is what makes turning Firebase off a decision about Android
 * rather than about push.
 */
final class PushDispatcher
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly UserRepository $users,
        private readonly PushSenderRegistry $senders,
        private readonly StateChangeBuilder $stateChangeBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int,array<string,string>> $changed accountId => type => state
     */
    public function dispatch(array $changed): void
    {
        if (0 === count($changed) || false === $this->senders->anyConfigured()) {
            return;
        }

        foreach ($this->groupByUser($changed) as $userId => $userChanges) {
            $deliverable = $this->subscriptions->findDeliverableForUser($userId);

            foreach ($deliverable as $subscription) {
                $sender = $this->senders->for($subscription);

                // Not logged per subscription: an install with Firebase
                // switched off has one of these per Android device per state
                // change, and a message repeated several times a second buries
                // everything else. The Session already tells those clients that
                // FCM is unavailable.
                if (null === $sender || false === $sender->isConfigured()) {
                    continue;
                }

                $filtered = $this->filterToWantedTypes($subscription, $userChanges);

                if (0 === count($filtered)) {
                    continue;
                }

                $sender->send($subscription, $this->stateChangeBuilder->format($filtered));
            }
        }
    }

    /**
     * A subscription belongs to a user, but changes are recorded per account —
     * so the accounts have to be resolved back to their owners before anything
     * can be delivered.
     *
     * @param array<int,array<string,string>> $changed
     *
     * @return array<int,array<string,array<string,string>>> userId => accountId => type => state
     */
    private function groupByUser(array $changed): array
    {
        $byUser = [];
        $owners = $this->users->findOwnersOfAccounts(array_keys($changed));

        foreach ($changed as $accountId => $types) {
            $userId = $owners[$accountId] ?? null;

            if (null === $userId) {
                $this->logger->warning('Push: no owner for account, skipping', ['accountId' => $accountId]);

                continue;
            }

            $byUser[$userId][(string) $accountId] = $types;
        }

        return $byUser;
    }

    /**
     * @param array<string,array<string,string>> $changes
     *
     * @return array<string,array<string,string>>
     */
    private function filterToWantedTypes(PushSubscription $subscription, array $changes): array
    {
        $filtered = [];

        foreach ($changes as $accountId => $types) {
            foreach ($types as $type => $state) {
                if (false === $subscription->wants($type)) {
                    continue;
                }

                $filtered[$accountId][$type] = $state;
            }
        }

        return $filtered;
    }
}
