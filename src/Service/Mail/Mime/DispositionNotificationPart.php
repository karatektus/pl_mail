<?php

declare(strict_types=1);

namespace App\Service\Mail\Mime;

use Symfony\Component\Mime\Part\TextPart;

/**
 * The `message/disposition-notification` half of an MDN.
 *
 * Symfony Mime has no part for this and cannot be talked into one: TextPart
 * hardcodes `text` as its media type and DataPart wants to be an attachment.
 * The machine-readable half of an MDN is neither — it is
 * `message/disposition-notification`, a media type with no charset parameter
 * and no Content-Disposition, whose body is a header-shaped field block.
 *
 * So: a TextPart with the media type overridden, the charset suppressed (null
 * through the constructor, which is what stops getPreparedHeaders() adding
 * `charset=utf-8` — the type has no charset parameter and software that parses
 * MDNs strictly can reject one), and 8bit encoding forced. The fields are pure
 * US-ASCII by construction — addresses and a fixed vocabulary — so nothing is
 * transformed; the alternative was quoted-printable, which is legal but writes
 * `=` escapes into a block the other end parses as headers.
 */
final class DispositionNotificationPart extends TextPart
{
    public function __construct(string $fields)
    {
        parent::__construct($fields, null, 'disposition-notification', '8bit');
    }

    public function getMediaType(): string
    {
        return 'message';
    }
}
