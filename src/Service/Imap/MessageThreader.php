<?php

namespace App\Service\Imap;

use App\Domain\Enum\MessageTab;
use App\Domain\Enum\ThreadingMethod;
use App\Entity\Account;
use App\Entity\Message;
use App\Entity\MessageThread;
use App\Repository\MessageRepository;
use App\Repository\MessageThreadRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MessageThreader
{
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

        // No usable references at all — fall back to subject + participant matching.
        $normalizedSubject = $this->normalizeSubject($message->getSubject());

        if ($normalizedSubject !== '') {
            $candidateThread = $this->messageThreadRepository->findMatchingNormalizedSubjectThreadForAccount(
                $normalizedSubject,
                $account,
            );

            if ($candidateThread !== null && $this->participantsOverlap($message, $candidateThread)) {
                $this->attachMessageToThread($message, $candidateThread);

                return;
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
        $ids = [];

        $inReplyTo = $message->getInReplyTo();

        if (is_array($inReplyTo)) {
            foreach ($inReplyTo as $id) {
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }

        $references = $message->getReferences();

        if (is_array($references)) {
            foreach ($references as $id) {
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function normalizeSubject(?string $subject): string
    {
        if ($subject === null) {
            return '';
        }

        $normalized = trim($subject);

        // Strip repeated Re:/Fwd:/Fw: prefixes, including localized/spaced variants
        // like "RE :" or "Fwd:Fwd:".
        $pattern = '/^\s*((re|fwd|fw)\s*:\s*)+/i';
        $normalized = preg_replace($pattern, '', $normalized);

        if ($normalized === null) {
            $normalized = trim($subject);
        }

        $normalized = mb_strtolower(trim($normalized));

        return $normalized;
    }

    private function participantsOverlap(Message $message, MessageThread $thread): bool
    {
        $fromAddress = $message->getFromAddress();

        if ($fromAddress === null || $fromAddress === '') {
            return false;
        }

        return $this->messageRepository->existsWithFromAddressInThread($fromAddress, $thread);
    }

    private function createThread(Message $message, Account $account, ThreadingMethod $threadingMethod): MessageThread
    {
        $thread = new MessageThread()
            ->setAccount($account)->setSubject($message->getSubject())->setNormalizedSubject($this->normalizeSubject($message->getSubject()))
            ->setThreadingMethod($threadingMethod)
            ->setMessageCount(0)
            ->setUnreadCount(0)
            ->setTab(MessageTab::Primary)
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

        $receivedAt = $message->getReceivedAt();

        if (null !== $receivedAt ) {
            $currentLastMessageAt = $thread->getLastMessageAt();

            if (null !== $currentLastMessageAt || $receivedAt > $currentLastMessageAt) {
                $thread->setLastMessageAt($receivedAt);
            }
        }
    }
}
