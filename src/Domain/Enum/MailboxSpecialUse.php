<?php

namespace App\Domain\Enum;

enum MailboxSpecialUse: string
{
    case INBOX = '\\Inbox';
    case SENT = '\\Sent';
    case TRASH = '\\Trash';
    case DRAFTS = '\\Drafts';
    case JUNK = '\\Junk';
    case ARCHIVE = '\\Archive';
}
