<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Mail\Message;

/**
 * What a provider says one stored message's flags are right now.
 *
 * The point of the type is that it is the *server's* statement and not a diff:
 * it says what is true there, and ThreadStatusUpdater::applyRemoteFlags()
 * decides what, if anything, that means for the row. Keeping those apart is
 * what lets the reconciler be tested against a stated server rather than a real
 * one, the same split RemoteDeletionSyncTest works through.
 *
 * $flags is the raw list as the provider spelled it, kept so the row's flags
 * mirror stays a faithful copy of the server's — including flags plMail has no
 * column for. $seen and $flagged are the two that map onto columns, normalised
 * out of that list by the caller because the spelling varies: IMAP writes
 * `\Seen`, some servers answer `Seen`, and Gmail and Graph do not speak IMAP
 * flags at all.
 */
final readonly class RemoteFlagState
{
    /**
     * @param list<string> $flags  the provider's own spelling, verbatim
     */
    public function __construct(
        public Message $message,
        public bool    $seen,
        public bool    $flagged,
        public array   $flags = [],
    ) {
    }

    /**
     * The flag list as plMail stores it: the server's own words when it gave
     * any, and otherwise the two facts we did establish, spelled the way
     * MessageFlag spells them.
     *
     * The fallback matters for Gmail and Graph, which report read state as a
     * label and a boolean respectively and never send a flag list at all. Their
     * rows still carry a flags mirror — GmailMessageBuilder and
     * GraphMessageBuilder both synthesise one at ingest — so a refresh that
     * left it alone would leave the mirror saying what was true months ago.
     *
     * @return list<string>
     */
    public function storedFlags(): array
    {
        if ([] !== $this->flags) {
            return MessageFlag::canonicalList($this->flags);
        }

        $flags = [];

        if (true === $this->seen) {
            $flags[] = MessageFlag::SEEN->value;
        }

        if (true === $this->flagged) {
            $flags[] = MessageFlag::FLAGGED->value;
        }

        return MessageFlag::canonicalList($flags);
    }
}
