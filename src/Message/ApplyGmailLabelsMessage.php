<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Outgoing Gmail label mutation for messages on Gmail API accounts.
 * Applied via messages.batchModify — no folder moves involved.
 *
 * $add / $remove entries are either:
 *   - Gmail system label ids as-is ("INBOX", "TRASH", "STARRED", "UNREAD"), or
 *   - numeric local Label entity ids as strings ("42") for custom labels.
 *     The handler resolves those to their gmailLabelId, creating the label
 *     remotely first when it does not exist on Gmail yet.
 *
 * @param int[]        $messageIds  local Message entity ids
 * @param list<string> $add
 * @param list<string> $remove
 */
readonly class ApplyGmailLabelsMessage
{
    public function __construct(
        public int   $accountId,
        public array $messageIds,
        public array $add = [],
        public array $remove = [],
    ) {}
}
