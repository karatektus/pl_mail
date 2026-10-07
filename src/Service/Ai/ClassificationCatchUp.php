<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Ai\AiFeature;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ClassifyMailMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Monitoring\MessengerQueueRepository;
use App\Service\Mail\InitialImportState;
use App\Service\Mail\PostIngest\EnrichmentRouter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Asks the assistant about old mail, a bounded handful at a time, once an
 * import is over.
 *
 * WHY OLD MAIL IS NOT ASKED ABOUT AS IT IS IMPORTED
 * ─────────────────────────────────────────────────
 * It used to be: every message of a fifty-thousand-message mailbox was queued
 * for a model call the moment it was ingested, oldest first, on the queue that
 * fetches mail. Hours of somebody's GPU spent on newsletters from 2019 before
 * last week's mail had been looked at, with new mail waiting behind all of it.
 *
 * So the import classifies only what is recent (RecentMailPolicy), and this
 * does the rest afterwards: newest first, so the mail somebody is most likely
 * to scroll to is done soonest and stopping anywhere leaves the useful end
 * finished; in the background, on the queue behind every other; and not at all
 * until the import has finished, because a mailbox still arriving is a moving
 * target and its own recent mail has first call on the model.
 *
 * WHAT THIS IS, AND IS MODELLED ON: EmbeddingCatchUp::sweep(). A budget per
 * call, no state of its own, nothing to resume — the message's own
 * `aiCategorisedAt` stamp is the progress marker, written by the handler
 * whether or not the model said anything usable.
 *
 * WHAT STANDS IN FOR STATE is the queue itself. A run posts nothing while its
 * queue still holds what the last run posted, which is all that keeps two
 * sweeps from queueing the same ids twice — and also makes the pace
 * self-adjusting: a slow host is simply topped up less often.
 *
 * IT DISPATCHES, IT DOES NOT ASK. ClassifyMailHandler already checks the
 * feature and the owner again, already skips what is stamped, already re-files
 * what it touches and already stands aside for live mail. A second path that
 * called the model itself would re-implement all four.
 */
final readonly class ClassificationCatchUp
{
    public function __construct(
        private MessageRepository        $messages,
        private AccountRepository        $accounts,
        private AiPermissions            $permissions,
        private InitialImportState       $importState,
        private InteractiveAiActivity    $interactive,
        private BackfillPolicy           $policy,
        private MessengerQueueRepository $queues,
        private MessageBusInterface      $bus,
        private LoggerInterface          $logger,
    ) {
    }

    /**
     * Whether a sweep may post anything at all right now.
     *
     * Asked once per run by the command rather than once per mailbox: neither
     * answer depends on whose mail it is.
     */
    public function mayRun(): bool
    {
        // Somebody is using the composer or a summary. Those share the chat
        // model with classification and are what a person is actually waiting
        // on; the backlog is nobody's foreground. Same signal, same cooldown,
        // as the embedding backfill.
        if (true === $this->interactive->shouldYield($this->policy->cooldownSeconds)) {
            return false;
        }

        try {
            return 0 === $this->queues->countWaitingOn(EnrichmentRouter::BACKLOG);
        } catch (Throwable $exception) {
            // A transport whose table this cannot read — anything but the
            // doctrine one. Without the depth there is nothing to stop a run
            // re-posting what the last one posted, so it does not run; the
            // feature degrades to "old mail keeps the rules' answer", which is
            // what it had before this class existed.
            $this->logger->warning('ClassificationCatchUp: could not read the backlog queue, not sweeping', [
                'error'     => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return false;
        }
    }

    /**
     * Queue the newest unasked mail of one person's.
     *
     * @return int how many messages were queued
     */
    public function sweep(User $user, int $limit): int
    {
        $userId = (int) $user->id;

        if ($userId <= 0 || $limit <= 0) {
            return 0;
        }

        if (false === $this->permissions->allows($user, AiFeature::Categorise)) {
            return 0;
        }

        // ALL of this person's accounts, not each on its own. Their mail is
        // one inbox to them and one queue to the model, and starting on the
        // finished account's history while another is still importing would
        // put old mail in front of that import's recent mail.
        $accounts = $this->accounts->findBy(['usr' => $user, 'isActive' => true]);

        if (false === $this->importState->allComplete($accounts)) {
            return 0;
        }

        $ids = $this->messages->unclassifiedIdsForUser($userId, $limit);

        if ([] === $ids) {
            return 0;
        }

        // CHUNKED BY BackfillPolicy::$batchSize, the tuned answer to "how long
        // may one job hold the host before something else gets a turn".
        foreach (array_chunk($ids, $this->policy->batchSize) as $chunk) {
            $this->bus->dispatch(new ClassifyMailMessage(array_values($chunk)), EnrichmentRouter::backlog());
        }

        $this->logger->info('ClassificationCatchUp: queued old mail for the assistant', [
            'userId'   => $userId,
            'messages' => count($ids),
        ]);

        return count($ids);
    }
}
