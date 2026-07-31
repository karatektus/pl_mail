<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Interface\PostIngestStepInterface;
use App\Entity\Mail\Account;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\ContactRepository;
use App\Service\Imap\MessageThreader;
use App\Service\Rule\MailRuleEngine;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Everything that happens to a batch of messages after they exist as rows:
 * sanitising, JMAP state, categorisation, threading, rules — in the one order
 * that is correct.
 *
 * The three sync paths used to carry a copy of this each. They agreed, which
 * was the problem: the ordering below is subtle enough that three copies is
 * three chances to diverge, and any feature wanting to react to new mail had to
 * be wired into all three. Now they build a list and call run().
 *
 * What the callers keep is their tail, because that genuinely differs — IMAP
 * publishes its Mercure update and dispatches contact harvesting one level up
 * in SyncImapMailboxMessageHandler, while Gmail and Graph do both inline. The
 * pipeline stops at the last flush and hands back a PostIngestResult.
 *
 * Two branches deliberately do not come through here: IMAP's Gmailify claim and
 * SyncGmailMessageBatchHandler::enrichExisting(). Both re-point a row that has
 * already been through the pipeline once, so running it again would re-record a
 * create for an id JMAP clients already know, and re-run rules over mail the
 * user may since have filed by hand.
 */
final readonly class PostIngestPipeline
{
    /**
     * @param iterable<PostIngestStepInterface> $steps
     */
    public function __construct(
        private ContactRepository      $contactRepository,
        private MailBodySanitizer      $sanitizer,
        private RawMessageResolver     $rawResolver,
        private MessageCategorizer     $categorizer,
        private MessageThreader        $messageThreader,
        private MailRuleEngine         $ruleEngine,
        private StateManager           $stateManager,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
        #[AutowireIterator('app.post_ingest_step')]
        private iterable               $steps,
    ) {
    }

    /**
     * Runs the shared post-ingest pass over one batch.
     *
     * PRECONDITION: the caller has already persisted and flushed every message.
     * Ids must exist before threading queries them, and MailRuleEngine matches
     * in SQL — against search_vector, a generated column — so a message that
     * has not reached the database is invisible to the user's own rules.
     *
     * Runs its flushes even for an empty batch. IMAP calls this after updating
     * the mailbox's last-seen UID, and that write has to land whether or not
     * this particular batch turned out to hold anything new.
     *
     * @param Account                $carrier  the account that fetched the batch;
     *                                         rules are the carrier's, threading and
     *                                         JMAP state are the owning account's
     * @param list<IngestedMessage>  $ingested already persisted and flushed
     */
    public function run(Account $carrier, array $ingested): PostIngestResult
    {
        $user = $carrier->getUsr();

        $correspondents = null !== $user
            ? $this->contactRepository->findCorrespondentEmails($user)
            : [];

        $messages    = [];
        $accounts    = [];
        $ruleTargets = [];

        foreach ($ingested as $item) {
            $message   = $item->message;
            $accountId = (int) $item->account->getId();

            $this->sanitizer->sanitize($message);

            // Store the original bytes now that the row has an id. Only IMAP
            // gets these for free; the API providers pass null and
            // RawMessageResolver fetches on first use instead.
            if (null !== $item->rawSource) {
                $this->rawResolver->store($message, $item->rawSource);
            }

            // JMAP state: the ids exist after the caller's flush. record() only
            // persists, so these rows ride along on the flush below.
            $this->stateManager->recordCreated(
                $accountId,
                JmapObjectType::Email,
                (string) $message->getId(),
            );

            $message->setCategory($this->categorizer->categorize($message, $correspondents));

            try {
                $this->messageThreader->assignThread($message, $item->account);
            } catch (\Throwable $e) {
                $this->logger->error('PostIngest: threading failed', [
                    'messageId' => $message->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }

            $messages[]           = $message;
            $accounts[$accountId] = $item->account;
            $ruleTargets[]        = $message;
        }

        // One query per rule for the whole batch, after threading so archive
        // and trash actions can reach each message's thread.
        $this->ruleEngine->applyToBatch($ruleTargets, $carrier);

        // Threads exist only after assignThread() above, so this runs as a
        // second pass rather than inside the loop — and only after the flush,
        // which is where a thread created moments ago gets its id. Reading
        // them before it published every new thread to JMAP clients as id 0.
        $this->em->flush();

        $threadIdsByAccount = [];

        foreach ($ingested as $item) {
            $thread = $item->message->getThread();

            if (null !== $thread) {
                $threadIdsByAccount[(int) $item->account->getId()][] = (int) $thread->getId();
            }
        }

        foreach ($threadIdsByAccount as $threadAccountId => $threadIds) {
            $this->stateManager->recordThreadsTouched($threadAccountId, $threadIds);
        }

        // The change-log rows recorded just now.
        $this->em->flush();

        $result = new PostIngestResult($messages, $accounts, $threadIdsByAccount);

        $this->notifySteps($result);

        return $result;
    }

    /**
     * Steps run last, individually guarded. A step exists to queue follow-up
     * work; whatever it throws is its own problem, and must not cost the
     * mailbox the sync that has already succeeded.
     */
    private function notifySteps(PostIngestResult $result): void
    {
        if (true === $result->isEmpty()) {
            return;
        }

        foreach ($this->steps as $step) {
            try {
                $step->afterCommit($result);
            } catch (\Throwable $e) {
                $this->logger->error('PostIngest: step failed', [
                    'step'  => $step::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
