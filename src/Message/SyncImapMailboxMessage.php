<?php

namespace App\Message;

readonly class SyncImapMailboxMessage
{
    public function __construct(
        public int $mailboxId,
    ) {}
}
