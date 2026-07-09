<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched after a status change (star, read, archive, delete, move) so that
 * the change is mirrored on the IMAP server asynchronously.
 *
 * action values:
 *   'flag'    — set \Flagged   (star)
 *   'unflag'  — clear \Flagged (unstar)
 *   'seen'    — set \Seen      (mark read)
 *   'unseen'  — clear \Seen    (mark unread)
 *   'archive' — move message to the account's \Archive mailbox
 *   'trash'   — move message to the account's \Trash mailbox
 *   'delete'  — permanently delete (used when message is already in Trash)
 *
 * @param int[]  $messageIds  App Message entity IDs to act on
 * @param string $action      One of the values listed above
 */
readonly class ApplyImapFlagsMessage
{
    /**
     * @param int[] $messageIds
     */
    public function __construct(
        public array  $messageIds,
        public string $action,
    ) {}
}
