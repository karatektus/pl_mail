<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

use DateTimeImmutable;

/**
 * A summary that is already written down, and whether it still describes the
 * conversation it was written from.
 *
 * TWO FACTS AND NOT ONE BOOLEAN
 * ─────────────────────────────
 * "There is no summary" and "there is one but the thread has moved on since"
 * are different things to a reader and get different treatment: the first
 * offers a button, the second shows the old text greyed with the button beside
 * it. Collapsing them would mean a thread that gained a single "thanks" threw
 * away half a minute of somebody's waiting, which is the reason the pane shows
 * a stale summary rather than hiding it.
 *
 * A DTO rather than an array, because three values travel together from
 * ThreadSummaryStore through a controller into a template and an array would
 * be three string keys nothing checks.
 */
final readonly class StoredThreadSummary
{
    /**
     * @param string                 $text     what the model wrote, already tidied
     * @param bool                   $isFresh  whether the stored source hash still
     *                                         matches the transcript this thread
     *                                         would produce right now
     * @param DateTimeImmutable|null $writtenAt null only when the stored timestamp
     *                                          could not be parsed, which costs the
     *                                          "summarised on…" line and nothing else
     * @param bool                   $isPartial whether the model was shown less than
     *                                          the whole conversation. Not stored: it
     *                                          is read off the transcript the freshness
     *                                          check already rebuilds, so it describes
     *                                          the thread AS IT IS NOW rather than as
     *                                          it was when the row was written — which
     *                                          is the truthful answer to "did it see
     *                                          all of this", and free
     */
    public function __construct(
        public string $text,
        public bool $isFresh,
        public ?DateTimeImmutable $writtenAt,
        public bool $isPartial = false,
    ) {
    }
}
