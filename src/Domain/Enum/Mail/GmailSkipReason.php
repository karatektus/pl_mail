<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * Why a message that is in a Gmail mailbox is not in plMail.
 *
 * Recorded with the message's id (App\Entity\Mail\GmailSkippedMessage), so the
 * import stops asking for it and `app:gmail:skipped` can say what it was.
 */
enum GmailSkipReason: string
{
    /**
     * Fetched, and its headers name neither this account nor another one of
     * the user's — see SyncGmailMessageBatchHandler::resolveOwningAccount().
     * A draft, a chat, mail sent from a "send mail as" address, mail Gmail
     * collected from a mailbox that is not connected here.
     */
    case Unattributable = 'unattributable';

    /** Listed by Gmail, and answered with a permanent error when asked for. */
    case Unfetchable = 'unfetchable';

    /**
     * Another of the user's Gmail accounts holds it, under its own Gmail id,
     * through its own sync: mail forwarded from one to the other, or a copy
     * both received. Stored once, there; see
     * SyncGmailMessageBatchHandler::mayTakeGmailId().
     */
    case HeldElsewhere = 'held_elsewhere';
}
