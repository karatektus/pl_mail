<?php

namespace App\Service\Imap;

use App\Domain\Enum\MessageCategory;
use App\Domain\Enum\ThreadingMethod;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Account;
use App\Entity\Message;
use App\Entity\MessageThread;
use App\Repository\MessageRepository;
use App\Repository\MessageThreadRepository;
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
        $providerThreadKey = $message->getProviderThreadKey();

        if (null !== $providerThreadKey && '' !== $providerThreadKey) {
            $thread = $this->messageThreadRepository->findOneByProviderThreadKeyForAccount($providerThreadKey, $account)
                ?? $this->createThread($message, $account, ThreadingMethod::Provider)
                    ->setProviderThreadKey($providerThreadKey);

            $this->attachMessageToThread($message, $thread);

            return;
        }

        // 2. RFC 5322 References / In-Reply-To.
        $referenceIds = $this->collectReferenceIds($message);

        if (count($referenceIds) > 0) {
            $parentMessage = $this->messageRepository->findOneByMessageIdsForAccount($referenceIds, $account);

            if ($parentMessage !== null && $parentMessage->getThread() !== null) {
                $thread = $parentMessage->getThread();
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
        if (true === $this->hasReplyPrefix($message->getSubject())) {
            $normalizedSubject = $this->normalizeSubject($message->getSubject());

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
     * @return string[] message-ids this message references, most specific first
     */
    private function collectReferenceIds(Message $message): array
    {
        // Normalised again on read, not just on write: rows synced before the
        // write paths were fixed still hold bracketed ids, and those have to keep
        // matching until the rethread backfill has run.
        return MessageIdHelper::normaliseList(array_merge(
            MessageIdHelper::normaliseList($message->getInReplyTo()),
            MessageIdHelper::normaliseList($message->getReferences()),
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
        $thread = $message->getThread();

        if (null === $thread) {
            return;
        }

        if ($thread->getMessageCount() > 1) {
            return;
        }

        $thread
            ->setSubject($message->getSubject())
            ->setNormalizedSubject($this->normalizeSubject($message->getSubject()));
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

        $fromAddress = $message->getFromAddress();

        if (null !== $fromAddress && '' !== $fromAddress) {
            $addresses[] = $fromAddress;
        }

        foreach ([$message->getToAddresses(), $message->getCcAddresses()] as $recipients) {
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
        $thread = new MessageThread()
            ->setAccount($account)->setSubject($message->getSubject())->setNormalizedSubject($this->normalizeSubject($message->getSubject()))
            ->setThreadingMethod($threadingMethod)
            ->setMessageCount(0)
            ->setUnreadCount(0)
            ->setCategory(MessageCategory::Primary)
            ->setAttachmentCount(0);

        $this->entityManager->persist($thread);

        return $thread;
    }
    private function attachMessageToThread(Message $message, MessageThread $thread): void
    {
        $message->setThread($thread);

        $thread->setMessageCount($thread->getMessageCount() + 1);

        foreach ($message->getLabels() as $label) {
            $thread->addLabel($label);
        }

        if (null === $message->getSeenAt()) {
            $thread->setUnreadCount($thread->getUnreadCount() + 1);
        }

        if (true === $message->hasAttachments()) {
            $thread->setAttachmentCount($thread->getAttachmentCount() + 1);
        }

        $occurredAt = $message->getReceivedAt()
            ?? $message->getSentAt()
            ?? $message->getCreatedAt();

        if (null !== $occurredAt) {
            $currentLastMessageAt = $thread->getLastMessageAt();

            if (null === $currentLastMessageAt || $occurredAt > $currentLastMessageAt) {
                $thread->setLastMessageAt($occurredAt);
            }
        }
    }
}
