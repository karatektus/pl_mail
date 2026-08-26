<?php

declare(strict_types=1);

namespace App\Repository\Ai;

use App\Domain\DTO\Ai\BackfillRun;
use App\Domain\Enum\Ai\BackfillPauseReason;
use App\Domain\Enum\Ai\BackfillStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The single row that says whether a backfill is meant to be walking.
 *
 * NOT A ServiceEntityRepository, AND THERE IS NO ENTITY
 * ────────────────────────────────────────────────────
 * The same argument AiCallRecorder makes, and it is not a stylistic one.
 * BackfillEmbeddingsHandler calls EntityManager::clear() after every chunk so a
 * walk of a hundred thousand messages does not die of memory. Anything managed
 * that was read before that clear is detached after it, and an update against a
 * detached object is silently dropped — every chunk, for hours, on the one
 * table whose whole job is to say what is happening.
 *
 * So: plain statements, each of them complete on its own, none of them holding
 * anything across a clear().
 *
 * EVERY WRITE IS ONE STATEMENT THAT RE-CHECKS WHAT IT ASSUMES
 * ──────────────────────────────────────────────────────────
 * Two processes touch this row — the web request an operator clicks in, and the
 * worker running the chunk — and a read-then-write in PHP would let one clobber
 * the other's decision. Claiming a run is therefore an UPDATE with the guard in
 * its WHERE clause and the answer in its row count, and a chunk's progress is
 * an UPDATE built out of jsonb_set rather than a whole object written back.
 *
 * NOTHING HERE THROWS
 * ───────────────────
 * A state read that fails is a panel that says "idle", never a 500 on the admin
 * page that was going to draw it, and a state write that fails must not fail
 * the chunk that had already done its work.
 */
