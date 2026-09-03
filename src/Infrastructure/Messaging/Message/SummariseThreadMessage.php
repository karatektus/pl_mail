<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Summarise one conversation from the whole of it, away from any connection.
 *
 * WHY THIS IS A JOB AND THE ORDINARY SUMMARY IS NOT
 * ─────────────────────────────────────────────────
 * The streamed endpoint is right for the ordinary case: half a minute, tokens
 * arriving as they are written, and a reader watching them. It is wrong for the
 * full-conversation run, which is silent for minutes while the model reads and
 * only then begins — and for the whole of that silence the answer depends on a
 * browser connection staying open.
 *
 * That dependency is what broke, and not in a way any timeout could fix.
 * Measured on a real installation: a full run over 67,720 characters, abandoned
 * after 40 seconds with nothing yet written, while the model host sat healthy
 * for seven days either side of it. The heartbeat had put four frames on the
 * wire, so the connection was alive and being written to and then was not.
 * Nothing in plMail closed it. A held-open connection has a proxy, a browser
 * and a network in it, and any of them may end it for reasons this application
 * does not get told.
 *
 * So the full run stops needing one. The work is dispatched, a worker does it,
 * the store gets the answer, and Mercure says so. The reader may close the tab,
 * navigate away or lose their network entirely and still find the summary
 * waiting — which is the behaviour somebody who has just been told "this takes
 * several minutes" would reasonably expect anyway.
 *
 * IDS AND NOT OBJECTS, like every other message here: an envelope outlives the
 * request that made it, and a serialised entity is a snapshot that can be wrong
 * by the time it is read.
 */
final readonly class SummariseThreadMessage
{
    public function __construct(
        public int $threadId,
        /**
         * Whose mail this is.
         *
         * Carried rather than looked up, because the handler has to answer two
         * questions with it and neither is safe to skip: whether this person is
         * still allowed the feature — an administrator may have switched it off
         * between the press and the worker picking the envelope up — and where
         * to publish the Mercure nudge. A job that ran on a permission it no
         * longer had would be the worst kind, because nobody is watching.
         */
        public int $userId,
    ) {
    }
}
