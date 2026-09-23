<?php

declare(strict_types=1);

namespace App\Jmap\Mapper;

use App\Repository\Mail\MessageRepository;

/**
 * Loads every Mailbox count for one account in a single grouped query per
 * grain, so a Mailbox/get over a large label tree is two reads rather than a
 * textbook N+1.
 *
 * The aggregates themselves — and why they are raw SQL, and why "unread" means
 * seen_at rather than the \Seen flag — live on MessageRepository.
 */
final class MailboxCountsProvider
{
    public function __construct(
        private readonly MessageRepository $messages,
    ) {
    }

    /**
     * Only what the call will print. Both grains are a full aggregate over
     * the account's message_label rows, and a client syncing mailbox names or
     * roles — which is most Mailbox/get calls — asked for neither; a
     * Mailbox/get naming ids only ever shows those labels.
     *
     * @param list<string>|null $properties as the call received them, null = all
     * @param list<int>|null    $labelIds   null = every label of the account
     */
    public function forAccount(int $accountId, ?array $properties = null, ?array $labelIds = null): MailboxCounts
    {
        $wants = static fn (string ...$names): bool => null === $properties
            || [] !== array_intersect($names, $properties);

        return new MailboxCounts(
            true === $wants('totalEmails', 'unreadEmails')
                ? $this->messages->countEmailsPerLabelForAccount($accountId, $labelIds)
                : [],
            true === $wants('totalThreads', 'unreadThreads')
                ? $this->messages->countThreadsPerLabelForAccount($accountId, $labelIds)
                : [],
        );
    }
}
