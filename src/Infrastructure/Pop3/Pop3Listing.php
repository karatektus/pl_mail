<?php

declare(strict_types=1);

namespace App\Infrastructure\Pop3;

/**
 * One line of a POP3 LIST: the message's number in this session, and the
 * number of octets the server says RETR will return.
 *
 * Both only mean anything for the session they came from. The number restarts
 * at 1 after any deletion and the size is the size on the wire, before
 * transfer decoding — see Pop3Connection::retrieve() and the note there about
 * what "octets" counts.
 *
 * Kept as an object rather than a tuple because the pair travels together
 * through the syncer and a positional array of two ints is the kind of thing
 * that gets swapped at a call site without anything complaining.
 */
final readonly class Pop3Listing
{
    public function __construct(
        public int $number,
        public int $octets,
    ) {
    }
}
