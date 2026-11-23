<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Domain\DTO\Ai\StoredTranslation;
use App\Domain\Enum\Ai\TranslationLanguage;
use App\Repository\Ai\MessageTranslation\MessageTranslationRepository;
use Closure;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes the stored translations, and nothing else (#713).
 *
 * ThreadSummaryStore's shape for ThreadSummaryStore's reasons, which its
 * docblock gives at length: DBAL and no entity; the model and the prompt
 * fingerprint in the read, so a row written by another model or under another
 * prompt is invisible; the content hash compared by the caller, so a row for a
 * message whose text has changed since (a mail decrypted later, say) is STALE
 * and shown as such rather than hidden; an upsert, so two tabs translating at
 * once overwrite rather than collide; and no deletion here at all. A message
 * that is deleted takes its rows with it by ON DELETE CASCADE.
 */
final readonly class TranslationStore
{
    public function __construct(
        private MessageTranslationRepository $translations,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The translation of a message into a language, if the current model and
     * prompt wrote it. Null for every reason, a database error included: the
     * answer to all of them is the button.
     *
     * The source hash is asked for only when there is a row to compare it
     * with. The conversation view asks this for every message it draws, and
     * reading the text out of every body of a long conversation to compare it
     * with nothing would be the slowest part of the page.
     *
     * @param Closure(): string $sourceHash
     */
    public function forMessage(int $messageId, TranslationLanguage $language, string $model, string $promptHash, Closure $sourceHash): ?StoredTranslation
    {
        try {
            $row = $this->translations->findForMessage($messageId, $language->value, $model, $promptHash);
        } catch (Throwable $exception) {
            $this->logger->error('TranslationStore: could not read a stored translation', [
                'messageId' => $messageId,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return null;
        }

        return false === $row ? null : self::stored($row, $language, $sourceHash());
    }

    /**
     * The rows of every message of a conversation, in one query and by message
     * id; a message without a usable translation is absent. Hand the row of
     * the message being drawn to stored() with that message's source hash.
     *
     * Rows and not translations, because whether a translation is fresh needs
     * the message's text, and the caller has only the message it is drawing:
     * asking for the others here would load every message of the conversation
     * wherever one of them is drawn on its own (a Turbo Stream, the single
     * message pane). The view draws them all, and one lookup per message is
     * what ThreadViewQueryBudgetTest exists to refuse.
     *
     * @return array<int, array{translation: string, source_language: string|null, source_hash: string, created_at: string}>
     */
    public function rowsForThread(int $threadId, TranslationLanguage $language, string $model, string $promptHash): array
    {
        try {
            return $this->translations->findForThread($threadId, $language->value, $model, $promptHash);
        } catch (Throwable $exception) {
            $this->logger->error('TranslationStore: could not read stored translations', [
                'threadId'  => $threadId,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return [];
        }
    }

    /**
     * A stored row as a translation, fresh when its hash is the hash of the
     * text the message would be translated from now.
     *
     * @param array{translation: string, source_language: string|null, source_hash: string, created_at: string} $row
     */
    public static function stored(array $row, TranslationLanguage $language, string $sourceHash): StoredTranslation
    {
        try {
            $writtenAt = new DateTimeImmutable((string) $row['created_at']);
        } catch (Throwable) {
            // A timestamp that cannot be parsed costs the date and nothing else.
            $writtenAt = null;
        }

        return new StoredTranslation(
            (string) $row['translation'],
            $language,
            null === $row['source_language'] ? null : (string) $row['source_language'],
            hash_equals((string) $row['source_hash'], $sourceHash),
            $writtenAt,
        );
    }

    /**
     * Write one, replacing the one there was. Logged and swallowed like the
     * summary's: this runs after the translation has been streamed to the
     * reader, and what a failure loses is the caching, not the translation.
     *
     * @return bool whether anything was stored
     */
    public function save(int $messageId, TranslationLanguage $language, string $translation, ?string $sourceLanguage, string $sourceHash, string $model, string $promptHash): bool
    {
        try {
            $this->translations->upsert($messageId, $language->value, $translation, $sourceLanguage, $sourceHash, $model, $promptHash, new DateTimeImmutable());

            return true;
        } catch (Throwable $exception) {
            $this->logger->error('TranslationStore: could not store a translation', [
                'messageId' => $messageId,
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return false;
        }
    }
}
