<?php

declare(strict_types=1);

namespace App\Jmap\Mapper;

/**
 * The four count properties RFC 8621 §2 requires on a Mailbox, resolved for a
 * whole account in two queries rather than four per label.
 *
 * Email counts come from the message<->label join (message_label), which is the
 * authoritative per-message label assignment. Thread counts come from
 * thread_label, which ThreadLabelSynchronizer keeps equal to the union of a
 * thread's messages' labels — so it is exactly the "thread appears under this
 * mailbox" set, already materialised.
 */
final class MailboxCounts
{
    /**
     * @param array<int,array{total:int,unread:int}> $emails  keyed by label id
     * @param array<int,array{total:int,unread:int}> $threads keyed by label id
     */
    public function __construct(
        private readonly array $emails = [],
        private readonly array $threads = [],
    ) {
    }

    /**
     * @return array{totalEmails:int,unreadEmails:int,totalThreads:int,unreadThreads:int}
     */
    public function forLabel(?int $labelId): array
    {
        $emails = $this->emails[$labelId] ?? ['total' => 0, 'unread' => 0];
        $threads = $this->threads[$labelId] ?? ['total' => 0, 'unread' => 0];

        return [
            'totalEmails' => $emails['total'],
            'unreadEmails' => $emails['unread'],
            'totalThreads' => $threads['total'],
            'unreadThreads' => $threads['unread'],
        ];
    }
}
