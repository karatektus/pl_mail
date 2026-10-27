<?php

declare(strict_types=1);

namespace App\Jmap\Protocol;

use App\Entity\User\User;
use App\Jmap\Account\CalendarAccountResolver;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Service\Calendar\Change\CalendarChangeReader;
use App\Service\Contacts\Change\ContactChangeReader;

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
        // Rare — a tab made, renamed, switched or moved in Settings — and
        // exactly what a phone cannot find out any other way.
        JmapObjectType::InboxTab,
    ];

    public function __construct(
        private readonly StateManager $stateManager,
        private readonly CalendarChangeReader $calendarChanges,
        private readonly CalendarAccountResolver $calendarAccounts,
        private readonly ContactChangeReader $contactChanges,
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

        foreach ($user->accounts as $account) {
            $accountId = (string) $account->id;

            foreach (self::TYPES as $type) {
                $snapshot[$accountId][$type->value] = $this->stateManager->stateFor(
                    (int) $account->id,
                    $type,
                );
            }
        }

        // Calendars are the user's, published under one account
        // (CalendarAccountResolver), and their states are the calendar change
        // log's rather than StateManager's — the same tokens Calendar/get and
        // CalendarEvent/get answer with. Left out, a change to an event was
        // news to nobody: every client found it only at its next sweep.
        $calendarAccount = $this->calendarAccounts->accountFor($user);

        if (null !== $calendarAccount) {
            $snapshot[(string) $calendarAccount->id]['CalendarEvent'] = $this->calendarChanges->stateForUser((int) $user->id);
            $snapshot[(string) $calendarAccount->id]['Calendar'] = $this->calendarChanges->stateForUserCollections((int) $user->id);
            // Contacts, from the same account (ContactAccountResolver) and the
            // contact change log, for the same reason.
            $snapshot[(string) $calendarAccount->id]['ContactCard'] = $this->contactChanges->cardState((int) $user->id);
            $snapshot[(string) $calendarAccount->id]['AddressBook'] = $this->contactChanges->bookState((int) $user->id);
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
