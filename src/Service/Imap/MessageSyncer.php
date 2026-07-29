<?php

namespace App\Service\Imap;

use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\MessageIdHelper;
use App\Domain\Helper\MimeHeaderHelper;
use App\Entity\Mailbox;
use App\Entity\Message;
use App\Entity\MessagePart;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\ContactRepository;
use App\Repository\MailboxRepository;
use App\Repository\MessageRepository;
use App\Service\Mail\InlineAttachmentDetector;
use App\Service\Mail\MailBodySanitizer;
use App\Service\Mail\MessageCategorizer;
use App\Service\Mail\RawMessageResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message as ImapMessage;
use App\Service\Rule\MailRuleEngine;
use App\Service\Mail\HeaderNormalizer;

class MessageSyncer
{
    private const int BATCH_SIZE = 50;

    public function __construct(
        private readonly AttachmentStorageHelper $attachmentStorage,
        private readonly MailboxRepository       $mailboxRepository,
        private readonly EntityManagerInterface  $em,
        private readonly LoggerInterface         $logger,
        private readonly MessageThreader         $messageThreader,
        private readonly MessageRepository       $messageRepository,
        private readonly MailBodySanitizer       $sanitizer,
        private readonly MessageCategorizer      $categorizer,
        private readonly ContactRepository       $contactRepository,
        private readonly StateManager            $stateManager,
        private readonly RawMessageResolver      $rawResolver,
        private readonly InlineAttachmentDetector $inlineDetector,
        private readonly MailRuleEngine $ruleEngine,
        private readonly HeaderNormalizer $headerNormalizer,
    ) {}

    public function syncMailbox(Mailbox $mailbox, Client $client): void
    {
        $mailboxId   = $mailbox->getId();
        $accountId   = $mailbox->getAccount()->getId();
        $lastSeenUid = $mailbox->getLastSeenUid() ?? 0;
        $uidRange    = ($lastSeenUid + 1) . ':*';
        $limit       = $mailbox->getAccount()->getSyncLimit();

        $this->logger->info('Syncing mailbox', [
            'mailbox'     => $mailbox->getFullPath(),
            'account'     => $accountId,
            'lastSeenUid' => $lastSeenUid,
            'limit'       => 0 === $limit ? 'none' : $limit,
        ]);

        $folder = $client->getFolder($mailbox->getName());

        if (null === $folder) {
            $this->logger->error('Folder not found', ['mailbox' => $mailbox->getName()]);
            return;
        }

        if (true === ($limit > 0)) {
            $uidRange = $this->cappedUidRange($folder, $uidRange, $limit);
        }

        // Load all already-synced UIDs up front so each batch can O(1)-skip them.
        // array_flip turns [123, 456, …] into [123 => 0, 456 => 1, …].
        $syncedUids = array_flip(
            $this->messageRepository->findSyncedUids($mailbox)
        );

        $synced = 0;

        $folder->messages()
            ->where('UID', $uidRange)
            ->chunked(function ($batch) use ($mailboxId, $accountId, &$synced, &$syncedUids) {
                $this->processBatch($batch, $mailboxId, $accountId, $syncedUids);
                $synced += count($batch);
                $this->em->clear();
                $this->logger->info(sprintf('Synced %d messages so far', $synced));
            }, self::BATCH_SIZE);

        $mailbox = $this->mailboxRepository->find($mailboxId);
        $mailbox->setSyncedAt(new DateTimeImmutable());
        $mailbox->setUnreadMessages($this->messageRepository->countUnseenForMailbox($mailbox));
        $mailbox->setTotalMessages($this->messageRepository->countTotalForMailbox($mailbox));
        $this->em->flush();
    }

