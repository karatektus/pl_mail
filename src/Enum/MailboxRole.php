<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Special-use role of a mailbox, mapped from the RFC 6154 SPECIAL-USE
 * attributes (e.g. "\Sent") advertised by the server, when available.
 */
enum MailboxRole: string
{
    case Inbox = 'inbox';
    case Sent = 'sent';
    case Drafts = 'drafts';
    case Trash = 'trash';
    case Junk = 'junk';
    case Archive = 'archive';
    case All = 'all';
    case Flagged = 'flagged';
}
