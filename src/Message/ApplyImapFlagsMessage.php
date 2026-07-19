<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Outgoing IMAP state propagation for messages on plain-IMAP accounts.
 *
 * $messageIds is a messageId => sourceMailboxId map captured BEFORE any DB
 * move/flush so the source folder is always the one the UID is valid in.
 *
 * $destinationPath is only set for the 'move' action — used when a custom
 * location label is replaced and the propagator has already resolved which
 * folder the message must physically move to. 'archive' and 'trash' keep
 * resolving their destination inside the handler as before.
 */
readonly class ApplyImapFlagsMessage
{
    /**
     * @param array<int,int> $messageIds  messageId => sourceMailboxId
     */
    public function __construct(
        public array   $messageIds,
        public string  $action,
        public ?string $destinationPath = null,
    ) {}
}
