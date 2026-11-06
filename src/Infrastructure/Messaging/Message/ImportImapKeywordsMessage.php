<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Read the keywords an account's stored mail already carries as labels, once,
 * after its Labels as keywords switch went on (#487). One page of message
 * ids per job; the handler queues the next.
 *
 * A job and not part of the request: an account with years of mail is tens of
 * thousands of rows, each possibly a label to find or make and a thread to
 * re-derive. See KeywordLabelFollower::importExisting().
 */
final readonly class ImportImapKeywordsMessage
{
    public function __construct(
        public int $accountId,
        /** The last message id the previous page reached; 0 to start. */
        public int $afterId = 0,
    ) {
    }
}
