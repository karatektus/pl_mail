<?php

declare(strict_types=1);

namespace App\Jmap\Push;

use App\Entity\PushSubscription;
use App\Jmap\Protocol\StateChangeBuilder;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * Turns "these accounts changed" into one StateChange per subscribed device.
 *
 * Called once per request/handler, never per changed row: StateManager
 * accumulates the dirty (account, type) pairs in memory and this drains them,
 * so a Gmail batch that imports 50 messages produces one notification rather
 * than fifty.
 */
final class PushDispatcher
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly UserRepository $users,
        private readonly WebPushSender $sender,
        private readonly StateChangeBuilder $stateChangeBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int,array<string,string>> $changed accountId => type => state
     */
    public function dispatch(array $changed): void
    {
        if (0 === count($changed) || false === $this->sender->isConfigured()) {
            return;
        }

        foreach ($this->groupByUser($changed) as $userId => $userChanges) {
            $deliverable = $this->subscriptions->findDeliverableForUser($userId);

            foreach ($deliverable as $subscription) {
                $filtered = $this->filterToWantedTypes($subscription, $userChanges);

                if (0 === count($filtered)) {
                    continue;
                }

                $this->sender->send($subscription, $this->stateChangeBuilder->format($filtered));
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
