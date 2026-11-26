<?php

declare(strict_types=1);

namespace App\Repository\Ai\MessageTranslation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * message_translation: the model's translation of one message into one
 * language, with the model, prompt and source it was made from (#713).
 *
 * Raw DBAL because the table has no entity, like thread_summary;
 * TranslationStore decides what a stored translation is still good for.
 */
final readonly class MessageTranslationRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * The stored translation of a message into a language, made by this model
     * and prompt.
     *
     * @return array{translation: string, source_language: string|null, source_hash: string, created_at: string}|false
     */
    public function findForMessage(int $messageId, string $language, string $model, string $promptHash): array|false
    {
        /** @var array{translation: string, source_language: string|null, source_hash: string, created_at: string}|false */
        return $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT translation, source_language, source_hash, created_at
                  FROM message_translation
                 WHERE message_id = :id
                   AND target_language = :language
                   AND model = :model
                   AND prompt_hash = :prompt
            SQL,
            ['id' => $messageId, 'language' => $language, 'model' => $model, 'prompt' => $promptHash],
            ['id' => ParameterType::INTEGER],
        );
    }

    /**
     * The same for every message of a conversation, by message id: one query
     * for the whole of it rather than one per message it shows, and by the
     * thread's id so that nobody has to load its messages to ask.
     *
     * @return array<int, array{translation: string, source_language: string|null, source_hash: string, created_at: string}>
     */
    public function findForThread(int $threadId, string $language, string $model, string $promptHash): array
    {
        /** @var list<array{message_id: int|string, translation: string, source_language: string|null, source_hash: string, created_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT t.message_id, t.translation, t.source_language, t.source_hash, t.created_at
                  FROM message_translation t
                  JOIN message m ON m.id = t.message_id
                 WHERE m.thread_id = :thread
                   AND t.target_language = :language
                   AND t.model = :model
                   AND t.prompt_hash = :prompt
            SQL,
            ['thread' => $threadId, 'language' => $language, 'model' => $model, 'prompt' => $promptHash],
            ['thread' => ParameterType::INTEGER],
        );

        $byMessage = [];

        foreach ($rows as $row) {
            $byMessage[(int) $row['message_id']] = [
                'translation'     => $row['translation'],
                'source_language' => $row['source_language'],
                'source_hash'     => $row['source_hash'],
                'created_at'      => $row['created_at'],
            ];
        }

        return $byMessage;
    }

    /** Store a message's translation into a language, replacing the one it had. */
    public function upsert(int $messageId, string $language, string $translation, ?string $sourceLanguage, string $sourceHash, string $model, string $promptHash, DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO message_translation (message_id, target_language, translation, source_language, source_hash, model, prompt_hash, created_at)
                VALUES (:id, :language, :translation, :source, :hash, :model, :prompt, :now)
                ON CONFLICT (message_id, target_language) DO UPDATE
                    SET translation     = EXCLUDED.translation,
                        source_language = EXCLUDED.source_language,
                        source_hash     = EXCLUDED.source_hash,
                        model           = EXCLUDED.model,
                        prompt_hash     = EXCLUDED.prompt_hash,
                        created_at      = EXCLUDED.created_at
            SQL,
            [
                'id'          => $messageId,
                'language'    => $language,
                'translation' => $translation,
                'source'      => $sourceLanguage,
                'hash'        => $sourceHash,
                'model'       => $model,
                'prompt'      => $promptHash,
                'now'         => $now->format('Y-m-d H:i:s'),
            ],
            ['id' => ParameterType::INTEGER],
        );
    }

    /** A message's translations, gone. */
    public function deleteForMessage(int $messageId): void
    {
        $this->connection->executeStatement('DELETE FROM message_translation WHERE message_id = ?', [$messageId]);
    }

    /** The translations of an account's mail whose plaintext came from a browser. */
    public function deleteForPlaintextSource(int $accountId, string $plaintextSource): void
    {
        $this->connection->executeStatement(
            'DELETE FROM message_translation WHERE message_id IN (SELECT id FROM message WHERE account_id = ? AND plaintext_source = ?)',
            [$accountId, $plaintextSource],
        );
    }
}