final readonly class AiBackfillStateRepository
{
    /**
     * How long a run may go without progress before somebody may start another.
     *
     * A chain that is alive re-dispatches within seconds, and the longest
     * legitimate gap is the host-unreachable retry. Fifteen minutes is well
     * past both, so a run that has been silent this long is one whose worker
     * died — and refusing to start a new one forever, because a row still says
     * "running", is the state nobody can get out of without a database client.
     */
    public const int STALE_AFTER_SECONDS = 900;

    public function __construct(
        private Connection      $connection,
        private LoggerInterface $logger,
    ) {
    }

    /** The row as it stands, or a fresh idle one when there is nothing to read. */
    public function current(): BackfillRun
    {
        try {
            $row = $this->connection->fetchAssociative('SELECT * FROM ai_backfill_state WHERE singleton = 1');
        } catch (Throwable) {
            // Most likely the table is not there yet, on a container that came
            // up before the migration lock released.
            return new BackfillRun();
        }

        if (false === $row) {
            return new BackfillRun();
        }

        $mailboxes = self::decodeMailboxes($row['mailboxes'] ?? null);

        return new BackfillRun(
            status:            BackfillStatus::tryFrom((string) ($row['status'] ?? '')) ?? BackfillStatus::Idle,
            pauseReason:       null === $row['pause_reason'] ? null : BackfillPauseReason::tryFrom((string) $row['pause_reason']),
            model:             null === $row['model'] ? null : (string) $row['model'],
            mailboxes:         $mailboxes,
            failures:          (int) ($row['failures'] ?? 0),
            emptyBatches:      (int) ($row['empty_batches'] ?? 0),
            lastError:         null === $row['last_error'] ? null : (string) $row['last_error'],
            startedAt:         self::moment($row['started_at'] ?? null),
            lastProgressAt:    self::moment($row['last_progress_at'] ?? null),
            finishedAt:        self::moment($row['finished_at'] ?? null),
            interactiveSeenAt: self::moment($row['interactive_seen_at'] ?? null),
        );
    }

    /**
     * Claim the run, or refuse because one is already going.
     *
     * The guard is in the WHERE clause rather than in a preceding read: two
     * administrators pressing Start in the same second would both pass a check
     * in PHP, and two chains over one mailbox is twice the requests to a host
     * that is the bottleneck by definition.
     *
     * @param list<int> $userIds the mailboxes this run covers
     *
     * @return bool false when a live run already holds it
     */
    public function begin(string $model, array $userIds, DateTimeImmutable $now): bool
    {
        $this->ensureRow($now);

        $mailboxes = [];

        foreach ($userIds as $userId) {
            $mailboxes[(string) $userId] = ['cursor' => null, 'done' => false];
        }

        $sql = <<<'SQL'
            UPDATE ai_backfill_state
               SET status           = 'running',
                   pause_reason     = NULL,
                   model            = :model,
                   mailboxes        = :mailboxes::jsonb,
                   failures         = 0,
                   empty_batches    = 0,
                   last_error       = NULL,
                   started_at       = :now,
                   last_progress_at = :now,
                   finished_at      = NULL,
                   updated_at       = :now
             WHERE singleton = 1
               AND (
                     status NOT IN ('running', 'paused')
                  OR pause_reason IN ('operator', 'feature_off')
                  OR last_progress_at IS NULL
                  OR last_progress_at < :stale
               )
        SQL;

        return $this->write($sql, [
            'model'     => $model,
            'mailboxes' => json_encode($mailboxes, JSON_THROW_ON_ERROR),
            'now'       => $now,
            'stale'     => $now->modify('-' . self::STALE_AFTER_SECONDS . ' seconds'),
        ], ['now' => Types::DATETIME_IMMUTABLE, 'stale' => Types::DATETIME_IMMUTABLE]) > 0;
    }

    /**
     * Pick a stopped run back up, without touching the cursors.
     *
     * Refuses a run that is merely stepping aside — that one has a delivery in
     * the queue already, and dispatching a second chain for the same mailbox is
     * how a "resume" doubles the load it was meant to restore.
     */
    public function resume(DateTimeImmutable $now): bool
    {
        $sql = <<<'SQL'
            UPDATE ai_backfill_state
               SET status           = 'running',
                   pause_reason     = NULL,
                   empty_batches    = 0,
                   last_error       = NULL,
                   started_at       = COALESCE(started_at, :now),
                   last_progress_at = :now,
                   finished_at      = NULL,
                   updated_at       = :now
             WHERE singleton = 1
               AND (
                     status = 'failed'
                  OR (status = 'paused' AND pause_reason IN ('operator', 'feature_off'))
                  OR (status IN ('running', 'paused') AND (last_progress_at IS NULL OR last_progress_at < :stale))
               )
        SQL;

        return $this->write($sql, [
            'now'   => $now,
            'stale' => $now->modify('-' . self::STALE_AFTER_SECONDS . ' seconds'),
        ], ['now' => Types::DATETIME_IMMUTABLE, 'stale' => Types::DATETIME_IMMUTABLE]) > 0;
    }

    /**
     * Stop, and say why.
     *
     * The reason decides whether anything is still coming: the two that resume
     * themselves leave a delayed delivery in the queue, and the two that do not
     * have ended the chain. {@see BackfillPauseReason}.
     */
    public function pause(BackfillPauseReason $reason, DateTimeImmutable $now): void
    {
        $this->write(
            <<<'SQL'
                UPDATE ai_backfill_state
                   SET status       = 'paused',
                       pause_reason = :reason,
                       updated_at   = :now
                 WHERE singleton = 1
            SQL,
            ['reason' => $reason->value, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Step aside, but stay alive.
     *
     * The same row as pause(), plus the clock: a chain that yields to the
     * composer or waits out an unreachable host is still in the queue and still
     * checking in, and leaving last_progress_at behind would have the panel
     * calling it stalled after fifteen minutes of somebody using the composer
     * hard — which is the moment the yielding is working best.
     *
     * Only ever called with a reason that resumes itself; the two that end the
     * chain go through pause() and deliberately stop touching the clock, so
     * that a run abandoned mid-pause is still recognisable as abandoned.
     */
    public function yieldFor(BackfillPauseReason $reason, DateTimeImmutable $now): void
    {
        $this->write(
            <<<'SQL'
                UPDATE ai_backfill_state
                   SET status           = 'paused',
                       pause_reason     = :reason,
                       last_progress_at = :now,
                       updated_at       = :now
                 WHERE singleton = 1
            SQL,
            ['reason' => $reason->value, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * One chunk got through: cursor forward, failures counted, clock touched.
     *
     * jsonb_set rather than writing the whole object back, so two mailboxes
     * being walked at once cannot lose each other's cursor. `true` as the last
     * argument creates the key if a mailbox was added to the install after the
     * run began.
     *
     * The status goes back to 'running' here, which is what lifts a pause that
     * resumes itself — the walk demonstrating it is moving again rather than a
     * separate transition somebody has to remember to write.
     */
    public function recordChunk(int $userId, ?int $cursor, bool $done, int $failures, DateTimeImmutable $now): void
    {
        $this->write(
            <<<'SQL'
                UPDATE ai_backfill_state
                   SET mailboxes        = jsonb_set(
                           COALESCE(mailboxes, '{}'::jsonb),
                           ARRAY[:userId],
                           jsonb_build_object('cursor', :cursor::int, 'done', :done::boolean),
                           true
                       ),
                       failures         = failures + :failures,
                       empty_batches    = 0,
                       status           = 'running',
                       pause_reason     = NULL,
                       last_progress_at = :now,
                       updated_at       = :now
                 WHERE singleton = 1
            SQL,
            [
                'userId'   => (string) $userId,
                'cursor'   => $cursor,
                'done'     => $done ? 'true' : 'false',
                'failures' => max(0, $failures),
                'now'      => $now,
            ],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * A chunk that stored nothing at all, which usually means the host is gone.
     *
     * Counted rather than acted on here: one is a blink, several in a row is a
     * host that is not coming back on its own, and the handler decides which of
     * those it is looking at.
     *
     * @return int how many in a row now
     */
    public function noteEmptyChunk(DateTimeImmutable $now): int
    {
        $this->write(
            <<<'SQL'
                UPDATE ai_backfill_state
                   SET empty_batches = empty_batches + 1,
                       updated_at    = :now
                 WHERE singleton = 1
            SQL,
            ['now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        return $this->current()->emptyBatches;
    }

    public function markFailed(string $errorKind, DateTimeImmutable $now): void
    {
        $this->write(
            <<<'SQL'
                UPDATE ai_backfill_state
                   SET status       = 'failed',
                       pause_reason = NULL,
                       last_error   = :error,
                       updated_at   = :now
                 WHERE singleton = 1
            SQL,
            ['error' => mb_substr($errorKind, 0, 32), 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function markComplete(DateTimeImmutable $now): void
    {
        $this->write(
            <<<'SQL'
                UPDATE ai_backfill_state
                   SET status       = 'complete',
                       pause_reason = NULL,
                       finished_at  = :now,
                       updated_at   = :now
                 WHERE singleton = 1
            SQL,
            ['now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Somebody is using the AI right now.
     *
     * A single timestamp, written from the web process and read from the
     * worker, which is the only reason it is in the database at all: the two
     * are different containers and share nothing else that survives a restart.
     * It is the newest of these and the newest interactive row in
     * ai_call_metric that together answer "is the GPU wanted elsewhere" —
     * this one covers a request that is still in flight, that one covers a
     * request that has just finished. See InteractiveAiActivity.
     */
    public function touchInteractive(DateTimeImmutable $now): void
    {
        $this->ensureRow($now);

        $this->write(
            <<<'SQL'
                UPDATE ai_backfill_state
                   SET interactive_seen_at = :now,
                       updated_at          = :now
                 WHERE singleton = 1
                   AND (interactive_seen_at IS NULL OR interactive_seen_at < :now)
            SQL,
            ['now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * The row exists, whatever happens.
     *
     * ON CONFLICT DO NOTHING rather than a check first: two workers coming up
     * together would both find nothing and both insert, and the unique index on
     * singleton is the only thing that can settle that without a lock.
     */
    private function ensureRow(DateTimeImmutable $now): void
    {
        $this->write(
            <<<'SQL'
                INSERT INTO ai_backfill_state (singleton, status, mailboxes, failures, empty_batches, created_at, updated_at)
                VALUES (1, 'idle', '{}'::jsonb, 0, 0, :now, :now)
                ON CONFLICT (singleton) DO NOTHING
            SQL,
            ['now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     *
     * @return int rows affected, or 0 when the write could not happen at all
     */
    private function write(string $sql, array $params = [], array $types = []): int
    {
        try {
            return (int) $this->connection->executeStatement($sql, $params, $types);
        } catch (Throwable $exception) {
            // Warning rather than error, and swallowed: the chunk that called
            // this has already done its work, and losing the note about it is
            // not a reason to fail the message and retry the whole batch.
            $this->logger->warning('AiBackfillStateRepository: could not write the backfill state', [
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return 0;
        }
    }

    /**
     * The cursor map, keyed by user id.
     *
     * int|string rather than string: json_decode() turns the numeric object
     * keys PostgreSQL stores into INTEGER array keys, which is also what makes
     * a lookup by `$userId` work without a cast. Declaring them as strings
     * would be a type no lookup could ever match.
     *
     * @return array<int|string, array{cursor: int|null, done: bool}>
     */
    private static function decodeMailboxes(mixed $value): array
    {
        // DBAL hands jsonb back as a string on PostgreSQL unless the column is
        // mapped, and nothing maps this one.
        if (true === is_string($value)) {
            $value = json_decode($value, true);
        }

        if (false === is_array($value)) {
            return [];
        }

        $mailboxes = [];

        foreach ($value as $userId => $entry) {
            if (false === is_array($entry)) {
                continue;
            }

            $cursor = $entry['cursor'] ?? null;

            $mailboxes[$userId] = [
                'cursor' => true === is_int($cursor) ? $cursor : null,
                'done'   => true === ($entry['done'] ?? false),
            ];
        }

        return $mailboxes;
    }

    private static function moment(mixed $value): ?DateTimeImmutable
    {
        if (false === is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
