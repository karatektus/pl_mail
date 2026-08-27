<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Ai\BackfillPauseReason;
use App\Entity\Ai\AiFeature;
use App\Infrastructure\Messaging\Message\BackfillEmbeddingsMessage;
use App\Repository\Ai\AiBackfillStateRepository;
use App\Repository\Ai\AiSettingsRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\User\UserRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\AiPermissions;
use App\Service\Ai\BackfillPolicy;
use App\Service\Ai\EmbeddingStore;
use App\Service\Ai\InteractiveAiActivity;
use App\Service\Ai\MessageEmbedder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Walks one mailbox, a chunk at a time, embedding what has not been embedded.
 *
 * RE-DISPATCHES ITSELF RATHER THAN LOOPING
 * ────────────────────────────────────────
 * A hundred thousand messages at a round trip each is hours. A single job
 * holding that would be killed by any worker restart with nothing to show for
 * it and would begin again from the start; a loop inside one handler would hold
 * a database connection and a transport lease for the whole time.
 *
 * So each delivery does a chunk, records nothing but the vectors themselves,
 * and posts the next cursor. Interrupting it at any point loses at most one
 * chunk, and starting it again resumes from wherever the vectors stop — the
 * work already done IS the progress record, which is why there is no separate
 * one to keep in step.
 *
 * ASCENDING ID, NOT DATE
 * ──────────────────────
 * The one ordering nothing can change underneath a long walk. Mail arriving
 * during a backfill gets a higher id and is met by the pass still coming; mail
 * deleted during one is simply absent. A date cursor would have to cope with
 * both, and with two messages sharing a timestamp.
 *
 * IT GETS OUT OF THE WAY, AND THAT IS THE POINT OF THE DELAYS
 * ──────────────────────────────────────────────────────────
 * Backfill and the composer want the same GPU. A click that lands while a batch
 * is in flight waits behind it, and "I pressed the button and nothing happened"
 * is the complaint this whole arrangement exists to remove — so before every
 * batch this asks whether anybody is using the AI, and if they are, it posts
 * itself back with a delay instead of doing any work. Nothing is lost: the same
 * cursor comes back a minute later and does the same chunk.
 *
 * The batch size and the gap between batches are configurable and timid by
 * default; see BackfillPolicy, which also records why the two models do not
 * evict each other on the target hardware.
 *
 * ai_backfill_state IS INTENT, NOT PROGRESS
 * ─────────────────────────────────────────
 * The row this writes says whether a walk is meant to be going, where each
 * mailbox got to, and what stopped it. It does not count anything: the panel's
 * percentage is a query against message_embedding, so a worker killed mid-chunk
 * resumes rather than restarts, and the number is right even for a backfill
 * that ran last week and was forgotten.
 */
