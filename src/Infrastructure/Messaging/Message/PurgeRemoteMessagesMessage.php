<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Destroy these messages at the provider, after plMail has already forgotten
 * them.
 *
 * ## Why this carries coordinates instead of ids
 *
 * Every other propagation message names local Message rows and lets the
 * handler look them up — ApplyImapFlagsHandler opens with
 * `findBy(['id' => …])`, ApplyGmailLabelsHandler collects gmailIds the same
 * way. That works because those operations leave the row in place.
 *
 * A purge does not. The row is gone before the worker runs, so a handler that
 * looked it up would find nothing, log "no messages found" and return —
 * leaving the mail on the server forever, to reappear at the next sync as a
 * message the user had explicitly destroyed. That is the failure this shape
 * exists to prevent, and it is invisible in testing against a fake provider.
 *
 * So the address of each message travels with the job: the IMAP folder and UID,
 * the Gmail id, the Graph id. Once dispatched, this envelope is self-contained
 * and does not care what the database says.
 *
 * ## Why one message for three providers
 *
 * An account is one provider — the three lists are never all populated — but
 * the purge that produced them is one user action over a selection that may
 * span accounts. Keeping it as one message type keeps the ordering property
 * that matters: whatever plMail queued for these messages earlier is already
 * on the queue ahead of this, so a relabel cannot arrive after the delete.
 */
final class PurgeRemoteMessagesMessage
{
    /**
     * @param array<string, list<int>> $imapUids folder path => UIDs in it
     * @param list<string>             $gmailIds
     * @param list<string>             $graphIds
     */
    public function __construct(
        public int   $accountId,
        public array $imapUids = [],
        public array $gmailIds = [],
        public array $graphIds = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->imapUids && [] === $this->gmailIds && [] === $this->graphIds;
    }
}
