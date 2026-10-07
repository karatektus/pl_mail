<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Interface\PostIngestStepInterface;
use App\Entity\Mail\Account;
use App\Repository\Mail\ContactRepository;
use App\Service\Imap\MessageThreader;
use App\Service\Rule\MailRuleEngine;
use DateTimeImmutable;
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
        private InitialImportState     $importState,
        private ClassificationHold     $hold,
        private MessageThreader        $messageThreader,
        private MailRuleEngine         $ruleEngine,
        private MailChangeRecorder     $changes,
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
        $user = $carrier->usr;

        $correspondents = null !== $user
            ? $this->contactRepository->findCorrespondentEmails($user)
            : [];

        $messages    = [];
        $accounts    = [];
        $ruleTargets = [];

        // Is this mail trickling in, or a mailbox being imported? Asked once,
        // before the loop, because two things below turn on it and both have
        // to agree: whether a message is held back for the assistant, and
        // which queue the steps put its follow-up work on. See
        // InitialImportState for what "imported" means per provider.
        $owners = [];

        foreach ($ingested as $item) {
            $owners[(int) $item->account->id] = $item->account;
        }

        $live    = $this->importState->allComplete($owners);
        $holding = true === $live && true === $this->hold->isOnFor($user);

        foreach ($ingested as $item) {
            $message   = $item->message;
            $accountId = (int) $item->account->id;

            $this->sanitizer->sanitize($message);

            // Store the original bytes now that the row has an id. Only IMAP
            // gets these for free; the API providers pass null and
            // RawMessageResolver fetches on first use instead.
            if (null !== $item->rawSource) {
                $this->rawResolver->store($message, $item->rawSource);
            }

            // JMAP state: the ids exist after the caller's flush. Recording
            // only persists, so these rows ride along on the flush below.
            //
            // No thread yet on purpose. assignThread() runs below and a thread
            // it creates has no id until the flush after this loop, so the
            // conversations are announced in a second pass down there instead.
            $this->changes->emailChanged(
                $accountId,
                (string) $message->id,
                created: true,
                thread: null,
            );

            $message->category = $this->categorizer->categorize($message, $correspondents, $user?->categorySorting);

            // BEFORE THREADING, because the thread is where a hold takes
            // effect: assignThread() gives a held message's new thread no
            // category, and every tab query already reads that as "in no tab".
            // The category written one line up stays — it is the rules' answer,
            // and it is what the message is filed under if the model never
            // replies. See ClassificationHold and Message::$categoryHeldAt.
            if (true === $holding
                && true === $this->hold->shouldHold($message, $item->account, $correspondents, $user?->categorySorting)) {
                $message->categoryHeldAt = new DateTimeImmutable();
            }

            try {
                $this->messageThreader->assignThread($message, $item->account);
            } catch (\Throwable $e) {
                $this->logger->error('PostIngest: threading failed', [
                    'messageId' => $message->id,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
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
            $thread = $item->message->thread;

            if (null !== $thread) {
                $threadIdsByAccount[(int) $item->account->id][] = (int) $thread->id;
            }
        }

        foreach ($threadIdsByAccount as $threadAccountId => $threadIds) {
            $this->changes->threadsTouched($threadAccountId, $threadIds);
        }

        // The change-log rows recorded just now.
        $this->em->flush();

        $result = new PostIngestResult($messages, $accounts, $threadIdsByAccount, $live);

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
                    'step'      => $step::class,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }
}
