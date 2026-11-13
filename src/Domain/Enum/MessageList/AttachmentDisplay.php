<?php

declare(strict_types=1);

namespace App\Domain\Enum\MessageList;

/**
 * How a list row says a conversation carries files (#608).
 *
 * Icon is the paperclip the row has always had. Chips names the files — up to
 * three under the preview, then "+N" — and opens one from the list, which is
 * what you want when the attachment is the reason the mail came. Chips cost a
 * query per list page that the other two do not, so the files are only read
 * when this is Chips; see ThreadRows.
 */
enum AttachmentDisplay: string
{
    case Hidden = 'hidden';
    case Icon   = 'icon';
    case Chips  = 'chips';

    /** How many chips a row shows before it says "+N". */
    public const int CHIP_LIMIT = 3;
}
