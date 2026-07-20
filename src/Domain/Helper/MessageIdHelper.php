<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Canonical form for RFC 5322 Message-IDs: trimmed, angle brackets stripped.
 *
 * The two sync paths disagree at the source — webklex strips the brackets,
 * the raw Gmail API header keeps them — so every write and every comparison
 * (Gmailify dedup, IMAP claim, threading) must go through this or
 * "<x@host>" and "x@host" silently count as different messages.
 */
final class MessageIdHelper
{
    public static function normalise(string $raw): string
    {
        return trim(trim($raw), '<> ');
    }
}
