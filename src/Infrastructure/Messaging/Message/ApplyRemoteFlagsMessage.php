<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * One message's flags changed on the server, and the server said which.
 *
 * The cheap half of what an IDLE notification used to cost. `* 12 FETCH (UID
 * 5001 FLAGS (\Seen))` carries the message and its complete new flag set, so
 * nothing has to be asked of anybody: no IMAP connection, no folder listing,
 * no round trip at all. Compare SyncImapMailboxMessage, which is what this
 * replaces for the common case and which re-reads every UID in the folder.
 *
 * ONLY EVER DISPATCHED WITH A UID, NEVER A SEQUENCE NUMBER
 * ───────────────────────────────────────────────────────
 * A sequence number is a position, and positions move under every expunge, so
 * one carried in a queue is a stale pointer by the time it is read — and the
 * failure is silent: flags applied to whichever message happens to be sitting
 * at that position now. ImapIdleCommand refuses to dispatch this without a
 * UID and falls back to the listing instead. See ImapFlagNotice.
 *
 * THE FLAG LIST IS COMPLETE, NOT A DELTA
 * ──────────────────────────────────────
 * RFC 3501 §7.4.2 requires the server to send the whole set, which is what
 * makes this safe to apply on its own. An empty list is therefore meaningful
 * and must not be treated as "nothing to do": it means every flag was cleared,
 * which is how "marked unread on my phone" arrives.
 */
final readonly class ApplyRemoteFlagsMessage
{
    /**
     * @param list<string> $flags the server's own spelling, complete
     */
    public function __construct(
        public int   $mailboxId,
        public int   $uid,
        public array $flags,
    ) {
    }
}
