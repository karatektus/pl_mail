<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\ThreadRow;
use App\Domain\DTO\Mail\ThreadRowMessage;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\SendFailure;
use App\Entity\Mail\MessageThread;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\AbstractLazyCollection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What a page of conversation rows reads from their messages, fetched once.
 *
 * ── What this replaces ──────────────────────────────────────────────────────
 * The row used to walk `thread.messages`, so the list preloaded that whole
 * collection with a fetch join: every message of every thread on the page,
 * hydrated in full — body text, raw HTML, sanitised HTML, the header blob —
 * to read a sender and a date off each and a snippet off the last. On a page of
 * long newsletter threads that was megabytes per render of mail nobody could
 * see from the list.
 *
 * Now it is two statements for the page: the row fields of every message
 * (MessageRepository::findRowFieldsForThreads), and the two body columns of
 * the ONE message per row whose snippet is shown. The snippet is flattened
 * here, once per row, rather than by the template.
 *
 * ── The three ways a row is asked for ───────────────────────────────────────
 * A list page calls preload() with its rows first. A single row re-rendered on
 * its own — a status stream after archive or star — was never preloaded and is
 * loaded alone, which costs what the lazy collection used to. And a thread
 * whose messages are ALREADY in memory — the thread view, a sync that just
 * assembled it — is read from the entities, because those are the truth for
 * this request and may hold a message not yet flushed.
 *
 * ── One side of a partly deleted conversation ──────────────────────────────
 * A thread with some of its messages in the bin is listed in Trash and in its
 * other lists at once (#268). Each row is drawn from the messages on its side
 * only (BinView): sender, snippet, draft, count and date. A side with nothing
 * on it is a thread wholly on the other, which is drawn whole.
 *
 * Resettable because FrankenPHP and the Messenger workers keep this alive
 * between requests, and a row answered from a previous one is stale.
 */
final class ThreadRows implements ResetInterface
{
    /** @var array<int, ThreadRow> thread id => its row */
    private array $rows = [];

    public function __construct(
        private readonly MessageThreadRepository $threads,
        private readonly MessageRepository $messages,
        private readonly MessageSnippet $snippet,
        private readonly BinView $bin,
    ) {
    }

    public function reset(): void
    {
        $this->rows = [];
    }

    /**
     * Everything one page of thread ROWS reads, in four queries.
     *
     * One entry point for the six list views. A list that gets one preload and
     * not another is the failure this exists to prevent — search once cost
     * fifty queries more than the inbox for the same rows because it skipped
     * the label preload — so adding a fifth thing the row needs should mean
     * editing this method, not auditing every controller.
     *
     * @param MessageThread[] $threads
     */
    public function preload(array $threads): void
    {
        if ([] === $threads) {
            return;
        }

        $this->threads->preloadForRows($threads);

        $ids = [];

        foreach ($threads as $thread) {
            if (null !== $thread->id && !$this->inMemory($thread)) {
                $ids[] = $thread->id;
            }
        }

        $this->load($ids);
    }

    public function for(MessageThread $thread): ThreadRow
    {
        if (null === $thread->id || $this->inMemory($thread)) {
            return $this->fromEntities($thread);
        }

        if (!isset($this->rows[$thread->id])) {
            $this->load([$thread->id]);
        }

        return $this->rows[$thread->id];
    }

    /**
     * An initialised collection is Doctrine's in-memory truth, so it wins over
     * a fetched copy; an uninitialised one costs nothing to ask about.
     */
    private function inMemory(MessageThread $thread): bool
    {
        $messages = $thread->messages;

        return !$messages instanceof AbstractLazyCollection || $messages->isInitialized();
    }

    private function fromEntities(MessageThread $thread): ThreadRow
    {
        $entities = array_values($thread->messages->toArray());
        $all      = array_map(ThreadRowMessage::of(...), $entities);
        $kept     = $this->side($all);

        // The entity behind the side's latest, for the body its snippet needs.
        $latest = null;

        foreach ($entities as $index => $entity) {
            if (in_array($all[$index], $kept, true)) {
                $latest = $entity;
            }
        }

        return new ThreadRow(
            $kept,
            $this->snippet->of($latest),
            true === $latest?->crypto?->isEncrypted(),
            count($kept) < count($all),
        );
    }

    /**
     * The messages on the side being looked at: the deleted ones in Trash, the
     * others anywhere else — or all of them, when that side has none.
     *
     * @param list<ThreadRowMessage> $messages
     *
     * @return list<ThreadRowMessage>
     */
    private function side(array $messages): array
    {
        $inBin = $this->bin->isOpen();
        $kept  = array_values(array_filter(
            $messages,
            static fn (ThreadRowMessage $message): bool => $message->trashed === $inBin,
        ));

        return [] === $kept ? $messages : $kept;
    }

    /**
     * @param list<int> $threadIds
     */
    private function load(array $threadIds): void
    {
        if ([] === $threadIds) {
            return;
        }

        /** @var array<int, list<ThreadRowMessage>> $byThread */
        $byThread = array_fill_keys($threadIds, []);

        foreach ($this->messages->findRowFieldsForThreads($threadIds) as $row) {
            $flags = is_array($row['flags']) ? $row['flags'] : [];

            /** @var DateTimeImmutable $createdAt */
            $createdAt = $row['createdAt'];

            $byThread[(int) $row['threadId']][] = new ThreadRowMessage(
                (int) $row['id'],
                self::stringOrNull($row['fromAddress']),
                self::stringOrNull($row['fromName']),
                is_array($row['toAddresses']) ? $row['toAddresses'] : null,
                in_array(MessageFlag::DRAFT->value, $flags, true),
                self::dateOrNull($row['receivedAt']),
                self::dateOrNull($row['sentAt']),
                $createdAt,
                self::dateOrNull($row['submissionSendAt']),
                (int) ($row['filedAsSent'] ?? 0) > 0,
                self::sendFailureOf($row['sendFailure'] ?? null),
                (int) ($row['trashed'] ?? 0) > 0,
                null === $row['seenAt'],
                true === $row['hasAttachments'],
            );
        }

        /** @var array<int, bool> $narrowed thread id => whether a side was left out */
        $narrowed = [];

        foreach ($byThread as $threadId => $messages) {
            $kept                = $this->side($messages);
            $narrowed[$threadId] = count($kept) < count($messages);
            $byThread[$threadId] = $kept;
        }

        $latestIds = [];

        foreach ($byThread as $messages) {
            $latest = $messages[count($messages) - 1] ?? null;

            if (null !== $latest?->id) {
                $latestIds[] = $latest->id;
            }
        }

        $bodies = $this->messages->findSnippetBodies($latestIds);

        foreach ($byThread as $threadId => $messages) {
            $latestId = ($messages[count($messages) - 1] ?? null)?->id;
            $body     = null !== $latestId ? ($bodies[$latestId] ?? null) : null;

            $this->rows[$threadId] = new ThreadRow(
                $messages,
                null === $body ? '' : $this->snippet->fromBodies($body['bodyHtmlSafe'], $body['bodyText'], $body['category']),
                // A thread with no messages has no body row at all, and the
                // nullsafe operator guards the call, not the offset.
                null !== $body && true === $body['crypto']?->isEncrypted(),
                $narrowed[$threadId],
            );
        }
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        return $value instanceof DateTimeImmutable ? $value : null;
    }

    /**
     * The column as the array result gives it: the enum when Doctrine maps it,
     * its string otherwise. Either way, an unknown value is no warning rather
     * than a broken list.
     */
    private static function sendFailureOf(mixed $value): ?SendFailure
    {
        if ($value instanceof SendFailure) {
            return $value;
        }

        return is_string($value) ? SendFailure::tryFrom($value) : null;
    }
}
