<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Best-effort outgoing state sync to Microsoft Graph.
 *
 * Deliberately carries no add/remove delta. Graph replaces the whole
 * `categories` array on PATCH rather than diffing it, so the only correct
 * payload is the message's full current label set — which means the handler
 * derives read state, flag state and categories from the DB rather than being
 * told. That keeps the push idempotent and keeps the DB unambiguously the
 * source of truth.
 *
 * $moveToLabel is the one exception: a folder move is expensive and Graph
 * gives us no cheap way to know where the message currently lives, so callers
 * pass it only when a move actually happened.
 *
 * @see \App\MessageHandler\ApplyGraphChangesHandler
 */
readonly class ApplyGraphChangesMessage
{
    /**
     * @param list<int> $messageIds   local Message ids
     * @param int|null  $moveToLabel  local Label id to move into; must be folder-backed
     */
    public function __construct(
        public int   $accountId,
        public array $messageIds,
        public ?int  $moveToLabel = null,
    ) {}
}
