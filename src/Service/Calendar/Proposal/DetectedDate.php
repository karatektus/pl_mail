<?php

declare(strict_types=1);

namespace App\Service\Calendar\Proposal;

use DateTimeImmutable;

/**
 * One date a detector read out of prose, with the words it read it from.
 *
 * Not an EventProposal and not an ExtractedEvent: a detector reports what it
 * found and decides nothing about whether it may be offered. Whether the date
 * is in the past, whether the mail is a newsletter, whether the user has
 * already thrown this claim away — all of that is EventProposer's, in one
 * place, so that a second detector cannot arrive later with its own idea of
 * what counts as noise.
 *
 * $sentence is required rather than optional. A detector that cannot say which
 * words it read has produced a bare date with an Add button next to it, which
 * is the thing the card exists not to be.
 */
final readonly class DetectedDate
{
    public function __construct(
        /** UTC. */
        public DateTimeImmutable $startsAt,
        /** UTC, exclusive. The default hour when the mail stated no length. */
        public DateTimeImmutable $endsAt,
        /** Verbatim, clamped — the words the card quotes. */
        public string            $sentence,
        /** 0-100, and never 100: this is a guess by construction. */
        public int               $confidence,
    ) {
    }
}
