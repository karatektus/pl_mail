<?php

namespace App\Service\Imap;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\Helper\AddressHelper;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\MessageIdHelper;
use App\Domain\Helper\MimeHeaderHelper;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\InlineAttachmentDetector;
use App\Service\Mail\PostIngestPipeline;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message as ImapMessage;
use App\Service\Mail\HeaderNormalizer;

class MessageSyncer
{
    private const int BATCH_SIZE = 50;

    public function __construct(
        private readonly AttachmentStorageHelper $attachmentStorage,
        private readonly MailboxRepository       $mailboxRepository,
        private readonly EntityManagerInterface  $em,
        private readonly LoggerInterface         $logger,
        private readonly MessageRepository       $messageRepository,
        private readonly StateManager            $stateManager,
        private readonly InlineAttachmentDetector $inlineDetector,
        private readonly PostIngestPipeline      $postIngest,
        private readonly HeaderNormalizer $headerNormalizer,
    ) {}

    /**
     * A UID range as a search criterion the library will not quote.
     *
     * `where('UID', '1:*')` looks right and is not: Query::generate_query()
     * emits the value bare only when `is_numeric()` says so, and a range is not
     * numeric, so it goes out as `UID "1:*"`. Servers reject that outright —
     * Dovecot with `BAD expected DIGIT instead of '"'` — and since every
     * incremental sync asks for a range, IMAP sync failed for every mailbox
     * while the logs showed only a protocol error with no obvious cause.
     *
     * `CUSTOM ` is the library's own escape hatch: validate_criteria() strips
     * the prefix and the remainder is pushed as a single token, which
     * generate_query() appends verbatim. A single UID would have been fine
     * either way, which is why this never showed up in a first sync of one.
     */
    private static function uidRangeCriteria(string $uidRange): string
    {
        return 'CUSTOM UID '.$uidRange;
    }

    /**
     * The instant an RFC 2822 `Date:` header names, in UTC.
     *
     * Both halves of this are needed. webklex parses the header with a bare
     * `Carbon::parse()` (vendor/webklex/php-imap/src/Header.php), which keeps
     * the offset the sender wrote — a `+0200` header yields a 15:58:46 object
     * for a 13:58:46 UTC instant. Doctrine's DateTimeImmutableType then formats
     * in whatever zone the object carries, and the column is TIMESTAMP WITHOUT
     * TIME ZONE, so the sender's wall clock is what lands in Postgres.
     *
     * Gmail and Graph both normalise to UTC before persisting. Without this,
     * IMAP alone stored something else, and the rows were only ever readable by
     * a renderer that was itself wrong in the opposite direction.
     */
    public static function toUtc(\DateTimeInterface $date): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new \DateTimeZone('UTC'));
    }

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
            ->where(self::uidRangeCriteria($uidRange))
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
            $uids = $folder->messages()->where(self::uidRangeCriteria($uidRange))->search()->all();
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
            //
            // Deliberately outside PostIngestPipeline: this row already went
            // through it on the Gmail side, so re-running would record a second
            // create for an id JMAP clients hold, and re-apply rules to mail the
            // user may since have filed by hand.
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

        // Set before the pipeline runs so its flush carries the write. It has
        // to land even when this batch built nothing — every message may have
        // been seen already, and the range would otherwise be re-fetched
        // forever.
        if (true === ($maxUid > 0)) {
            $mailbox->setLastSeenUid($maxUid);
        }

        // Pass 2 — the shared post-ingest sequence. IMAP is the only provider
        // holding the original bytes at this point, so it is the only one that
        // passes rawSource.
        $ingested = [];

        foreach ($messages as $index => $message) {
            $ingested[] = new IngestedMessage(
                $message,
                $mailbox->getAccount(),
                $rawBodies[$index] ?? '',
            );
        }

        $this->postIngest->run($mailbox->getAccount(), $ingested);
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
            $message->setFromAddress(AddressHelper::email($from->mail ?? ''));
            $message->setFromName(AddressHelper::name($from->personal ?? ''));
        }

        // Recipients
        $message->setToAddresses($this->formatAddresses($imapMessage->getTo()));
        $message->setCcAddresses($this->formatAddresses($imapMessage->getCc()));
        $message->setBccAddresses($this->formatAddresses($imapMessage->getBcc()));

        // Dates
        $receivedAt = self::toUtc($imapMessage->getDate()->toDate());
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
                'name'    => AddressHelper::name($address->personal ?? ''),
                'address' => AddressHelper::email($address->mail ?? ''),
            ];
        }

        return $result;
    }

    private function decodeMimeHeader(string $value): string
    {
        return MimeHeaderHelper::decode($value);
    }
}
