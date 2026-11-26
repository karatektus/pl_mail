<?php

declare(strict_types=1);

namespace App\Repository\Ai\ThreadSummary;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * thread_summary: the model's summary of a conversation, one per thread, with
 * the model, prompt and source it was made from.
 *
 * Raw DBAL because the table has no entity; ThreadSummaryStore decides what a
 * stored summary is still good for.
 */
final readonly class ThreadSummaryRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * The stored summary of a thread made by this model and prompt.
     *
     * @return array{summary: string, source_hash: string, created_at: string, full_context: bool|int|string}|false
     */
    public function findForThread(int $threadId, string $model, string $promptHash): array|false
    {
        /** @var array{summary: string, source_hash: string, created_at: string, full_context: bool|int|string}|false */
        return $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT summary, source_hash, created_at, full_context
                  FROM thread_summary
                 WHERE thread_id = :id
                   AND model = :model
                   AND prompt_hash = :prompt
            SQL,
            ['id' => $threadId, 'model' => $model, 'prompt' => $promptHash],
            ['id' => ParameterType::INTEGER],
        );
    }

    /** Store a thread's summary, replacing whatever it had. */
    public function upsert(int $threadId, string $summary, string $sourceHash, string $model, string $promptHash, bool $fullContext, DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO thread_summary (thread_id, summary, source_hash, model, prompt_hash, full_context, created_at)
                VALUES (:id, :summary, :hash, :model, :prompt, :full, :now)
                ON CONFLICT (thread_id) DO UPDATE
                    SET summary      = EXCLUDED.summary,
                        source_hash  = EXCLUDED.source_hash,
                        model        = EXCLUDED.model,
                        prompt_hash  = EXCLUDED.prompt_hash,
                        full_context = EXCLUDED.full_context,
                        created_at   = EXCLUDED.created_at
            SQL,
            [
                'id'      => $threadId,
                'summary' => $summary,
                'hash'    => $sourceHash,
                'model'   => $model,
                'prompt'  => $promptHash,
                'full'    => $fullContext,
                'now'     => $now->format('Y-m-d H:i:s'),
            ],
            ['id' => ParameterType::INTEGER, 'full' => ParameterType::BOOLEAN],
        );
    }

    /** A thread's summary, gone. */
    public function deleteForThread(int $threadId): void
    {
        $this->connection->executeStatement('DELETE FROM thread_summary WHERE thread_id = ?', [$threadId]);
    }

    /** The summaries of threads holding an account's mail whose plaintext came from a browser. */
    public function deleteForPlaintextSource(int $accountId, string $plaintextSource): void
    {
        $this->connection->executeStatement(
            'DELETE FROM thread_summary WHERE thread_id IN (SELECT thread_id FROM message WHERE account_id = ? AND plaintext_source = ? AND thread_id IS NOT NULL)',
            [$accountId, $plaintextSource],
        );
    }
}