#[AsMessageHandler]
final readonly class BackfillEmbeddingsHandler
{
    public function __construct(
        private UserRepository            $users,
        private MessageRepository         $messages,
        private MessageEmbedder           $embedder,
        private EmbeddingStore            $store,
        private AiAssistant               $ai,
        private AiPermissions             $permissions,
        private AiSettingsRepository      $settings,
        private AiBackfillStateRepository $state,
        private InteractiveAiActivity     $activity,
        private BackfillPolicy            $policy,
        private MessageBusInterface       $bus,
        private EntityManagerInterface    $entityManager,
        private LoggerInterface           $logger,
    ) {
    }

    public function __invoke(BackfillEmbeddingsMessage $message): void
    {
        $now = new DateTimeImmutable();
        $run = $this->state->current();

        // Asked every chunk, so Pause in the admin panel stops the walk within
        // one chunk rather than at the end of a mailbox — and so does a run
        // that has already been marked complete or failed.
        if (false === $run->isLive()) {
            $this->logger->info('BackfillEmbeddings: stopping, the run is not live', [
                'userId' => $message->userId,
                'status' => $run->status->value,
                'reason' => $run->pauseReason?->value,
            ]);

            return;
        }

        // A delivery from a chain that was superseded — a resume that raced an
        // in-flight chunk, most often. Dropped rather than run: it would do work
        // the live chain is already past, at the cost of requests to the one
        // machine that is the bottleneck. Behind the recorded cursor is the only
        // detectable case, and it is the common one.
        $cursor = $run->cursorFor($message->userId);

        if (null !== $message->afterMessageId && null !== $cursor && $message->afterMessageId < $cursor) {
            $this->logger->info('BackfillEmbeddings: dropping a superseded chunk', [
                'userId'   => $message->userId,
                'delivered' => $message->afterMessageId,
                'recorded'  => $cursor,
            ]);

            return;
        }

        // Every chunk asks again, so switching search off stops the walk within
        // one chunk. Recorded as a pause with a reason rather than a silent
        // return: "it stopped because you switched it off" is a sentence the
        // panel can say, and "it stopped" is not.
        if (false === $this->ai->isEnabledFor(AiFeature::Search)) {
            $this->state->pause(BackfillPauseReason::FeatureOff, $now);

            $this->logger->info('BackfillEmbeddings: stopping, the feature is off', [
                'userId' => $message->userId,
            ]);

            return;
        }

        // Somebody is using the AI. Step aside and come back — before any work,
        // because the whole value of this is not being in front of them.
        if (true === $this->activity->shouldYield($this->policy->cooldownSeconds, $now)) {
            $this->state->yieldFor(BackfillPauseReason::Interactive, $now);

            $this->postNext($message->userId, $message->afterMessageId, $this->activity->secondsUntilQuiet($this->policy->cooldownSeconds, $now) * 1000);

            return;
        }

        // Resolved once per DELIVERY and never per message, which matters
        // because $this->entityManager->clear() runs further down: the object
        // below is evicted at the end of every chunk and found again by the
        // next one.
        $user = $this->users->find($message->userId);

        if (null === $user) {
            // The mailbox went away mid-run — a user deleted while a backfill
            // was walking. Marked finished rather than left pending, or the run
            // could never reach "complete".
            $this->state->recordChunk($message->userId, $message->afterMessageId, true, 0, $now);
            $this->finishIfDone($now);

            return;
        }

        if (false === $this->permissions->allows($user, AiFeature::Search)) {
            // Finished, not pending — the same answer the deleted mailbox above
            // gets, and for the same reason: a run that leaves a mailbox pending
            // can never reach "complete", so the panel would show a walk stuck
            // at 97% forever.
            //
            // NOT a pause. The installation-wide check higher up pauses the
            // whole run with a reason, because that is one fact about the
            // installation; this is one mailbox out of many opting out, and
            // stopping everybody's backfill for it would be the wrong scope.
            // Its own log line, because the two are different facts.
            $this->state->recordChunk($message->userId, $message->afterMessageId, true, 0, $now);
            $this->finishIfDone($now);

            $this->logger->info('BackfillEmbeddings: skipping a mailbox whose owner has search switched off', [
                'userId' => $message->userId,
            ]);

            return;
        }

        $ids = $this->messages->idsForUserAfter($message->userId, $message->afterMessageId, $this->policy->batchSize);

        if ([] === $ids) {
            $this->state->recordChunk($message->userId, $message->afterMessageId, true, 0, $now);
            $this->finishIfDone($now);

            $this->logger->info('BackfillEmbeddings: finished a mailbox', [
                'userId'   => $message->userId,
                'embedded' => $this->store->countFor((string) $this->settings->currentOrDefault()->embeddingModel),
            ]);

            return;
        }

        $model   = (string) $this->settings->currentOrDefault()->embeddingModel;
        $done    = $this->store->alreadyStored($ids, $model);
        $pending = array_values(array_diff($ids, $done));
        $stored  = 0;

        if ([] !== $pending) {
            $stored = $this->embedder->embedAll($user, $this->messages->findByIds($pending));
        }

        // A whole chunk that stored nothing, with work to do, is a host that is
        // not answering — one message can fail for its own reasons, fifty in a
        // row cannot. Retried on a long delay rather than failed outright,
        // because a host that is rebooting comes back and a backfill that gave
        // up on the first blink would need restarting by hand every time.
        if ([] !== $pending && 0 === $stored) {
            $inARow = $this->state->noteEmptyChunk($now);

            $this->entityManager->clear();

            if ($inARow >= $this->policy->maxEmptyChunks) {
                $this->state->markFailed('no_answer', $now);

                $this->logger->warning('BackfillEmbeddings: giving up, the host answered nothing', [
                    'userId'   => $message->userId,
                    'attempts' => $inARow,
                ]);

                return;
            }

            $this->state->yieldFor(BackfillPauseReason::HostUnreachable, $now);

            // The SAME cursor: nothing was stored, so there is nothing to step
            // past. Advancing here would silently skip a chunk of the mailbox
            // every time the host blinked.
            $this->postNext($message->userId, $message->afterMessageId, $this->policy->retrySeconds * 1000);

            return;
        }

        // The cursor advances past everything LOOKED AT, not everything stored.
        // A message the model could not answer for must not become a wall the
        // walk restarts at forever; the next full run picks it up because
        // nothing was written for it.
        $cursor = max($ids);

        $this->state->recordChunk($message->userId, $cursor, false, count($pending) - $stored, $now);

        // Cleared before posting the next chunk: this handler has walked a
        // batch of entities it will never look at again, and a long walk that
        // keeps every one is killed for memory rather than finishing.
        $this->entityManager->clear();

        $this->postNext($message->userId, $cursor, $this->policy->pauseMs);
    }

    /**
     * The next chunk, after a deliberate gap.
     *
     * DelayStamp rather than a sleep: a sleeping handler holds a worker and a
     * transport lease doing nothing, and on a queue that also carries rule runs
     * and admin sweeps that is a worker nobody else can have. A delayed message
     * costs a row's available_at and frees the process entirely.
     */
    private function postNext(int $userId, ?int $afterMessageId, int $delayMs): void
    {
        $envelope = new BackfillEmbeddingsMessage($userId, $afterMessageId);

        $this->bus->dispatch(
            $envelope,
            $delayMs > 0 ? [new DelayStamp($delayMs)] : [],
        );
    }

    /** The run is complete once every mailbox in it has been walked to the end. */
    private function finishIfDone(DateTimeImmutable $now): void
    {
        if (true === $this->state->current()->everyMailboxFinished()) {
            $this->state->markComplete($now);
        }
    }
}
