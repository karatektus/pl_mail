<?php

declare(strict_types=1);

namespace App\Domain\Enum\MessageList;

/**
 * Who a conversation row names (#608).
 *
 * Everyone is what the row has done since a thread you had answered stopped
 * looking like it came from you: everybody who wrote, oldest first. Latest is
 * the newest message's sender alone, which is the shorter answer to "who am I
 * waiting on" and the one a client with a single From column gives.
 *
 * The Sent and Drafts lists name the recipients instead whichever this is,
 * because every row there is from you.
 */
enum SenderDisplay: string
{
    case Everyone = 'everyone';
    case Latest   = 'latest';
}
