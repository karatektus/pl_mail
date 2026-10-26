<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Take the Gmail identity off the other accounts' IMAP copies one Gmail
 * mailbox carries, after its Gmailify switch was turned off (#205).
 *
 * A job and not part of the request: a mailbox that collected another
 * address's mail for years carries tens of thousands of copies, each with
 * labels to take off, a thread to re-derive and a JMAP change to record.
 * Inside the settings request that is minutes, under the web container's
 * time limit. See GmailifyLinks::severCarriedBy().
 */
final readonly class SeverGmailifyLinksMessage
{
    public function __construct(
        /** The Gmail account whose switch was turned off: the carrier. */
        public int $accountId,
    ) {
    }
}
