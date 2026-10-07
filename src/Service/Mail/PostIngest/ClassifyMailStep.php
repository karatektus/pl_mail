<?php

declare(strict_types=1);

namespace App\Service\Mail\PostIngest;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Interface\PostIngestStepInterface;
use App\Entity\Ai\AiFeature;
use App\Infrastructure\Messaging\Message\ClassifyMailMessage;
use App\Infrastructure\Messaging\Message\ReleaseHeldMailMessage;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\BackfillPolicy;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Queues a second opinion for a freshly ingested batch — when anybody asked
 * for one.
 *
 * Dispatches and returns, which is the whole contract: this runs on a worker
 * holding an IMAP connection or a Graph rate-limit budget, and a language model
 * on another machine is the last thing that belongs inside a sync.
 *
 * THREE KINDS OF MAIL LEAVE HERE DIFFERENTLY
 * ──────────────────────────────────────────
 *   HELD mail — kept out of the inbox tabs until the assistant answers, see
 *   Message::$categoryHeldAt — goes out one message to an envelope on the
 *   live queue, with a delayed release queued elsewhere beside it. One each, because somebody
 *   is waiting on every one of them separately and a batch would make the
 *   first wait for the last.
 *
 *   RECENT mail goes out in small chunks, on the live queue if it is
 *   trickling in and the shared one if it is part of an import. Small because
 *   a chunk is how long the worker is deaf to anything else: this used to
 *   post the whole batch as one envelope, fifty or a hundred model calls, and
 *   that envelope was minutes long.
 *
 *   OLD mail is not queued at all. It keeps the rules' answer, which is the
 *   one it would have been shown under anyway, and is asked about later, in
 *   the background, once the import is over. See RecentMailPolicy and
 *   App\Service\Ai\ClassificationCatchUp.
 *
 * THE GUARD IS HERE AND NOT ONLY IN THE HANDLER
 * ─────────────────────────────────────────────
 * Checking twice looks redundant and is not. Almost every installation has this
 * switched off, and without this check every one of them would enqueue a job
 * per arriving batch for a handler whose entire body is `return`. That is a
 * queue that fills, a transport that has to drain it, and a failure surface
 * that exists only for people who declined the feature.
 *
 * The handler checks again because settings can change between dispatch and
 * delivery, and because a job already on the queue when somebody switches the
 * feature off must not still run.
 */
final readonly class ClassifyMailStep implements PostIngestStepInterface
{
    public function __construct(
        private MessageBusInterface  $bus,
        private AiAssistant          $ai,
        private AiSettingsRepository $settings,
        private RecentMailPolicy     $recent,
        private BackfillPolicy       $policy,
        private EnrichmentRouter     $router,
    ) {
    }

    public function afterCommit(PostIngestResult $result): void
    {
        $held   = [];
        $recent = [];

        foreach ($result->messages as $message) {
            $id = $message->id;

            if (null === $id) {
                continue;
            }

            if (true === $message->isCategoryHeld()) {
                $held[] = (int) $id;

                continue;
            }

            if (true === $this->recent->isRecent($message)) {
                $recent[] = (int) $id;
            }
        }

        // BEFORE THE FEATURE CHECK, and unconditionally. A held message is
        // invisible in the tabs until something releases it, so the release is
        // queued whatever else is true — including the feature having been
        // switched off in the moment between the pipeline holding the message
        // and this line. Holding mail and then declining to queue its way out
        // is the one outcome here that loses mail from view.
        if ([] !== $held) {
            $this->bus->dispatch(new ReleaseHeldMailMessage($held), [
                new DelayStamp($this->settings->currentOrDefault()->holdSeconds() * 1000),
            ]);
        }

        if (false === $this->ai->isEnabledFor(AiFeature::Categorise)) {
            return;
        }

        foreach ($held as $id) {
            $this->bus->dispatch(new ClassifyMailMessage([$id]), EnrichmentRouter::live());
        }

        // WHICH of these are worth asking about is the handler's decision, not
        // this one — it needs the owner's own preference, which is a query, and
        // a query does not belong in a step that runs inside a sync.
        foreach (array_chunk($recent, $this->policy->batchSize) as $chunk) {
            $this->bus->dispatch(new ClassifyMailMessage(array_values($chunk)), $this->router->stampsFor($result));
        }
    }
}
