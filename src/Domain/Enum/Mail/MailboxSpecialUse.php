<?php

namespace App\Domain\Enum\Mail;

enum MailboxSpecialUse: string
{
    case INBOX = '\\Inbox';
    case SENT = '\\Sent';
    case TRASH = '\\Trash';
    case DRAFTS = '\\Drafts';
    case JUNK = '\\Junk';
    case ARCHIVE = '\\Archive';

    /**
     * Where a folder stands in the queue when an account's history is being
     * brought in: lower is sooner.
     *
     * The order somebody would ask for if asked. The Inbox is what they open;
     * Sent and Drafts are what they wrote; their own folders (no special use,
     * hence the nullable argument) are where they filed things on purpose.
     * The archive comes after those because on Gmail over IMAP "All Mail" is
     * every message a second time, and by then most of it is already here.
     * Spam and the bin are last: nobody is waiting for either.
     *
     * Static and taking null, rather than a method on a case, because the
     * commonest folder has no case at all.
     */
    public static function importRank(?self $use): int
    {
        return match ($use) {
            self::INBOX   => 0,
            self::SENT    => 10,
            self::DRAFTS  => 20,
            null          => 30,
            self::ARCHIVE => 40,
            self::JUNK    => 80,
            self::TRASH   => 90,
        };
    }
}
