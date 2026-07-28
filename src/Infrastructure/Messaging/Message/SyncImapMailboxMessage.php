<?php

namespace App\Infrastructure\Messaging\Message;

readonly class SyncImapMailboxMessage
{
    public function __construct(
        public int $mailboxId,
    ) {}
}
