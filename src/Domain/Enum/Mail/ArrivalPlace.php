<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * Where a message was filed at the moment it was stored.
 *
 * Kept on the message (Message::$arrivedIn) rather than read off its labels
 * when somebody asks, because the labels are where the mail is NOW. Most inbox
 * mail is archived within the day, and a delay figure that sorted mail by its
 * present labels would move it out of "inbox" as it was read.
 *
 * Three values because that is how many answers the reader needs. Providers
 * announce what lands in the inbox and nothing else — see
 * GmailWatchService::LABEL — so the inbox is the mail a delay matters for.
 * Spam is singled out from the rest only because it is the usual reason for a
 * quarter-hour wait and worth being able to see at a glance.
 */
enum ArrivalPlace: string
{
    case Inbox     = 'inbox';
    case Spam      = 'spam';
    case Elsewhere = 'elsewhere';
}
