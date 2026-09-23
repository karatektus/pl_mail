<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\ThreadRow;
use App\Domain\DTO\Mail\ThreadRowMessage;
use App\Domain\Enum\Mail\MessageFlag;
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
        $messages = array_values(array_map(
            ThreadRowMessage::of(...),
            $thread->messages->toArray(),
        ));

        $latest = $thread->messages->last();

        return new ThreadRow($messages, $this->snippet->of(false === $latest ? null : $latest));
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
            );
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
                null === $body ? '' : $this->snippet->fromBodies($body['bodyHtmlSafe'], $body['bodyText']),
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
}