    /**
     * Narrow a UID range to the newest $limit messages in the folder.
     *
     * Costs one extra SEARCH, which returns UIDs only — no headers or bodies —
     * so the saving on a large backlog is worth the round-trip. Falls back to
     * the original range if the search fails: a slow full sync beats no sync.
     *
     * The cap applies per run, not just to the first one. A mailbox that gains
     * more than $limit messages between runs therefore skips the middle, since
     * lastSeenUid jumps to the newest UID synced. That is the trade the setting
     * exists to make; clearing it and re-running walks the gap.
     */
    private function cappedUidRange(Folder $folder, string $uidRange, int $limit): string
    {
        try {
            $uids = $folder->messages()->where('UID', $uidRange)->search()->all();
        } catch (\Throwable $e) {
            $this->logger->warning('Could not apply sync limit, syncing full range', [
                'range' => $uidRange,
                'error' => $e->getMessage(),
            ]);

            return $uidRange;
        }

        $uids = array_map(intval(...), array_values($uids));

        if (true === (count($uids) <= $limit)) {
            return $uidRange;
        }

        sort($uids);
        $windowStart = $uids[count($uids) - $limit];

        $this->logger->info('Sync limit applied', [
            'available' => count($uids),
            'limit'     => $limit,
            'range'     => $windowStart . ':*',
        ]);

        return $windowStart . ':*';
    }

