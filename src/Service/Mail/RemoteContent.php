<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * The result of running a message body through {@see RemoteContentBlocker}.
 *
 * `$blocked` is what the "Show images" bar keys off — not `$html`, which is
 * always safe to render either way. A body with no remote references at all
 * gets no bar, because there is nothing to offer.
 */
final readonly class RemoteContent
{
    public function __construct(
        public string $html,
        /** How many remote references were neutralised on this render. */
        public int    $blocked,
        /** How many were rewritten to go through the image proxy instead. */
        public int    $allowed,
    ) {
    }
}
