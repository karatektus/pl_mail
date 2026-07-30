<?php

declare(strict_types=1);

namespace App\Jmap\Protocol;

use App\Entity\User\User;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;

/**
 * Builds the StateChange object (RFC 8620 §7.1) that both EventSource and
 * PushSubscription deliver:
 *
 *   {"@type":"StateChange","changed":{"<accountId>":{"Email":"9","Mailbox":"3"}}}
 *
 * Deliberately tiny: JMAP never pushes mail content, only the news that a
 * state token moved. The client then calls Email/changes to find out what.
 * Every token here comes from StateManager::stateFor(), so a push and a
 * subsequent /changes call can never disagree.
 */
final class StateChangeBuilder
{
    /**
     * Types worth waking a client for. Identity is omitted: it changes only
     * when the user edits their own addresses, which the client just did.
     */
    private const array TYPES = [
        JmapObjectType::Mailbox,
        JmapObjectType::Email,
        JmapObjectType::Thread,
        JmapObjectType::EmailSubmission,
    ];

    public function __construct(
        private readonly StateManager $stateManager,
    ) {
    }

    /**
     * Current state token for every (account, type) the user can see.
     *
     * @return array<string,array<string,string>> accountId => type => state
     */
    public function snapshot(User $user): array
    {
        $snapshot = [];

        foreach ($user->getAccounts() as $account) {
            $accountId = (string) $account->getId();

            foreach (self::TYPES as $type) {
                $snapshot[$accountId][$type->value] = $this->stateManager->stateFor(
                    (int) $account->getId(),
                    $type,
                );
            }
        }

        return $snapshot;
    }

    /**
     * The subset of $current that differs from $previous, in the same shape.
     *
     * Only changed types are reported: sending the full snapshot every time
     * would make every client refetch everything on any change.
     *
     * @param array<string,array<string,string>> $previous
     * @param array<string,array<string,string>> $current
     *
     * @return array<string,array<string,string>>
     */
    public function diff(array $previous, array $current): array
    {
        $changed = [];

        foreach ($current as $accountId => $types) {
            foreach ($types as $type => $state) {
                if (($previous[$accountId][$type] ?? null) === $state) {
                    continue;
                }

                $changed[$accountId][$type] = $state;
            }
        }

        return $changed;
    }

    /**
     * @param array<string,array<string,string>> $changed
     *
     * @return array<string,mixed>
     */
    public function format(array $changed): array
    {
        return [
            '@type' => 'StateChange',
            // An empty map must serialise as {} rather than [].
            'changed' => 0 === count($changed) ? new \stdClass() : $changed,
        ];
    }
}
