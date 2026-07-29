<?php

namespace App\Domain\Enum\Mail;

enum MessageFlag: string
{
    case ANSWERED = '\\Answered';
    case FLAGGED = '\\Flagged';
    case DELETED = '\\Deleted';
    case SEEN = '\\Seen';
    case DRAFT = '\\Draft';
    case RECENT = '\\Recent';
}
