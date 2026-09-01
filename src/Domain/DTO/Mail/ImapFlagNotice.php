<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

/**
 * What an idling server told us when a message's flags changed elsewhere.
 *
 * `* 12 FETCH (UID 5001 FLAGS (\Seen \Flagged))` is the whole event: which
 * message, and its COMPLETE new flag set — not a delta, which is what makes it
 * safe to apply on its own. RFC 3501 §7.4.2 requires the server to send the
 * full list.
 *
 * WHY THIS EXISTS AT ALL, WHEN THE LISTING IS THE AUTHORITY
 * ────────────────────────────────────────────────────────
 * Because the listing costs 1832 messages and this costs none. The IDLE loop
 * used to answer every one of these lines by dispatching a full mailbox sync —
 * a fresh IMAP connection and `UID FETCH 1:* (FLAGS)` over the entire folder —
 * to learn something the line it had just read already said. On an inbox where
 * a phone marks a batch read, that is one full listing per message.
 *
 * THE UID IS THE WHOLE PROBLEM, AND IT IS OPTIONAL
 * ───────────────────────────────────────────────
 * `12` is a SEQUENCE NUMBER — a position in the mailbox, not an identity.
 * plMail keys everything on UID, and sequence numbers shift under every
 * expunge, so a mapping is wrong the moment it is stale and wrong in the
 * quietest possible way: flags applied to the wrong message.
 *
 * Servers MAY include `UID` in an unsolicited FETCH, and IMAP4rev2 (RFC 9051
 * §7.5.2) requires it — Dovecot and Gmail both send it. So this carries both,
 * and {@see isResolvable()} is what the caller asks. With a UID the change is
 * applied to that one message and nothing is listed; without one, there is no
 * honest way to know which message this was, and the caller falls back to the
 * listing that does know.
 *
 * A parsed value rather than a bool, so the decision "can we act on this
 * directly" is made once, on data, instead of by every reader re-reading the
 * line.
 */
final readonly class ImapFlagNotice
{
    /**
     * @param list<string> $flags the server's own spelling, complete; empty is
     *                            a real answer and means every flag was cleared
     */
    public function __construct(
        public int   $sequence,
        public ?int  $uid,
        public array $flags,
    ) {
    }

    /**
     * Whether this names a message we can find.
     *
     * The only question worth asking about one of these. False is not an error
     * — it is a server that spelled its notification the older, legal way, and
     * the answer to it is the folder listing rather than a guess.
     */
    public function isResolvable(): bool
    {
        return null !== $this->uid;
    }

    public function hasFlag(string $flag): bool
    {
        foreach ($this->flags as $candidate) {
            if (0 === strcasecmp(ltrim($candidate, '\\'), ltrim($flag, '\\'))) {
                return true;
            }
        }

        return false;
    }
}
