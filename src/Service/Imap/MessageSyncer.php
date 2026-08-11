<?php

namespace App\Service\Imap;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\Helper\AddressHelper;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\ImapConnectionFactory;
use App\Domain\Helper\MessageIdHelper;
use App\Domain\Helper\MimeHeaderHelper;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\InlineAttachmentDetector;
use App\Service\Mail\PostIngestPipeline;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\Client;
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
        private readonly InlineAttachmentDetector $inlineDetector,
        private readonly PostIngestPipeline      $postIngest,
        private readonly HeaderNormalizer $headerNormalizer,
        private readonly SentCopyReconciler $sentCopies,
        private readonly ImapConnectionFactory $connections,
        private readonly VanishedMessageReconciler $vanished,
        private readonly GhostMessageReaper $ghosts,
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
        $mailboxId = $mailbox->id;
        $accountId = $mailbox->account->id;

        $folder = $client->getFolder($mailbox->name);

        if (null === $folder) {
            $this->logger->error('Folder not found', ['mailbox' => $mailbox->name]);
            return;
        }

        // Before anything reads lastSeenUid or the stored UIDs, because both can
        // change here: a folder rebuilt on the server has its high-water mark
        // reset to zero and every stored UID stripped, and the incremental pass
        // below has to be planned against that rather than against the state
        // this method was entered with. On the ordinary poll this is a no-op —
        // it only lists a folder every SWEEP_INTERVAL_MINUTES.
        $this->vanished->sweep($mailbox, $client);

        $lastSeenUid = $mailbox->lastSeenUid ?? 0;
        $uidRange    = ($lastSeenUid + 1) . ':*';

        $this->logger->info('Syncing mailbox', [
            'mailbox'     => $mailbox->fullPath,
            'account'     => $accountId,
            'lastSeenUid' => $lastSeenUid,
        ]);

        // Load all already-synced UIDs up front so each batch can O(1)-skip them.
        // array_flip turns [123, 456, …] into [123 => 0, 456 => 1, …].
        $syncedUids = array_flip(
            $this->messageRepository->findSyncedUids($mailbox)
        );

        // How a message that appears here is told from a message that is merely
        // also here: by whether the copy it would have moved from is still on
        // the server. Opens nothing until something actually asks.
        $presence = new ImapUidPresence($mailbox->account, $this->connections, $this->logger);

        $synced = 0;

        try {
            $folder->messages()
                ->where(self::uidRangeCriteria($uidRange))
                ->chunked(function ($batch) use ($mailboxId, $accountId, &$synced, &$syncedUids, $presence) {
                    $this->processBatch($batch, $mailboxId, $accountId, $syncedUids, $presence);
                    $synced += count($batch);
                    $this->em->clear();
                    $this->logger->info(sprintf('Synced %d messages so far', $synced));
                }, self::BATCH_SIZE);

            $mailbox = $this->mailboxRepository->find($mailboxId);

            // Before the counts below, so they are taken over what is left. Only
            // does anything on a Sent folder that still holds pairs the old send
            // path duplicated; on every other sync it is one indexed query that
            // finds nothing. See SentCopyReconciler::repair().
            $this->sentCopies->repair($mailbox);

            // The same idea one folder wider: rows this account holds twice
            // because a move left the first one behind. Also one indexed query
            // that finds nothing on an account that never had the bug.
            $this->sentCopies->repairRelocated($mailbox, $presence);

            // Last, and account-wide rather than folder-wide, because the
            // question it settles is "is this message anywhere" and no single
            // folder can answer it. Does nothing until every folder in the
            // account has been swept, and nothing then either unless the server
            // confirms the absence one row at a time. See
            // VanishedMessageReconciler.
            $this->vanished->reap($mailbox->account, $presence);

            // Before the counts below, because a ghost is counted as unread and
            // the badge is where they were noticed in the first place. Runs
            // after the reconcilers so it never races them for the same row.
            $this->ghosts->reap();
        } finally {
            $presence->close();
        }

        $mailbox->syncedAt = new DateTimeImmutable();
        $mailbox->unreadMessages = $this->messageRepository->countUnseenForMailbox($mailbox);
        $mailbox->totalMessages = $this->messageRepository->countTotalForMailbox($mailbox);
        $this->em->flush();
    }

    /**
     * @param array<int,bool> $syncedUids  passed by reference so new UIDs are
     *                                      registered within the same sync run
     *                                      (guards against duplicates inside a
     *                                      single chunked call)
     */
    private function processBatch(
        iterable         $batch,
        int              $mailboxId,
        int              $accountId,
        array            &$syncedUids,
        ImapUidPresence  $presence,
    ): void {
        $mailbox  = $this->mailboxRepository->find($mailboxId);
        $messages = [];
        // Parallel to $messages: the original bytes, which are only in hand
        // here and are written to disk in pass 2 once the rows have ids.
        $rawBodies = [];
        $maxUid   = 0;
        // The lowest UID this batch refused to persist. The high-water mark is
        // held below it so the next sync asks for it again — see the clamp
        // after the loop.
        $lowestSkippedUid = null;

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

            // A fetch that told us nothing is not a message. Skipping it here —
            // before the claim below, which would otherwise be handed an empty
            // Message-ID to match on — is what stops a ghost row being written.
            // See isUsableFetch() for what "nothing" means and why.
            if (false === $this->isUsableFetch($imapMessage)) {
                $this->logger->warning(
                    'Skipped an IMAP fetch that carried no message; nothing was persisted',
                    [
                        'uid'     => $uid,
                        'mailbox' => $mailbox->fullPath,
                        'account' => $accountId,
                    ],
                );

                if (null === $lowestSkippedUid || $uid < $lowestSkippedUid) {
                    $lowestSkippedUid = $uid;
                }

                continue;
            }

            // A copy of this exact message that this account already holds:
            // Gmail-imported and waiting for its IMAP twin, or written by our
            // own composer and waiting for its Sent copy to come back. Either
            // way it is linked to this mailbox/UID instead of being inserted a
            // second time, and IMAP operations work on it normally from here.
            // See SentCopyReconciler, which owns the matching rules and the
            // reason each of them is scoped the way it is.
            $rfcMessageId = MessageIdHelper::normalise((string) $imapMessage->getMessageId());

            if (null !== $this->sentCopies->claim($mailbox, $rfcMessageId, $uid, $presence)) {
                $syncedUids[$uid] = true;

                if (true === ($uid > $maxUid)) {
                    $maxUid = $uid;
                }

                continue;
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

        // A skipped UID must stay inside the next run's range, or "retry next
        // sync" is not true and a message lost to one bad fetch is lost for
        // good. Holding the mark below the lowest skipped UID costs a re-fetch
        // of the UIDs above it — which processBatch then skips in O(1) against
        // $syncedUids — and never costs the message itself.
        if (null !== $lowestSkippedUid) {
            $maxUid = min($maxUid, $lowestSkippedUid - 1);
        }

        // Set before the pipeline runs so its flush carries the write. It has
        // to land even when this batch built nothing — every message may have
        // been seen already, and the range would otherwise be re-fetched
        // forever.
        //
        // Never backwards: the clamp above can push this below where the
        // mailbox already stands, and lowering the mark would re-deliver mail
        // that is already here.
        if (true === ($maxUid > ($mailbox->lastSeenUid ?? 0))) {
            $mailbox->lastSeenUid = $maxUid;
        }

        // Pass 2 — the shared post-ingest sequence. IMAP is the only provider
        // holding the original bytes at this point, so it is the only one that
        // passes rawSource.
        $ingested = [];

        foreach ($messages as $index => $message) {
            $ingested[] = new IngestedMessage(
                $message,
                $mailbox->account,
                $rawBodies[$index] ?? '',
            );
        }

        $this->postIngest->run($mailbox->account, $ingested);
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

    /**
     * Whether a fetch carried a message at all.
     *
     * A FETCH can come back with an empty header block — the connection
     * hiccuped mid-response, the server answered NIL, the MIME was malformed
     * enough that the parser produced nothing. None of that throws: webklex
     * hands back an empty Attribute for every field, and an empty Attribute
     * answers '' for a string and `false` for an address. buildMessage() would
     * then happily assemble a Message with no sender, no subject and no body —
     * and, because Attribute::toDate() runs the empty value through
     * Carbon::parse(false), a receivedAt of 1970-01-01. That is the ghost:
     * indistinguishable from mail in the schema, counted as unread because
     * nothing set seenAt, and visible only as a blank row with a "?" avatar.
     *
     * The test is deliberately generous about what counts as a message: any
     * ONE of a Date, a Message-ID or a From is enough. Real mail always has at
     * least one — a subjectless message still has a sender, a message with no
     * Date header still has a Message-ID — so this cannot refuse anything a
     * user would recognise. It refuses only the case where the fetch said
     * nothing whatsoever, which is not a message that happens to be empty but
     * an answer that failed to arrive.
     */
    private function isUsableFetch(ImapMessage $imapMessage): bool
    {
        $from = $imapMessage->getFrom()->first();

        // `first()` on an empty Attribute is `false`, not null — the same trap
        // buildMessage() fell into below.
        $fromAddress = (false !== $from && null !== $from)
            ? (string) ($from->mail ?? '')
            : '';

        return self::describesAMessage(
            (string) MessageIdHelper::normalise((string) $imapMessage->getMessageId()),
            $fromAddress,
            $this->hasDateHeader($imapMessage),
        );
    }

    /**
     * The decision itself, over the three values it actually turns on.
     *
     * Split out from isUsableFetch() so it can be tested: a webklex Message
     * cannot be constructed without a live IMAP connection (Message::make()
     * reaches through setClient() to openFolder()), so a test that wanted to
     * exercise the predicate through the real object would have to stand up a
     * server to ask a question about three strings. The adapter above is the
     * part that reads webklex; this is the part that decides.
     */
    public static function describesAMessage(
        string $messageId,
        string $fromAddress,
        bool   $hasDateHeader,
    ): bool {
        return '' !== $messageId
            || '' !== $fromAddress
            || true === $hasDateHeader;
    }

    /**
     * Whether the fetch carried a Date header with a value in it.
     *
     * Asked as "does the attribute hold anything" rather than by parsing,
     * because a present-but-unparseable Date throws out of toDate() and an
     * absent one silently becomes the epoch. Only the second is a ghost.
     */
    private function hasDateHeader(ImapMessage $imapMessage): bool
    {
        $date = $imapMessage->getDate();

        if (null === $date) {
            return false;
        }

        return [] !== $date->toArray();
    }

    /**
     * When the message arrived, or now — never the epoch.
     *
     * A message with a sender and a Message-ID but no Date header is real mail
     * and has to be stored; dating it 1970 would sort it to the bottom of every
     * list forever and make it look like the ghosts this class now refuses.
     * Ingest time is the honest approximation, and it is what Gmail's builder
     * already falls back to.
     */
    private function receivedAtOf(ImapMessage $imapMessage): DateTimeImmutable
    {
        if (false === $this->hasDateHeader($imapMessage)) {
            return new DateTimeImmutable();
        }

        return self::toUtc($imapMessage->getDate()->toDate());
    }

    private function buildMessage(ImapMessage $imapMessage, Mailbox $mailbox, int $accountId): Message
    {
        $message = new Message();
        $message->account = $mailbox->account;
        $message->mailbox = $mailbox;

        $mailboxLabel = $mailbox->label;

        if (null !== $mailboxLabel) {
            $message->addLabel($mailboxLabel);
        }

        $message->imapUid = $imapMessage->getUid();
        $message->messageId = MessageIdHelper::normalise((string) $imapMessage->getMessageId());
        $message->subject = $this->decodeMimeHeader((string) $imapMessage->getSubject());

        // From. `first()` answers `false` on an empty Attribute, so the old
        // `null !== $from` let a missing sender through and then read `->mail`
        // off a boolean.
        $from = $imapMessage->getFrom()->first();
        if (false !== $from && null !== $from) {
            $message->fromAddress = AddressHelper::email($from->mail ?? '');
            $message->fromName = AddressHelper::name($from->personal ?? '');
        }

        // Recipients
        $message->toAddresses = $this->formatAddresses($imapMessage->getTo());
        $message->ccAddresses = $this->formatAddresses($imapMessage->getCc());
        $message->bccAddresses = $this->formatAddresses($imapMessage->getBcc());

        // Dates
        $receivedAt = $this->receivedAtOf($imapMessage);
        $message->sentAt = $receivedAt;
        $message->receivedAt = $receivedAt;

        // Flags
        $flagNames = array_values($imapMessage->getFlags()->toArray());
        $message->flags = $flagNames;

        if (
            true === in_array('Seen', $flagNames, true)
            || true === in_array('\\Seen', $flagNames, true)
        ) {
            $message->seenAt = new DateTimeImmutable();
        }

        // Threading headers
        $inReplyTo  = $imapMessage->getInReplyTo();
        $references = $imapMessage->getReferences();

        $message->inReplyTo  = $inReplyTo->exist() ? MessageIdHelper::normaliseList((string) $inReplyTo) : [];
        $message->references = $references->exist() ? MessageIdHelper::normaliseList((string) $references) : [];

        // Headers
        $rawHeaders = [];

        foreach ($imapMessage->getHeader()->getAttributes() as $name => $attribute) {
            $values = $attribute->toArray();

            $rawHeaders[(string) $name] = count($values) === 1
                ? (string) reset($values)
                : array_map(static fn($v): string => (string) $v, $values);
        }

        $message->headers = $this->headerNormalizer->normalize($rawHeaders);

        // Body
        $message->bodyText = $imapMessage->getTextBody() ?? '';
        $message->bodyHtml = $imapMessage->getHTMLBody() ?? '';

        // Attachments
        $attachments = $imapMessage->getAttachments();
        $message->syncedAt = new DateTimeImmutable();

        $hasAttachments = false;

        foreach ($attachments as $attachment) {
            if (false === $this->persistAttachment($attachment, $message, $accountId)) {
                $hasAttachments = true;
            }
        }

        $message->hasAttachments = $hasAttachments;

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
            $message->mailbox->id,
            $message->imapUid,
            $filename,
            $content,
        );

        $part = new MessagePart();
        $part->message     = $message;
        $part->contentType = $attachment->getContentType() ?? 'application/octet-stream';
        $part->filename    = $filename;
        $part->size        = strlen($content);
        $part->storagePath = $storagePath;

        // getId() falls back to a content hash when the part has no Content-ID,
        // so it only counts as a cid when the HTML body actually references it.
        $normalizedCid = $this->inlineDetector->normalizeContentId((string) $attachment->getId());
        $isInline      = $this->inlineDetector->isInline(
            $attachment->getDisposition(),
            $normalizedCid,
            $message->bodyHtml,
        );

        $part->contentId   = '' !== $normalizedCid ? $normalizedCid : null;
        $part->disposition = $isInline ? 'inline' : 'attachment';
        $part->isInline    = $isInline;

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
