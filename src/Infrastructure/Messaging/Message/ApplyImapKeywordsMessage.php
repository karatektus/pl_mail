<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Write label keywords to the server copies of some messages (#221 step 2).
 *
 * Copies, not messages: a keyword lives on each copy of an email (see
 * MessageCopies), and the address of each one is taken when the job is queued,
 * like ApplyImapFlagsMessage's source UIDs, because a row can be moved before
 * the job runs.
 */
readonly class ApplyImapKeywordsMessage
{
    /**
     * @param list<array{message: int, mailbox: int, uid: int}> $copies
     * @param list<string>                                      $add    keywords, already encoded
     * @param list<string>                                      $remove
     */
    public function __construct(
        public int   $accountId,
        public array $copies,
        public array $add = [],
        public array $remove = [],
    ) {}
}
