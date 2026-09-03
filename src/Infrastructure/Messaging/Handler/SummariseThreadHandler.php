<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Infrastructure\Messaging\Message\SummariseThreadMessage;
use App\Repository\Mail\MessageThreadRepository;
use App\Repository\User\UserRepository;
use App\Service\Ai\ThreadSummariser;
use App\Service\Ai\ThreadSummaryNotifier;
use App\Service\Ai\ThreadSummaryStore;
use App\Service\Ai\ThreadTranscript;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes a full-conversation summary with nobody waiting on the other end.
 *
 * The whole point is what is ABSENT here: no StreamedResponse, no
 * connection_aborted(), no set_time_limit() racing an idle timeout, and no
 * frames whose delivery anything can interrupt. The model is asked, the answer
 * is stored, and a nudge goes out. A reader who closed the tab thirty seconds in
 * gets the summary anyway, because nothing about producing it was theirs to
 * hold open.
 *
 * WHY THE STREAM IS ITERATED AND THROWN AWAY
 * ──────────────────────────────────────────
 * ThreadSummariser::stream() is a generator and its RETURN is the finished,
 * tidied answer — the tokens are for a reader watching, and there is not one.
 * Iterating to the end is how the return becomes available and how
 * AiAssistant::recorded() gets to file an honest metrics row; abandoning it
 * would record the call as cancelled, which is the word for a reader who left
 * and would be a lie about a worker that did the job.
 *
 * THE PERMISSION IS CHECKED AGAIN, for EmbedMessagesHandler's reason with a
 * sharper edge: an envelope outlives the request that made it, an administrator
 * may switch summaries off in between, and this one costs minutes of somebody
 * else's GPU. stream() refuses on its own if the feature is off — it is asked
 * here so the refusal is a logged decision rather than a silent null.
 */
#[AsMessageHandler]
final readonly class SummariseThreadHandler
{
    public function __construct(
        private MessageThreadRepository $threads,
        private UserRepository          $users,
        private ThreadSummariser        $summariser,
        private ThreadTranscript        $transcript,
        private ThreadSummaryStore      $store,
        private ThreadSummaryNotifier   $notifier,
        private LoggerInterface         $logger,
    ) {
    }

    public function __invoke(SummariseThreadMessage $message): void
    {
        $user   = $this->users->find($message->userId);
        $thread = $this->threads->find($message->threadId);

        if (null === $user || null === $thread) {
            // Deleted between the press and the worker. Not a failure worth
            // retrying, and not worth a nudge either — the card it would reach
            // is on a page that no longer has the thread.
            return;
        }

        if (false === $this->summariser->isAvailableFor($user)
            || false === ThreadSummariser::hasEnoughToSummarise($thread)) {
            $this->logger->info('SummariseThreadHandler: refused before asking the model', [
                'thread' => $message->threadId,
            ]);

            $this->notifier->finished($message->userId, $message->threadId, 'failed');

            return;
        }

        // The FULL transcript, which is the entire reason this job exists.
        $transcript = $this->transcript->forThread($thread, true);
        $tokens     = $this->summariser->stream($user, $thread, $transcript, true);

        if (null === $tokens) {
            $this->notifier->finished($message->userId, $message->threadId, 'failed');

            return;
        }

        foreach ($tokens as $ignored) {
            // Drained, not used. See the class docblock: the tokens are for a
            // reader and the return is the answer.
        }

        $result = $tokens->getReturn();

        if (false === $result->succeeded || null === $result->content) {
            // Logged at error for the reason ThreadSummaryController logs its
            // failures there: this is the only record of a user-visible
            // operation that failed, and at warning it is removable by the
            // capture level an operator raises to quieten a busy install.
            $this->logger->error('Thread summary job failed', [
                'thread'           => $message->threadId,
                'kind'             => $result->errorKind,
                'model'            => $this->summariser->model(),
                'transcript_chars' => mb_strlen($transcript),
            ]);

            $this->notifier->finished($message->userId, $message->threadId, 'failed');

            return;
        }

        // HASHED OVER THE ORDINARY TRANSCRIPT, NOT THE ONE THAT WAS SENT, and
        // getting this backwards would have made the whole job pointless.
        //
        // The hash answers one question — has this conversation changed since
        // the summary was written — and it is answered by comparing against
        // whatever the READER's page computes when it opens the thread. That is
        // always the ordinary transcript, because the page has no idea a full
        // run ever happened. Store the full transcript's hash and the two can
        // never match: the summary arrives, and the card shows it greyed out as
        // out of date, for ever, with a button offering to write it again.
        //
        // Both derive from the same messages, so either is a faithful answer to
        // the question. Only one of them is the answer the reader will ask.
        $this->store->save(
            $message->threadId,
            $result->content,
            ThreadTranscript::hash($this->transcript->forThread($thread)),
            $this->summariser->model(),
            $this->summariser->promptFingerprint(),
            true,
        );

        $this->notifier->finished($message->userId, $message->threadId, 'ready');
    }
}
