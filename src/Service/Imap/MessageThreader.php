<?php

namespace App\Service\Imap;

use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MessageThreader
{
    /**
     * Reply/forward prefixes, in the languages this app is translated into plus
     * the ones common European clients emit. Used both to strip the prefix for
     * subject normalisation and to decide whether a message is a reply at all.
     */
    private const string REPLY_PREFIX_PATTERN = '/^\s*((re|fwd|fw|aw|wg|antw|sv|vs|res|rif|tr|doorst)\s*(\[\d+\])?\s*:\s*)+/i';

    /**
     * How far back the subject fallback may reach for a parent thread. Long
     * enough to cover a slow-moving conversation, short enough that a recurring
     * subject starts a fresh thread instead of extending a stale one.
     */
    private const string SUBJECT_FALLBACK_WINDOW = '-30 days';

    /** How many provider conversation ids to remember before starting over. */
    private const int PROVIDER_THREAD_CACHE_LIMIT = 500;

    /**
     * Threads already handed out for a provider conversation id, keyed by
     * "accountId|providerThreadKey". See providerThread().
     *
     * @var array<string, MessageThread>
     */
    private array $providerThreads = [];

    public function __construct(
        private readonly EntityManagerInterface  $entityManager,
        private readonly MessageRepository       $messageRepository,
        private readonly MessageThreadRepository $messageThreadRepository,
    )
    {
    }

    /**
     * Assigns a newly-synced message to a thread, creating one if needed.
     * Must be called after the message itself has its message_id, in_reply_to,
     * thread_references, subject, and addresses populated, but the entity
     * does not need to be persisted yet — this method will associate it
     * with a thread and the caller is responsible for the final flush.
     */
    public function assignThread(Message $message, Account $account): void
    {
        // 1. The provider's own conversation id, when there is one. Gmail and
        //    Graph have already grouped the conversation the way the user sees
        //    it in their web client; nothing we derive can beat that.
        $providerThreadKey = $message->providerThreadKey;

        if (null !== $providerThreadKey && '' !== $providerThreadKey) {
            $this->attachMessageToThread(
                $message,
                $this->providerThread($providerThreadKey, $account, $message),
            );

            return;
        }

        // 2. RFC 5322 References / In-Reply-To.
        $referenceIds = $this->collectReferenceIds($message);

        if (count($referenceIds) > 0) {
            $parentMessage = $this->messageRepository->findOneByMessageIdsForAccount($referenceIds, $account);

            if ($parentMessage !== null && $parentMessage->thread !== null) {
                $thread = $parentMessage->thread;
                $this->attachMessageToThread($message, $thread);

                return;
            }

            // Headers are valid even though no parent has been synced yet —
            // method is about what the message provides, not whether a match occurred.
            $thread = $this->createThread($message, $account, ThreadingMethod::References);
            $this->attachMessageToThread($message, $thread);

            return;
        }

        // 3. No usable references at all. Subject matching is the last resort and
        //    is deliberately narrow: it exists to rescue replies from clients that
        //    omit References, not to group unrelated mail that happens to share a
        //    subject line. A message that is not itself a reply always starts its
        //    own thread — otherwise every "Your order has shipped" notification
        //    ever received collapses into one endless conversation.
        if (true === $this->hasReplyPrefix($message->subject)) {
            $normalizedSubject = $this->normalizeSubject($message->subject);

            if ($normalizedSubject !== '') {
                $candidateThread = $this->messageThreadRepository->findMatchingNormalizedSubjectThreadForAccount(
                    $normalizedSubject,
                    $account,
                    new \DateTimeImmutable(self::SUBJECT_FALLBACK_WINDOW),
                );

                if ($candidateThread !== null && $this->participantsOverlap($message, $candidateThread)) {
                    $this->attachMessageToThread($message, $candidateThread);

                    return;
                }
            }
        }

        $thread = $this->createThread($message, $account, ThreadingMethod::SubjectFallback);
        $this->attachMessageToThread($message, $thread);
    }

    /**
     * The thread for a provider conversation id, created on first sight.
     *
     * The repository only sees what has been flushed, and a sync batch threads
     * every message it built before flushing once at the end. Two messages of
     * the same Gmail/Graph conversation in one batch therefore both missed the
     * lookup, each made a thread, and the second INSERT hit
     * uniq_message_thread_provider_key_account — taking the whole batch with
     * it. Threads handed out here are remembered for exactly as long as the
     * unit of work still holds them.
     */
    private function providerThread(string $providerThreadKey, Account $account, Message $message): MessageThread
    {
        $cacheKey = $account->id . '|' . $providerThreadKey;
        $pending  = $this->providerThreads[$cacheKey] ?? null;

        // A worker handles many messages, and the entity manager is cleared
        // between them — anything it no longer manages is a stale reference.
        if (null !== $pending && true === $this->entityManager->contains($pending)) {
            return $pending;
        }

        $thread = $this->messageThreadRepository->findOneByProviderThreadKeyForAccount($providerThreadKey, $account);

        if (null === $thread) {
            $thread = $this->createThread($message, $account, ThreadingMethod::Provider);
            $thread->providerThreadKey = $providerThreadKey;
        }

        // Long-running workers never stop adding keys, so the cache has to be
        // bounded — but only entries the repository lookup above can already
        // serve are safe to drop. A thread without an id is an unflushed
        // INSERT: evicting it mid-batch made the next message of the same
        // conversation create it a second time, and the batch-end flush died
        // on uniq_message_thread_provider_key_account.
        if (count($this->providerThreads) >= self::PROVIDER_THREAD_CACHE_LIMIT) {
            $this->providerThreads = array_filter(
                $this->providerThreads,
                static fn (MessageThread $cached): bool => null === $cached->id,
            );
        }

        $this->providerThreads[$cacheKey] = $thread;

        return $thread;
    }

    /**
     * @return string[] message-ids this message references, most specific first
     */
    private function collectReferenceIds(Message $message): array
    {
        // Normalised again on read, not just on write: rows synced before the
        // write paths were fixed still hold bracketed ids, and those have to keep
        // matching until the rethread backfill has run.
        return MessageIdHelper::normaliseList(array_merge(
            MessageIdHelper::normaliseList($message->inReplyTo),
            MessageIdHelper::normaliseList($message->references),
        ));
    }

    /**
     * Is this message a reply or forward, as far as its subject line admits?
     *
     * This is the gate on subject-based threading. Automated notifications never
     * carry a reply prefix, so they can never be merged into an existing thread.
     */
    public function hasReplyPrefix(?string $subject): bool
    {
        if (null === $subject) {
            return false;
        }

        return 1 === preg_match(self::REPLY_PREFIX_PATTERN, $subject);
    }

    public function normalizeSubject(?string $subject): string
    {
        if ($subject === null) {
            return '';
        }

        $normalized = trim($subject);

        // Strip repeated Re:/Fwd:/AW:/… prefixes, including spaced and counted
        // variants like "RE :", "Fwd:Fwd:" and "Re[2]:".
        $normalized = preg_replace(self::REPLY_PREFIX_PATTERN, '', $normalized);

        if ($normalized === null) {
            $normalized = trim($subject);
        }

        return mb_strtolower(trim($normalized));
    }
    /**
     * Keep a single-message draft thread's subject in step with the draft.
     * No-op once the thread holds anything else — a reply draft must never
     * rename the conversation it hangs off.
     */
    public function resyncDraftThreadSubject(Message $message): void
    {
        $thread = $message->thread;

        if (null === $thread) {
            return;
        }

        if ($thread->messageCount > 1) {
            return;
        }

        $thread->subject = $message->subject;
        $thread->normalizedSubject = $this->normalizeSubject($message->subject);
    }

    /**
     * Does this message share a participant with the thread?
     *
     * Sender *and* recipients are considered: a reply from someone who has not
     * posted before shares no sender with the thread, but addresses someone who
     * has. Comparing senders alone would reject exactly the replies the subject
     * fallback exists to catch.
     */
    private function participantsOverlap(Message $message, MessageThread $thread): bool
    {
        $addresses = [];

        $fromAddress = $message->fromAddress;

        if (null !== $fromAddress && '' !== $fromAddress) {
            $addresses[] = $fromAddress;
        }

        foreach ([$message->toAddresses, $message->ccAddresses] as $recipients) {
            foreach ($recipients ?? [] as $recipient) {
                $address = is_array($recipient) ? ($recipient['address'] ?? null) : $recipient;

                if (is_string($address) && '' !== $address) {
                    $addresses[] = $address;
                }
            }
        }

        if (0 === count($addresses)) {
            return false;
        }

        return $this->messageRepository->existsWithAnyFromAddressInThread($addresses, $thread);
    }

    private function createThread(Message $message, Account $account, ThreadingMethod $threadingMethod): MessageThread
    {
        $thread = new MessageThread();
        $thread->account = $account;
        $thread->subject = $message->subject;
        $thread->normalizedSubject = $this->normalizeSubject($message->subject);
        $thread->threadingMethod = $threadingMethod;
        $thread->messageCount = 0;
        $thread->unreadCount = 0;
        // Seed from the message that opens the thread; attachMessageToThread()
        // takes over from here. Primary only when the message is uncategorised,
        // which is the case for locally-composed drafts.
        $thread->category = $message->category ?? MessageCategory::Primary;
        $thread->attachmentCount = 0;

        $this->entityManager->persist($thread);

        return $thread;
    }
    private function attachMessageToThread(Message $message, MessageThread $thread): void
    {
        $message->thread = $thread;

        $thread->messageCount = $thread->messageCount + 1;

        foreach ($message->labels as $label) {
            $thread->addLabel($label);
        }

        if (null === $message->seenAt) {
            $thread->unreadCount = $thread->unreadCount + 1;
        }

        if (true === $message->hasAttachments) {
            $thread->attachmentCount = $thread->attachmentCount + 1;
        }

        $occurredAt = $message->receivedAt
            ?? $message->sentAt
            ?? $message->createdAt;

        if (null !== $occurredAt) {
            $currentLastMessageAt = $thread->lastMessageAt;

            if (null === $currentLastMessageAt || $occurredAt > $currentLastMessageAt) {
                $thread->lastMessageAt = $occurredAt;

                // Most-recent-wins, the same rule the category backfill applies in
                // SQL. Without this a thread would keep whatever category it was
                // created with and the inbox tabs — which filter on the thread, not
                // the message — would only ever move after a backfill run.
                $category = $message->category;

                if (null !== $category) {
                    $thread->category = $category;
                }
            }
        }
    }
}
