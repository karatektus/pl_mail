<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Best-effort outgoing state sync to Microsoft Graph.
 *
 * Carries no add/remove delta. Graph replaces the whole `categories` array on
 * PATCH rather than diffing it, so the only correct payload is the message's
 * full current label set — which means the handler derives categories, and by
 * default read state and flag state, from the DB rather than being told. That
 * keeps the push idempotent and keeps the DB the source of truth.
 *
 * $moveToLabel is an exception: a folder move is expensive and Graph gives us
 * no cheap way to know where the message currently lives, so callers pass it
 * only when a move actually happened.
 *
 * $isRead and $flagged are the other, and they exist because the DB is not
 * always up to date when a worker reads it. The caller queues this job and then
 * flushes, so a worker can load the row first and push the OLD read state for a
 * message the user has just read — Microsoft is written at that moment and
 * still shows the message unread. A caller that changes one of them says what
 * it changed to; null means "not what this job is about", and the handler
 * derives it from the row as before. IMAP and Gmail jobs carry their action the
 * same way.
 *
 * An override is the user's intent at one moment, so it is NOT replayed when a
 * throttled push is requeued: thirty seconds on the row is flushed and is the
 * newer truth. A Messenger redelivery after a transient failure does replay it,
 * because the envelope is resent as it was; that only matters if the same
 * message was toggled again inside the retry delay.
 *
 * @see \App\Infrastructure\Messaging\Handler\ApplyGraphChangesHandler
 */
readonly class ApplyGraphChangesMessage
{
    /**
     * @param list<int> $messageIds   local Message ids
     * @param int|null  $moveToLabel  local Label id to move into; must be folder-backed
     * @param bool|null $isRead       the read state the user chose for all of $messageIds, or null to derive it from each row
     * @param bool|null $flagged      the star the user chose for all of $messageIds, or null to derive it from each row
     */
    public function __construct(
        public int   $accountId,
        public array $messageIds,
        public ?int  $moveToLabel = null,
        public ?bool $isRead = null,
        public ?bool $flagged = null,
    ) {}
}
