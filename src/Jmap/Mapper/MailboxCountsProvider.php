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

    public function forAccount(int $accountId): MailboxCounts
    {
        return new MailboxCounts(
            $this->messages->countEmailsPerLabelForAccount($accountId),
            $this->messages->countThreadsPerLabelForAccount($accountId),
        );
    }
}
