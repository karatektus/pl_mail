<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Domain\Helper\ReadableBody;
use App\Repository\Ai\MessageEmbedding\MessageEmbeddingRepository;
use App\Repository\Mail\Message\MessageSearchIndexRepository;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Indexes the messages whose search_vector is still NULL, newest first.
 *
 * Version20261105120000 turned search_vector from a generated column into one
 * a trigger keeps, so that the migration would not rewrite the table, and
 * every row it found started out NULL. This is what fills them: from the
 * schedule (`app:search:index-pending`, a short pass every minute) and from
 * `app:backfill html-body-text` for an administrator who wants it done now.
 * NULL is the cursor, so a pass that is cut off loses nothing — the next one
 * starts where the rows say, not where a counter does.
 *
 * NEWEST FIRST, because that is what people search for. A mailbox of a
 * hundred thousand messages takes a while, and while it runs, last week's
 * mail being findable matters more than 2014's. Until a row is reached it is
 * not found by full-text; the substring pass still finds it.
 *
 * PER ROW, TWO THINGS:
 *
 *   1. body_html_text, for mail sent as HTML alone (#477) — the same test as
 *      ReadableBody::htmlWords(), whose answer is written, and only for rows
 *      that do not have one yet. The bodies are read for those rows only; the
 *      rest are not even detoasted.
 *   2. search_vector set to an empty vector. That is not the value it ends up
 *      with: it is what tells the trigger to build it. The trigger leaves a
 *      NULL row alone on any other update, because a vector built by an
 *      unrelated update — a flag, a move — would be built without
 *      body_html_text and would take the row off this list for good.
 *
 * The embedding of a row whose body_html_text was written here is dropped: it
 * was made from the subject and sender alone, and the embedding passes only
 * look for mail that has none. The nightly sweep and the next search make it
 * again (see EmbeddingCatchUp). A thread summary needs nothing; it is seen to
 * be out of date the next time the thread is opened.
 *
 * ONE ROW THAT WILL NOT INDEX DOES NOT STOP THE REST (#546). Each row is
 * written under a savepoint of its own. A batch used to be one statement in
 * one transaction, and a message whose vector Postgres refused rolled back the
 * other 499 with it; newest first, the next pass took the same batch and
 * failed the same way, and the index stopped at 500 of 9,444 for good. A row
 * that fails now is logged and left NULL, and this pass does not ask for it
 * again; the next pass tries it once more, which is right for a failure that
 * was only a moment's.
 *
 * FOR UPDATE SKIP LOCKED, so two passes at once — the schedule and an
 * administrator's backfill — share the work instead of waiting on each other,
 * and a sync holding a row it is writing is simply passed by until next time.
 */
final readonly class SearchIndexRebuilder
{
    /** @param int $batchSize rows per transaction; a parameter so a test can watch the order */
    public function __construct(
        private MessageSearchIndexRepository $index,
        private MessageEmbeddingRepository   $embeddings,
        private int        $batchSize = 500,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /** How many messages are still waiting. One probe of a partial index once there are none. */
    public function pending(): int
    {
        return $this->index->countPending();
    }

    /**
     * Index waiting messages until there are none or the time is up.
     *
     * The budget is checked between batches, so a pass runs over it by at most
     * one batch.
     *
     * @param float|null                  $seconds  null: until there are none
     * @param (callable(int): void)|null  $progress told how many rows each batch did
     *
     * @return array{indexed: int, filled: int} rows indexed, and of those how many got body_html_text
     */
    public function rebuild(?float $seconds = null, ?callable $progress = null): array
    {
        $deadline = null === $seconds ? null : microtime(true) + $seconds;
        $indexed  = 0;
        $filled   = 0;
        $refused  = [];

        do {
            [$batch, $words] = $this->batch($refused);

            $indexed += $batch;
            $filled  += $words;

            if (null !== $progress && $batch > 0) {
                $progress($batch);
            }
        } while ($batch > 0 && (null === $deadline || microtime(true) < $deadline));

        return ['indexed' => $indexed, 'filled' => $filled];
    }

    /**
     * @param list<int> $refused rows this pass could not index, not asked for again
     *
     * @return array{int, int} rows taken, rows given body_html_text
     */
    private function batch(array &$refused): array
    {
        return $this->index->inTransaction(function () use (&$refused): array {
            $rows   = $this->index->lockPendingBatch($this->batchSize, $refused);
            $filled = [];
            $plain  = [];

            foreach ($rows as $row) {
                $id    = (int) $row['id'];
                $words = true === $row['needs_words']
                    ? ReadableBody::htmlWords($row['body_text'], $row['body_html'], $row['body_html_safe'], null !== $row['graph_id'])
                    : null;

                if (null === $words) {
                    $plain[] = $id;
                    continue;
                }

                if (true === $this->attempt($id, fn () => $this->index->fillWords($id, $words), $refused)) {
                    $filled[] = $id;
                }
            }

            // Together while they go through, which is every time but the
            // rare one; one by one only when the batch is refused, to find the
            // row it was refused for.
            if ([] !== $plain && false === $this->attempt(null, fn () => $this->index->markForIndexing($plain), $refused)) {
                foreach ($plain as $id) {
                    $this->attempt($id, fn () => $this->index->markForIndexing([$id]), $refused);
                }
            }

            if ([] !== $filled) {
                $this->embeddings->deleteForMessages($filled);
            }

            return [count($rows), count($filled)];
        });
    }

    /**
     * Run one write under a savepoint: the nested transaction is one, so a
     * refusal rolls back this write alone and the batch's transaction goes on.
     *
     * @param int|null  $id      the row, or null for a write over several
     * @param list<int> $refused where a refused row is recorded
     */
    private function attempt(?int $id, callable $write, array &$refused): bool
    {
        try {
            $this->index->inTransaction($write);

            return true;
        } catch (DbalException $e) {
            if (null === $id) {
                return false;
            }

            $refused[] = $id;

            $this->logger->warning('A message could not be indexed for search; it is left out of this pass', [
                'messageId' => $id,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
    }
}
