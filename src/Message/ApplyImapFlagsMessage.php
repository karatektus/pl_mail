<?php

declare(strict_types=1);

namespace App\Message;

/**
 * @param int[]  $messageIds
 * @param string $action
 * @param int[]  $sourceMailboxIds  Original mailbox IDs before any DB move (required for archive/trash)
 */
readonly class ApplyImapFlagsMessage
{
    public function __construct(
        public array  $messageIds,
        public string $action,
    ) {}
}
