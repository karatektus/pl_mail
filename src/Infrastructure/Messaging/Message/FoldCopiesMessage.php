<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Fold the messages one folder keeps that are copies of one email stored
 * separately before #415 — see ImapCopyMerger.
 *
 * Queued by the folder's sweep once it has given the rows their size, and
 * handled apart from it: a fold that fails must cost that email, not the
 * sweep, or one bad group would stop the folder's sweeps for good.
 */
final readonly class FoldCopiesMessage
{
    public function __construct(
        public int $mailboxId,
    ) {
    }
}
