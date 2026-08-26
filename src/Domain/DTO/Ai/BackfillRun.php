<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

use App\Domain\Enum\Ai\BackfillPauseReason;
use App\Domain\Enum\Ai\BackfillStatus;
use DateTimeImmutable;

/**
 * The one row of ai_backfill_state, as PHP sees it.
 *
 * WHAT IS IN HERE IS INTENT, NOT PROGRESS
 * ───────────────────────────────────────
 * No count of embedded messages, deliberately. That number is a query against
 * message_embedding — the vectors ARE the progress record — so a worker killed
 * mid-chunk resumes where the vectors stop rather than where a counter last
 * managed to be written. A stored count would be a second version of the same
 * fact, and the two would disagree exactly when somebody was looking.
 *
 * What is stored is the part the vectors cannot answer: whether a walk is meant
 * to be going, where each mailbox's cursor got to, and what stopped it.
 *
 * A DTO rather than an entity, following AiCallMetricRepository and
 * AiCallRecorder. BackfillEmbeddingsHandler calls EntityManager::clear() after
 * every chunk, and a managed entity read before that clear is detached
 * afterwards — an update against it would be silently dropped, every chunk,
 * for as long as a backfill ran.
 */
final readonly class BackfillRun
{
    /**
     * Keyed by user id — as int|string, and that union is not sloppiness.
     * The map arrives from json_decode(), and PHP turns a numeric object key
     * into an INTEGER array key on the way in, so the same value is written as
     * a string and read back as a number. Typed as `array<string, …>` PHPStan
     * is right to say every lookup misses.
     *
     * @param array<int|string, array{cursor: int|null, done: bool}> $mailboxes
     */
    public function __construct(
        public BackfillStatus       $status = BackfillStatus::Idle,
        public ?BackfillPauseReason $pauseReason = null,
        public ?string              $model = null,
        public array                $mailboxes = [],
        public int                  $failures = 0,
        public int                  $emptyBatches = 0,
        public ?string              $lastError = null,
        public ?DateTimeImmutable   $startedAt = null,
        public ?DateTimeImmutable   $lastProgressAt = null,
        public ?DateTimeImmutable   $finishedAt = null,
        public ?DateTimeImmutable   $interactiveSeenAt = null,
    ) {
    }

    /**
     * Where this mailbox's walk had got to, or null for "from the beginning".
     *
     * Null and "id 0" have to stay distinguishable: the walk is `m.id > cursor`
     * and message ids start at 1, so a null cursor is the only way to say "the
     * whole mailbox" without special-casing the first chunk.
     */
    public function cursorFor(int $userId): ?int
    {
        $entry = $this->mailboxes[$userId] ?? null;

        return null === $entry ? null : $entry['cursor'];
    }

    public function isDone(int $userId): bool
    {
        return true === ($this->mailboxes[$userId]['done'] ?? false);
    }

    /** The mailboxes a resume would have to dispatch for. @return list<int> */
    public function unfinishedUserIds(): array
    {
        $ids = [];

        foreach ($this->mailboxes as $userId => $entry) {
            if (false === ($entry['done'] ?? false)) {
                $ids[] = (int) $userId;
            }
        }

        return $ids;
    }

    /** Has every mailbox in this run been walked to the end? */
    public function everyMailboxFinished(): bool
    {
        return [] !== $this->mailboxes && [] === $this->unfinishedUserIds();
    }

    /** Is a chain of chunks still in the queue? */
    public function isLive(): bool
    {
        return $this->status->isLive($this->pauseReason);
    }
}
