<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * A named, explainable disagreement between what a message SAYS it is from and
 * where it is actually from. Produced only by {@see SenderIdentityChecker}.
 *
 * The `claimed` / `actual` pair exists so the warning can show its work. A
 * banner that says "this might be phishing" teaches nobody anything; one that
 * says "the name says Hetzner, the address is ownkhalsick.com" is a fact the
 * reader can check, and is wrong in a way they can see when it is wrong.
 */
final readonly class SenderMismatch
{
    public function __construct(
        public SenderMismatchKind $kind,
        /** The domain or brand token lifted out of the display name. */
        public string $claimed,
        /** The registrable domain of the actual From address. */
        public string $actual,
    ) {
    }
}
