<?php

declare(strict_types=1);

namespace App\Service\Calendar\Proposal;

use App\Entity\Mail\Message;
use App\Entity\User\User;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Everything a detector may look at, assembled once per message.
 *
 * The shape ExtractionContext has, and for the same reason: every detector sees
 * the same message, and the whole context is rebuildable from the row months
 * later, which is what makes `app:backfill proposals` a backfill rather than a
 * resync.
 *
 * Two fields carry the correctness of the entire feature.
 *
 * $anchor is the message's own date — receivedAt, or sentAt where a message
 * never arrived anywhere. Every relative form resolves against it and never
 * against now(). "Saturday" in a mail sent on a Friday is tomorrow, forever, and
 * a backfill re-reading that mail next year has to produce the same instant it
 * produced the day it arrived. Anchoring on now() is the easiest bug in this
 * feature to ship, because it is invisible until the backfill runs.
 *
 * $locale is what decides 04/08/2026, and it comes from the user's own
 * preference rather than from anything in the message. A German reader writing
 * a slashed date means the fourth of August; an American means the eighth of
 * April; and the mail itself says nothing either way. Guessing from the content
 * — "the first number is 25, so it must be a day" — is how a feature ends up
 * right nine times and silently a week out on the tenth.
 */
final readonly class ProposalContext
{
    public function __construct(
        public Message           $message,
        public User              $usr,
        /** receivedAt ?? sentAt. Never now(). */
        public DateTimeImmutable $anchor,
        /** The user's own clock: what "14 Uhr" means. */
        public DateTimeZone      $zone,
        /** Two-letter language, lowercased — 'de', 'en'. */
        public string            $language,
        /** The plain-text body, gated and clamped. */
        public string            $text,
    ) {
    }
}