    /**
     * @param array<int,bool> $syncedUids  passed by reference so new UIDs are
     *                                      registered within the same sync run
     *                                      (guards against duplicates inside a
     *                                      single chunked call)
     */
    private function processBatch(
        iterable $batch,
        int      $mailboxId,
        int      $accountId,
        array    &$syncedUids,
    ): void {
        $mailbox  = $this->mailboxRepository->find($mailboxId);
        $messages = [];
        // Parallel to $messages: the original bytes, which are only in hand
        // here and are written to disk in pass 2 once the rows have ids.
        $rawBodies = [];
        $maxUid   = 0;

        // Pass 1 — build + persist Message rows (no threading yet)
        foreach ($batch as $imapMessage) {
            $uid = $imapMessage->getUid();

            // A `lastSeenUid+1:*` range still returns the highest-UID message when
            // nothing newer exists (`*` clamps to it), so every run re-delivers the
            // newest mail. Skip anything this mailbox already holds.
            if (true === isset($syncedUids[$uid])) {
                if (true === ($uid > $maxUid)) {
                    $maxUid = $uid;
                }

                continue;
            }

            // Gmailify history claim: a Gmail-imported copy of this exact message
            // (same RFC Message-ID, gmailId set, no IMAP location yet) gets linked
            // to this mailbox/UID instead of inserting a duplicate row. From here
            // on, IMAP operations (flags, moves) work on it normally.
            $rfcMessageId = MessageIdHelper::normalise($imapMessage->getMessageId());

            if ('' !== $rfcMessageId) {
                $claimable = $this->messageRepository->findGmailOnlyByMessageId(
                    $mailbox->getAccount(),
                    $rfcMessageId,
                );

                if (null !== $claimable) {
                    $claimable
                        ->setMailbox($mailbox)
                        ->setImapUid($uid);

                    $mailboxLabel = $mailbox->getLabel();

                    if (null !== $mailboxLabel) {
                        $claimable->addLabel($mailboxLabel);
                        $claimable->getThread()?->addLabel($mailboxLabel);

                        // Claiming a Gmail-imported row for this mailbox adds a
                        // label, so mailboxIds changed even though no message
                        // was created.
                        $this->stateManager->recordUpdated(
                            (int) $accountId,
                            JmapObjectType::Email,
                            (string) $claimable->getId(),
                        );

                        $claimedThread = $claimable->getThread();

                        if (null !== $claimedThread) {
                            $this->stateManager->recordThreadsTouched(
                                (int) $accountId,
                                [(int) $claimedThread->getId()],
                            );
                        }
                    }

                    $syncedUids[$uid] = true;

                    if (true === ($uid > $maxUid)) {
                        $maxUid = $uid;
                    }

                    continue;
                }
            }

            try {
                $message = $this->buildMessage($imapMessage, $mailbox, $accountId);
                $this->em->persist($message);
                $messages[]        = $message;
                $rawBodies[]       = $this->rawOf($imapMessage);
                $syncedUids[$uid]  = true; // mark within this run

                if (true === ($uid > $maxUid)) {
                    $maxUid = $uid;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to build message', [
                    'uid'   => $uid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Flush so all new messages have IDs before the threader queries them
        $this->em->flush();

        $correspondents = $this->contactRepository->findCorrespondentEmails($mailbox->getAccount()->getUsr());
        // Pass 2 — assign threads now that all messages exist in DB
        /** @var list<\App\Entity\Message> $ruleTargets */
        $ruleTargets = [];

        foreach ($messages as $index => $message) {
            $this->sanitizer->sanitize($message);

            // Store the original bytes now that the row has an id. IMAP is the
            // only provider that gets this for free — Gmail and Graph need a
            // second API call, so they are fetched lazily by RawMessageResolver.
            $this->rawResolver->store($message, $rawBodies[$index] ?? '');

            // JMAP state: the ids exist after the flush above. record() only
            // persists, so these rows ride along on the flush below.
            $this->stateManager->recordCreated(
                (int) $accountId,
                JmapObjectType::Email,
                (string) $message->getId(),
            );

            $message->setCategory($this->categorizer->categorize($message, $correspondents));
            try {
                $this->messageThreader->assignThread(
                    $message,
                    $mailbox->getAccount(),
                );
            } catch (\Throwable $e) {
                $this->logger->error('Failed to assign thread', [
                    'messageId' => $message->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }

            $ruleTargets[] = $message;
        }

        // One query per rule for the whole batch, after threading so archive
        // and trash actions can reach each message's thread.
        $this->ruleEngine->applyToBatch($ruleTargets, $mailbox->getAccount());

        if (true === ($maxUid > 0)) {
            $mailbox->setLastSeenUid($maxUid);
        }

        // Threads exist only after assignThread() above, so this runs as a
        // second pass rather than inside the loop — and only after the flush,
        // which is where a thread created moments ago gets its id. Reading
        // them before it published every new thread to JMAP clients as id 0.
        $this->em->flush();

        $threadIds = [];

        foreach ($messages as $message) {
            $thread = $message->getThread();

            if (null !== $thread) {
                $threadIds[] = (int) $thread->getId();
            }
        }

        $this->stateManager->recordThreadsTouched((int) $accountId, $threadIds);

        // The change-log rows recorded just now.
        $this->em->flush();
    }

    /**
     * Reassemble the message as it arrived: raw headers, blank line, raw body.
     * webklex keeps both around after parsing, so nothing is re-fetched.
     */
    private function rawOf(ImapMessage $imapMessage): string
    {
        $header = $imapMessage->getHeader();

        if (null === $header || '' === $header->raw) {
            return '';
        }

        return rtrim($header->raw, "\r\n") . "\r\n\r\n" . $imapMessage->getRawBody();
    }

    private function buildMessage(ImapMessage $imapMessage, Mailbox $mailbox, int $accountId): Message
    {
        $message = new Message()
            ->setAccount($mailbox->getAccount())
            ->setMailbox($mailbox);
        $mailboxLabel = $mailbox->getLabel();

        if (null !== $mailboxLabel) {
            $message->addLabel($mailboxLabel);
        }
        $message->setImapUid($imapMessage->getUid());
        $message->setMessageId(MessageIdHelper::normalise((string) $imapMessage->getMessageId()));
        $message->setSubject(
            $this->decodeMimeHeader((string) $imapMessage->getSubject())
        );

        // From
        $from = $imapMessage->getFrom()->first();
        if (null !== $from) {
            $message->setFromAddress($from->mail ?? '');
            $message->setFromName(
                $this->decodeMimeHeader((string) $from->personal)
            );
        }

        // Recipients
        $message->setToAddresses($this->formatAddresses($imapMessage->getTo()));
        $message->setCcAddresses($this->formatAddresses($imapMessage->getCc()));
        $message->setBccAddresses($this->formatAddresses($imapMessage->getBcc()));

        // Dates
        $date = $imapMessage->getDate()->toDate();
        $receivedAt = DateTimeImmutable::createFromInterface($date);
        $message->setSentAt($receivedAt);
        $message->setReceivedAt($receivedAt);

        // Flags
        $flagNames = array_values($imapMessage->getFlags()->toArray());
        $message->setFlags($flagNames);

        if (
            true === in_array('Seen', $flagNames, true)
            || true === in_array('\\Seen', $flagNames, true)
        ) {
            $message->setSeenAt(new DateTimeImmutable());
        }

        // Threading headers
        $inReplyTo  = $imapMessage->getInReplyTo();
        $references = $imapMessage->getReferences();

        $message->setInReplyTo(
            $inReplyTo->exist() ? MessageIdHelper::normaliseList((string) $inReplyTo) : []
        );
        $message->setReferences(
            $references->exist() ? MessageIdHelper::normaliseList((string) $references) : []
        );

        // Headers
        $rawHeaders = [];

        foreach ($imapMessage->getHeader()->getAttributes() as $name => $attribute) {
            $values = $attribute->toArray();

            $rawHeaders[(string) $name] = count($values) === 1
                ? (string) reset($values)
                : array_map(static fn($v): string => (string) $v, $values);
        }

        $message->setHeaders($this->headerNormalizer->normalize($rawHeaders));

        // Body
        $message->setBodyText($imapMessage->getTextBody() ?? '');
        $message->setBodyHtml($imapMessage->getHTMLBody() ?? '');

        // Attachments
        $attachments = $imapMessage->getAttachments();
        $message->setSyncedAt(new DateTimeImmutable());

        $hasAttachments = false;

        foreach ($attachments as $attachment) {
            if (false === $this->persistAttachment($attachment, $message, $accountId)) {
                $hasAttachments = true;
            }
        }

        $message->setHasAttachments($hasAttachments);


        return $message;
    }

    /**
     * @return bool  true if the part is inline (embedded in the HTML body)
     */
    private function persistAttachment(mixed $attachment, Message $message, int $accountId): bool
    {
        $filename = $attachment->getFilename() ?? ('attachment_' . uniqid());
        $content  = $attachment->getContent();

        $storagePath = $this->attachmentStorage->store(
            $accountId,
            $message->getMailbox()->getId(),
            $message->getImapUid(),
            $filename,
            $content,
        );

        $part = new MessagePart();
        $part->setMessage($message);
        $part->setContentType($attachment->getContentType() ?? 'application/octet-stream');
        $part->setFilename($filename);
        $part->setSize(strlen($content));
        $part->setStoragePath($storagePath);

        // getId() falls back to a content hash when the part has no Content-ID,
        // so it only counts as a cid when the HTML body actually references it.
        $normalizedCid = $this->inlineDetector->normalizeContentId((string) $attachment->getId());
        $isInline      = $this->inlineDetector->isInline(
            $attachment->getDisposition(),
            $normalizedCid,
            $message->getBodyHtml(),
        );

        $part->setContentId('' !== $normalizedCid ? $normalizedCid : null);
        $part->setDisposition($isInline ? 'inline' : 'attachment');
        $part->setIsInline($isInline);

        $this->em->persist($part);

        return $isInline;
    }

    private function formatAddresses(mixed $attribute): array
    {
        if (null === $attribute) {
            return [];
        }

        $result = [];
        foreach ($attribute as $address) {
            $result[] = [
                'name'    => $address->personal ?? '',
                'address' => $address->mail ?? '',
            ];
        }

        return $result;
    }

    private function decodeMimeHeader(string $value): string
    {
        return MimeHeaderHelper::decode($value);
    }
}
