<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Ask the model again about somebody's most recent mail.
 *
 * WHY A VERDICT GOES STALE, WHICH IS THE ONLY REASON THIS EXISTS
 * ──────────────────────────────────────────────────────────────
 * A message is asked about once, on arrival, and the answer is kept. That is
 * right: the mail does not change, so neither should the answer.
 *
 * What changes is the QUESTION. The prompt is editable from Admin → AI, the
 * model is a setting, and what plMail sends alongside the message has changed
 * too — verdicts stored before the bulk-header line was added were reached
 * without evidence the model now gets, and demonstrably differ because of it.
 * Every one of those is a stored answer to a question nobody asks any more.
 *
 * Nothing re-asks on its own, and nothing should: a model call per message is
 * the most expensive thing this application does, and doing it again unprompted
 * over a hundred thousand messages because somebody edited a prompt would be a
 * remarkable way to spend an afternoon of somebody else's GPU.
 *
 * BOUNDED, AND THE BOUND IS THE POINT. Recent mail is the mail somebody is
 * looking at, and it is where a wrong tab is noticed. A few hundred messages is
 * minutes of a warm model; the whole mailbox is hours, and the command that
 * does the whole mailbox already exists for anybody who wants it.
 */
final readonly class ReclassifyRecentMessage
{
    public function __construct(
        /**
         * Whose mail. Their accounts, their preference, their model calls —
         * the same scoping every other job in this feature uses.
         */
        public int $userId,
        /** How many of the newest messages to ask about again. */
        public int $limit,
    ) {
    }
}
