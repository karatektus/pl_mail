<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\DTO\Mail\ParsedAddress;
use App\Domain\DTO\Mail\ParsedMessage;
use App\Domain\DTO\Mail\ParsedPart;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\MessagePriority;
use App\Domain\Helper\AddressHelper;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\DTO\Mail\ImapCopyIdentity;
use App\Domain\Helper\MessageIdHelper;
use App\Domain\Helper\MessageParser;
use App\Domain\Helper\RepeatedHeaderHelper;
use App\Entity\Mail\Account;
use App\Infrastructure\ImapClient\Exception\ImapCommandException;
use App\Infrastructure\ImapClient\ImapSession;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Repository\Mail\Mailbox\MailboxRepository;
use App\Repository\Mail\Message\MessageRepository;
use App\Repository\Mail\Message\MessageImapRepository;
use App\Service\Crypto\MessageCryptoDetector;
use App\Service\Label\KeywordLabels;
use App\Service\Mail\InlineAttachmentDetector;
use App\Service\Mail\PostIngestPipeline;
use App\Service\Monitoring\ProcessHeartbeatService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use App\Service\Mail\HeaderNormalizer;

class MessageSyncer
{
    private const int BATCH_SIZE = 50;

    /**
     * How many syncs in a row one UID may fail before it is given up on.
     * See holdForRetry().
     */
    private const int MAX_UID_ATTEMPTS = 5;

    public function __construct(
        private readonly AttachmentStorageHelper $attachmentStorage,
        private readonly MailboxRepository       $mailboxRepository,
        private readonly EntityManagerInterface  $em,
        private readonly LoggerInterface         $logger,
        private readonly MessageRepository       $messageRepository,
        private readonly MessageImapRepository $messageImapRepository,
        private readonly InlineAttachmentDetector $inlineDetector,
        private readonly PostIngestPipeline      $postIngest,
        private readonly HeaderNormalizer $headerNormalizer,
        private readonly SentCopyReconciler $sentCopies,
        private readonly VanishedMessageReconciler $vanished,
        private readonly GhostMessageReaper $ghosts,
        private readonly ManagerRegistry $registry,
        private readonly ImapPagedFetch $pagedFetch,
        private readonly ImapCopies $copies,
        private readonly PurgedCopies $purgedCopies,
        private readonly ImapImportPlan $importPlan,
        private readonly ProcessHeartbeatService $heartbeats,
        private readonly MessageCryptoDetector $cryptoDetector,
        private readonly KeywordLabels $keywordLabels,
    ) {}

    /**
     * Whether the page being stored is history rather than new mail — set for
     * the length of storeImportPage() and at no other time.
     *
     * State on a service, which this class otherwise has none of, because the
     * one thing it changes is four calls down: how a message that would not
     * store is held (see holdForRetry()). Carried as an argument it would be a
     * parameter on five signatures to reach one `if`.
     */
    private bool $importing = false;

    /**
     * What the index pass said about each UID about to be downloaded, so the
     * message built from it keeps its size and server id — which is how the
     * next copy of it is recognised (ImapCopies). Set for the length of
     * one folder's sync or one import page, like $importing, and for the same
     * reason: it is needed four calls down.
     *
     * @var array<int, ImapCopyIdentity>
     */
    private array $identities = [];

    /**
     * An instant, in UTC.
     *
     * Both halves of this are needed. A parsed date keeps the offset the
     * sender wrote — a `+0200` header yields a 15:58:46 object for a 13:58:46
     * UTC instant. Doctrine's DateTimeImmutableType then formats in whatever
     * zone the object carries, and the column is TIMESTAMP WITHOUT TIME ZONE,
     * so the sender's wall clock is what lands in Postgres.
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

    public function syncMailbox(Mailbox $mailbox, ImapSession $session): void
    {
        $mailboxId = $mailbox->id;
        $accountId = $mailbox->account->id;
        $path      = $mailbox->serverPath();

        try {
            $session->select($path);
        } catch (ImapCommandException $refused) {
            $this->logger->error('Folder not found', ['mailbox' => $path, 'error' => $refused->getMessage()]);

            return;
        }

        // Before anything reads lastSeenUid or the stored UIDs, because both can
        // change here: a folder rebuilt on the server has its high-water mark
        // reset to zero and every stored UID stripped, and the incremental pass
        // below has to be planned against that rather than against the state
        // this method was entered with. On the ordinary poll this is a no-op —
        // it only lists a folder every SWEEP_INTERVAL_MINUTES.
        $this->vanished->sweep($mailbox, $session);

        // A folder nobody has looked at yet: put the mark at its top, so what
        // follows asks for new mail only, and leave everything below to the
        // importer, which brings it in newest first on its own queue. Without
        // this the range below was `1:*` — the whole folder, oldest first, in
        // this one job (#42). A server that will not say where its top is
        // leaves the folder unplanned, and it is read that old way.
        if (null === $mailbox->importFloorUid) {
            $this->importPlan->begin($mailbox, $session);
        }

        $lastSeenUid = $mailbox->lastSeenUid ?? 0;
        $uidRange    = ($lastSeenUid + 1) . ':*';

        $this->logger->info('Syncing mailbox', [
            'mailbox'     => $mailbox->fullPath,
            'account'     => $accountId,
            'lastSeenUid' => $lastSeenUid,
        ]);

        // Load the already-synced UIDs above the mark up front so each batch
        // can O(1)-skip them. Only those: the range below asks for nothing
        // lower, and processBatch() treats anything at or below the mark as
        // handled. array_flip turns [123, 456, …] into [123 => 0, 456 => 1, …].
        $syncedUids = array_flip(
            $this->messageImapRepository->findSyncedUids($mailbox, $lastSeenUid)
        );

        // Mail deleted forever here, whose deletion the server has not
        // confirmed: asked about again if the job did not get through, and
        // never imported again meanwhile (#427). See PurgedCopy.
        $this->purgedCopies->settle($mailbox, $session);

        foreach ($this->purgedCopies->uidsIn($mailbox) as $purgedUid) {
            $syncedUids[$purgedUid] = true;
        }

        // How a message that appears here is told from a message that is merely
        // also here: by whether the copy it would have moved from is still on
        // the server. Asks nothing until something wants to know.
        $presence = new ImapUidPresence($session, $this->logger);

        $synced = 0;

        // The lowest UID this run refused to persist, across every batch. Held
        // run-wide rather than per batch because the mark only ever moves up:
        // a clamp in the first batch was simply overwritten by the second
        // batch's higher UIDs, and the skipped message was never asked for again.
        $lowestSkippedUid = null;

        // A message that cannot be read costs that message, not the folder;
        // see ImapPagedFetch. A range that finds nothing moves nothing, so
        // a server's "no such message" for it is read as the empty answer
        // it means (#219).
        $uids = ImapUidSearch::range($session, $path, $uidRange);

        // Copies of mail that is already here become locations of it and are
        // not downloaded at all (#415). Asked of the new UIDs only; the rest
        // go on as they always did.
        $new = array_values(array_filter(
            $uids,
            static fn (int $uid): bool => $uid > $lastSeenUid && false === isset($syncedUids[$uid]),
        ));

        [$attached, $this->identities] = $this->copies->attachKnownCopies($mailbox, $session, $new);

        foreach ($attached as $uid) {
            $syncedUids[$uid] = true;
        }

        $uids = array_values(array_diff($uids, $attached));

        foreach ($this->pagedFetch->pages($session, $path, $uids, self::BATCH_SIZE) as [$batch, $unreadable]) {
            $this->processBatch($batch, $mailboxId, $accountId, $lastSeenUid, $syncedUids, $lowestSkippedUid, $presence, $unreadable);
            $synced += count($batch);
            $this->startNextBatch();
            $this->heartbeats->beatWhileBusy();
            $this->logger->info(sprintf('Synced %d messages so far', $synced));
        }

        $mailbox = $this->mailboxRepository->find($mailboxId);

        $this->identities = [];

        // An attached copy moves the mark like a downloaded one, or the next
        // poll asks for it again; held below a UID that failed, as there.
        if ([] !== $attached) {
            $top = max($attached);

            if (null !== $lowestSkippedUid) {
                $top = min($top, $lowestSkippedUid - 1);
            }

            if ($top > ($mailbox->lastSeenUid ?? 0)) {
                $mailbox->lastSeenUid = $top;
            }
        }

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

        $mailbox->syncedAt = new DateTimeImmutable();
        $mailbox->unreadMessages = $this->messageRepository->countUnseenForMailbox($mailbox);
        $mailbox->totalMessages = $this->messageRepository->countTotalForMailbox($mailbox);
        $this->em->flush();
    }

    /**
     * Store one page of a folder's history, fetched by ImapMailboxImporter.
     *
     * The same path new mail takes — processBatch(), the post-ingest pipeline
     * and all — so an imported message is in every way a message. What differs
     * is the bookkeeping around it: the page is below the high-water mark by
     * definition, so the mark is not what says "already here" (nothing is
     * passed as already synced; the importer asked only for what is missing),
     * and a message that will not store is reported back rather than counted
     * on the mailbox.
     *
     * @param iterable<ParsedMessage>  $batch
     * @param array<int, \Throwable>        $unreadable as for processBatch()
     * @param array<int, ImapCopyIdentity> $identities what the index pass said about the page's UIDs
     *
     * @return int|null the lowest UID of the page that was held back, or null
     *                  when every message of it was dealt with
     */
    public function storeImportPage(Mailbox $mailbox, ImapSession $session, iterable $batch, array $unreadable, array $identities = []): ?int
    {
        $syncedUids = [];
        $lowestHeld = null;
        $presence   = new ImapUidPresence($session, $this->logger);

        $this->importing  = true;
        $this->identities = $identities;

        try {
            $this->processBatch(
                $batch,
                (int) $mailbox->id,
                (int) $mailbox->account->id,
                0,
                $syncedUids,
                $lowestHeld,
                $presence,
                $unreadable,
            );
        } finally {
            $this->importing  = false;
            $this->identities = [];
        }

        $this->startNextBatch();

        return $lowestHeld;
    }

    /**
     * @param array<int,bool> $syncedUids  passed by reference so new UIDs are
     *                                      registered within the same sync run
     *                                      (guards against duplicates inside a
     *                                      single chunked call)
     * @param array<int, \Throwable> $unreadable  the UIDs of this page the
     *                                      library could not build a message
     *                                      from, and what it threw — see
     *                                      ImapPagedFetch
     * @param int|null        $lowestSkippedUid  the run-wide lowest UID held
     *                                      back for a retry; the high-water
     *                                      mark is clamped below it
     */
    private function processBatch(
        iterable         $batch,
        int              $mailboxId,
        int              $accountId,
        int              $lastSeenUid,
        array            &$syncedUids,
        ?int             &$lowestSkippedUid,
        ImapUidPresence  $presence,
        array            $unreadable = [],
    ): void {
        // The run as it stood before this batch. A batch the database refuses
        // is decided again from here, one message at a time.
        $syncedBefore = $syncedUids;
        $lowestBefore = $lowestSkippedUid;

        try {
            [$mailbox, $messages, $rawBodies, $maxUid] = $this->storeMessages(
                $batch, $mailboxId, $accountId, $lastSeenUid, $syncedUids, $lowestSkippedUid, $presence, false, $unreadable,
            );
        } catch (\Throwable $refused) {
            // Only a refused write closes the manager. Anything else, the
            // server going away mid-fetch say, is not something writing the
            // messages one at a time would get past.
            if (true === $this->em->isOpen()) {
                throw $refused;
            }

            // A notice, not a warning: this line only says the run changes
            // strategy. Most refusals are the race described below and lose
            // nothing, and a message that really cannot be stored is logged
            // as an error of its own by the one-at-a-time pass (#352).
            $this->logger->notice('The database refused a batch; storing its messages one at a time', [
                'mailboxId' => $mailboxId,
                'error'     => $refused->getMessage(),
                'exception' => $refused,
            ]);

            $this->registry->resetManager();

            $syncedUids       = $syncedBefore;
            $lowestSkippedUid = $lowestBefore;

            // What this folder holds NOW, which is not what it held when the
            // run began. $syncedUids is read once, before the first fetch, and
            // a sync is not the only thing that gives a row a UID here: a move
            // made in the app, or a second sync of the same account on another
            // worker, files rows into this folder while this run is still
            // fetching. Those UIDs then arrive as mail nobody has seen, the
            // INSERT is refused on (mailbox, uid), and one at a time every one
            // of them was refused again and logged as a message that could not
            // be built (#37) — for mail that was stored, and correctly.
            //
            // Asked again here rather than before every batch: it is one
            // indexed query, but the ordinary batch has no use for it, and a
            // refusal is exactly the moment the snapshot is known to be old.
            $mailboxNow = $this->mailboxRepository->find($mailboxId);

            if (null !== $mailboxNow) {
                foreach ($this->messageImapRepository->findSyncedUids($mailboxNow, $lastSeenUid) as $heldUid) {
                    $syncedUids[(int) $heldUid] = true;
                }
            }

            [$mailbox, $messages, $rawBodies, $maxUid] = $this->storeMessages(
                $batch, $mailboxId, $accountId, $lastSeenUid, $syncedUids, $lowestSkippedUid, $presence, true, $unreadable,
            );
        }

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
     * Pass 1: build the batch's messages and write them, so they have ids
     * before the threader queries them.
     *
     * In one transaction, which is what keeps a first sync of thousands of
     * mails quick, and a transaction is all or nothing. One value the database
     * would not take (a raw latin-1 attachment name was the first) had the
     * whole batch refused, and the refusal named no message. The mark stayed
     * below the batch, the next sync fetched the same fifty, and the folder
     * never got past them: holdForRetry() was only ever handed messages that
     * failed to build, never one the database refused.
     *
     * So a refused batch comes back here with $oneAtATime, and every message
     * is written in a transaction of its own. The database takes each one it
     * will take, and the one it refuses is held back like a message that could
     * not be built: asked for again, and after MAX_UID_ATTEMPTS syncs let go.
     *
     * @param array<int,bool> $syncedUids        as for processBatch()
     * @param int|null        $lowestSkippedUid  as for processBatch()
     *
     * @return array{Mailbox, list<Message>, list<string>, int} the mailbox as
     *         the manager holds it now, the messages written, their original
     *         bytes, and the highest UID the batch named
     */
    private function storeMessages(
        iterable         $batch,
        int              $mailboxId,
        int              $accountId,
        int              $lastSeenUid,
        array            &$syncedUids,
        ?int             &$lowestSkippedUid,
        ImapUidPresence  $presence,
        bool             $oneAtATime,
        array            $unreadable = [],
    ): array {
        $mailbox  = $this->mailboxRepository->find($mailboxId);
        $messages = [];
        // Parallel to $messages: the original bytes, which are only in hand
        // here and are written to disk in pass 2 once the rows have ids.
        $rawBodies = [];
        $maxUid   = 0;

        // The page in UID order, with the messages that could not be read
        // standing where they would have stood. The order is not
        // tidiness: holdForRetry() counts the LOWEST failure of a run, and
        // decides which that is by the order it is told of them. Handed the
        // unreadable ones before or after the rest, two poison messages would
        // take the counter from each other on every sync and neither would
        // ever reach the attempt that lets it go.
        /** @var array<int, ParsedMessage|\Throwable> $page */
        $page = $unreadable;

        foreach ($batch as $fetched) {
            $page[(int) $fetched->uid] = $fetched;
        }

        ksort($page);

        // Pass 1 — build + persist Message rows (no threading yet)
        foreach ($page as $uid => $imapMessage) {
            if ($imapMessage instanceof \Throwable) {
                if (true === $this->holdUnreadable($mailbox, $uid, $imapMessage, $lastSeenUid, $syncedUids, $lowestSkippedUid)) {
                    $maxUid = max($maxUid, $uid);
                }

                if (true === $oneAtATime) {
                    $this->em->flush();
                }

                continue;
            }

            // A `lastSeenUid+1:*` range still returns the highest-UID message when
            // nothing newer exists (`*` clamps to it), so every run re-delivers the
            // newest mail. Skip anything this mailbox already holds — and
            // anything at or below the mark, which $syncedUids no longer
            // covers: the clamped `*` can name a UID below it once the newest
            // message has been deleted.
            if ($uid <= $lastSeenUid || true === isset($syncedUids[$uid])) {
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

                $this->holdForRetry($mailbox, $uid, $lowestSkippedUid);

                if (true === $oneAtATime) {
                    $this->em->flush();
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
            $rfcMessageId = MessageIdHelper::normalise($imapMessage->messageId);
            $claimed      = $this->sentCopies->claim($mailbox, $rfcMessageId, $uid, $presence);

            // Mail with no Message-ID has nothing for claim() to match, so a
            // row that lost its address in a protocol switch (#289) is found
            // by sender, subject and Date header instead — see fingerprintOf().
            if (null === $claimed && '' === $rfcMessageId) {
                $fingerprint = $this->fingerprintOf($imapMessage);

                if (null !== $fingerprint) {
                    $claimed = $this->sentCopies->claimByFingerprint($mailbox, $fingerprint, $uid);
                }
            }

            if (null !== $claimed) {
                // A row this installation wrote, now with a server copy: what
                // the index said about that copy is what recognises the next.
                $claimed->rfc822Size    ??= $this->identities[$uid]->size ?? null;
                $claimed->serverEmailId ??= $this->identities[$uid]->serverId ?? null;

                if (true === $oneAtATime) {
                    $this->em->flush();
                }

                $syncedUids[$uid] = true;

                if (true === ($uid > $maxUid)) {
                    $maxUid = $uid;
                }

                continue;
            }

            try {
                // Before anything reads the body, so the columns, the parts and
                // the stored .eml all come from the same bytes.
                $imapMessage = $this->dropRepeatedHeader($imapMessage, $uid, $mailbox->fullPath);

                $message = $this->buildMessageFromParsed($imapMessage, $mailbox, $accountId);
                $raw     = $imapMessage->raw;

                // What the next copy of this email is recognised by. The
                // server's count where the index gave one; otherwise the bytes
                // as fetched, which are the same octets.
                $identity               = $this->identities[$uid] ?? null;
                $message->rfc822Size    = $identity->size ?? strlen($raw);
                $message->serverEmailId = $identity?->serverId;

                $this->em->persist($message);

                // Only now, with the message whole. buildMessage() used to
                // persist each part as it went, so a second attachment that
                // threw left the first one managed and pointing at a message
                // that was never persisted — and the flush below failed the
                // whole batch on it.
                foreach ($message->messageParts as $part) {
                    $this->em->persist($part);
                }

                // One at a time, every message is a transaction of its own, so
                // a refusal costs this message and nothing written before it.
                if (true === $oneAtATime) {
                    $this->em->flush();
                }

                $messages[]        = $message;
                $rawBodies[]       = $raw;
                $syncedUids[$uid]  = true; // mark within this run

                if (true === ($uid > $maxUid)) {
                    $maxUid = $uid;
                }
            } catch (\Throwable $e) {
                // Refused rather than unbuildable: the flush above closed the
                // manager, and the counter has to go onto a mailbox it can
                // still write. Never for a whole batch, where the messages
                // before this one are persisted but not written yet, and a
                // reset would drop them.
                if (true === $oneAtATime && false === $this->em->isOpen()) {
                    $this->registry->resetManager();
                    $mailbox = $this->mailboxRepository->find($mailboxId);
                }

                $retrying = $this->holdForRetry($mailbox, $uid, $lowestSkippedUid);

                // On its own, so the next refusal cannot roll the count back.
                if (true === $oneAtATime) {
                    $this->em->flush();
                }

                $this->logger->error(
                    true === $retrying
                        ? 'Failed to build message; it is asked for again next sync'
                        : 'Failed to build message too many times; skipping it for good',
                    [
                        'uid'       => $uid,
                        'mailbox'   => $mailbox->fullPath,
                        'attempts'  => $mailbox->failedUidAttempts,
                        'error'     => $e->getMessage(),
                        'exception' => $e,
                    ],
                );
            }
        }

        // Flush so all new messages have IDs before the threader queries them.
        // One at a time, everything is written already and this has nothing
        // left to do.
        $this->em->flush();

        if (true === $oneAtATime) {
            // Every refusal reset the manager, and a reset detaches whatever
            // was written before it. The pipeline writes what it decides onto
            // these rows and the mark onto the mailbox, so both are found again
            // where the manager can see them.
            $mailbox = $this->mailboxRepository->find($mailboxId);

            foreach ($messages as $index => $message) {
                $messages[$index] = $this->messageRepository->find($message->id) ?? $message;
            }
        }

        return [$mailbox, $messages, $rawBodies, $maxUid];
    }

    /**
     * Drop the finished batch's unit of work, and reopen the manager if
     * something closed it.
     *
     * Post-ingest steps run after the batch is committed and are allowed to
     * fail: PostIngestPipeline logs the failure and carries on, because the
     * mail is already stored. But Doctrine closes the manager when a flush
     * fails, and clear() does not reopen it. So one step that failed a flush
     * made the next batch throw EntityManagerClosed on its first persist.
     * Every message in that batch was logged as "Failed to build message"
     * though nothing was wrong with any of them, and the folder's sync then
     * died on the flush after.
     *
     * Resetting is safe here for the same reason clearing is: nothing that
     * writes holds an entity across a batch, and processBatch() finds the
     * mailbox again by id.
     */
    private function startNextBatch(): void
    {
        if (true === $this->em->isOpen()) {
            $this->em->clear();

            return;
        }

        $this->registry->resetManager();
    }

    /**
     * A message that could not be read from what the server sent: held
     * for a retry like one that would not store, and let go on the same count.
     *
     * These never used to get here. webklex threw while it was fetching
     * the page, the page never arrived, and the retry-then-skip below was
     * never told there was anything to count — which is how one message with
     * a header the parser disliked stopped a folder for good (#45).
     *
     * A UID this folder already holds is not a failure, whatever the parser
     * made of it this time: `lastSeenUid+1:*` re-delivers the newest message
     * on every run, and a message that is stored was once read.
     *
     * @param array<int,bool> $syncedUids
     *
     * @return bool whether the mark may pass this UID — it is already held, or
     *              it has failed often enough to be given up
     */
    private function holdUnreadable(
        Mailbox    $mailbox,
        int        $uid,
        \Throwable $reason,
        int        $lastSeenUid,
        array      $syncedUids,
        ?int       &$lowestSkippedUid,
    ): bool {
        if ($uid <= $lastSeenUid || true === isset($syncedUids[$uid])) {
            return true;
        }

        $retrying = $this->holdForRetry($mailbox, $uid, $lowestSkippedUid);

        $this->logger->error(
            true === $retrying
                ? 'Could not read a message the server sent; it is asked for again next sync'
                : 'Could not read a message the server sent, too many times; skipping it for good',
            [
                'uid'       => $uid,
                'mailboxId' => $mailbox->id,
                'mailbox'   => $mailbox->fullPath,
                'attempts'  => $mailbox->failedUidAttempts,
                'error'     => $reason->getMessage(),
                'exception' => $reason,
            ],
        );

        return false === $retrying;
    }

    /**
     * Keep a UID that could not be stored inside the next sync's range — up to
     * a point.
     *
     * Holding the high-water mark below a failed UID is what makes "retry next
     * sync" true. Holding it there for ever is what lets one poison message —
     * a MIME tree the parser always chokes on — stop a folder from ever
     * importing anything newer. So the lowest failing UID is counted on the
     * mailbox, and after MAX_UID_ATTEMPTS failures in a row it is let go and
     * logged, and the mark moves past it.
     *
     * Only the lowest failure of a run counts. A higher UID that fails in the
     * same run is re-fetched anyway, because the mark is already clamped below
     * the lower one, and letting it reset the counter would mean two poison
     * messages could hold a folder for ever between them.
     *
     * @return bool whether the UID is held for a retry
     */
    private function holdForRetry(Mailbox $mailbox, int $uid, ?int &$lowestSkippedUid): bool
    {
        // A page of history keeps no count here. The counter on the mailbox
        // belongs to the top of the folder, where new mail arrives, and the
        // importer is reading the bottom of the same folder from another
        // worker: one ledger between them and a message failing at each end
        // would take the count from the other on every pass. The importer
        // counts by the page instead — see ImapMailboxImporter — and all it
        // needs from here is to be told that something was held.
        if (true === $this->importing) {
            $lowestSkippedUid = null === $lowestSkippedUid ? $uid : min($lowestSkippedUid, $uid);

            return true;
        }

        if (null !== $lowestSkippedUid && $lowestSkippedUid < $uid) {
            return true;
        }

        if ($mailbox->failedUid === $uid) {
            ++$mailbox->failedUidAttempts;
        } else {
            $mailbox->failedUid         = $uid;
            $mailbox->failedUidAttempts = 1;
        }

        if ($mailbox->failedUidAttempts >= self::MAX_UID_ATTEMPTS) {
            return false;
        }

        $lowestSkippedUid = $uid;

        return true;
    }

    /**
     * The message again without the header block a server repeated at the
     * top of its body.
     *
     * Some messages on a Stalwart server answer RFC822.TEXT with the whole
     * message, headers included, while RFC822.HEADER is right (#173). Parsed
     * as it came, a single-part HTML mail was stored with its own
     * `Delivered-To: …` block as the first lines of body_html, and the .eml
     * carried the headers twice. That is the server's mistake, not a parser's,
     * so it is repaired on the bytes, before they are read: the columns, the
     * parts and the stored original then all come from the same repaired copy.
     * See RepeatedHeaderHelper for how a repeat is recognised.
     */
    private function dropRepeatedHeader(ParsedMessage $parsed, int $uid, string $mailboxPath): ParsedMessage
    {
        $header = $parsed->rawHeader();
        $body   = RepeatedHeaderHelper::strip($header, $parsed->rawBody());

        if (null === $body) {
            return $parsed;
        }

        $this->logger->info('Dropped a header block the server repeated at the top of the body', [
            'uid'     => $uid,
            'mailbox' => $mailboxPath,
        ]);

        return MessageParser::parse(rtrim($header, "\r\n") . "\r\n\r\n" . $body)
            ->withEnvelope($uid, $parsed->flags);
    }

    /**
     * Whether a fetch carried a message at all.
     *
     * A FETCH can come back with an empty header block — the connection
     * hiccuped mid-response, the server answered NIL, the MIME was malformed
     * enough that the parser produced nothing. None of that throws: the
     * parser hands back a message with no sender, no subject and no body, and
     * buildMessage() would happily store it — with a receivedAt of now, which
     * makes it look like new mail. That is the ghost: indistinguishable from
     * mail in the schema, counted as unread because nothing set seenAt, and
     * visible only as a blank row with a "?" avatar.
     *
     * The test is deliberately generous about what counts as a message: any
     * ONE of a Date, a Message-ID or a From is enough. Real mail always has at
     * least one — a subjectless message still has a sender, a message with no
     * Date header still has a Message-ID — so this cannot refuse anything a
     * user would recognise. It refuses only the case where the fetch said
     * nothing whatsoever, which is not a message that happens to be empty but
     * an answer that failed to arrive.
     */
    private function isUsableFetch(ParsedMessage $parsed): bool
    {
        return self::describesAMessage(
            MessageIdHelper::normalise($parsed->messageId),
            (string) $parsed->from?->address,
            $parsed->hasDateHeader,
        );
    }

    /**
     * The decision itself, over the three values it actually turns on.
     *
     * Split out from isUsableFetch() so the rule can be tested as the three
     * values it is, without a parsed message to carry them.
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
     * When the message arrived, or now — never the epoch.
     *
     * A message with a sender and a Message-ID but no Date header is real mail
     * and has to be stored; dating it 1970 would sort it to the bottom of every
     * list forever and make it look like the ghosts this class now refuses.
     * Ingest time is the honest approximation, and it is what Gmail's builder
     * already falls back to.
     */
    private function receivedAtOf(ParsedMessage $parsed): DateTimeImmutable
    {
        return self::dateFromHeader($parsed->date, new DateTimeImmutable());
    }

    /**
     * The instant a parsed `Date:` header names, or $fallback when it names
     * nothing usable.
     *
     * Absent and unreadable are the same answer here: MessageParser reads a
     * Date it cannot parse as no date, and a message is never dated by a
     * header that said nothing. Nor by one that said 1970 or before: a date at
     * or below the epoch is a mailer's zero, not when the mail was written.
     *
     * Public and static so the rule can be tested on its own.
     */
    public static function dateFromHeader(?\DateTimeInterface $date, DateTimeImmutable $fallback): DateTimeImmutable
    {
        if (null === $date) {
            return $fallback;
        }

        $parsed = self::toUtc($date);

        if ($parsed->getTimestamp() <= 0) {
            return $fallback;
        }

        return $parsed;
    }

    /**
     * The sender's priority, for the same column the composer writes on
     * outgoing mail. Null when the sender stated none, which is not the same
     * as Normal — see MessagePriority.
     *
     * Read from the normalised header bag, which carries both X-Priority and
     * Importance. Public so a test can hand it a real parsed header.
     *
     * @param array<string, string|list<string>>|null $headers
     */
    public function priorityOf(?array $headers): ?MessagePriority
    {
        return MessagePriority::fromHeaders(
            $this->headerNormalizer->first($headers, 'importance'),
            $this->headerNormalizer->first($headers, 'x-priority'),
        );
    }

    /**
     * What identifies a message that has no Message-ID: its sender, its
     * subject and the instant its Date header names — read exactly as
     * buildMessageFromParsed() writes them to the row, so a fingerprint taken
     * here matches the columns of a row built from the same bytes, whichever
     * protocol delivered them.
     *
     * Null when the message has a Message-ID (that is the identity to use)
     * and when it has no usable Date header: the row's time is then the
     * moment it was fetched, which no second fetch reproduces.
     *
     * Used only where a row is known to have lost its server address — after
     * a protocol switch (#289) — never to decide whether new mail is new.
     *
     * @return array{from: string, subject: string, date: DateTimeImmutable}|null
     */
    public function fingerprintOf(ParsedMessage $parsed): ?array
    {
        if ('' !== MessageIdHelper::normalise($parsed->messageId)) {
            return null;
        }

        if (null === $parsed->date) {
            return null;
        }

        return [
            'from'    => AddressHelper::email((string) $parsed->from?->address),
            'subject' => $parsed->subject,
            'date'    => $this->receivedAtOf($parsed),
        ];
    }

    /**
     * Turn one parsed message into a Message row, attached to a mailbox.
     *
     * The single place a row is built from an RFC 822 message, whichever
     * protocol delivered it. IMAP hands it one parsed from a FETCH, POP3 one
     * parsed from a RETR answer. Nothing below this line knows which — the
     * parser is the shared part, and that is the reason the two protocols
     * produce identical rows for identical bytes.
     *
     * Does NOT persist. The UID is the one the message was fetched under,
     * which a POP3 message does not have — see Pop3AccountSyncer.
     *
     * Public since POP3 was added. It was private while IMAP was the only
     * caller and the file was the interface.
     */
    public function buildMessageFromParsed(ParsedMessage $parsed, Mailbox $mailbox, int $accountId): Message
    {
        return $this->buildFromParsed($parsed, $mailbox->account, $mailbox);
    }

    /**
     * The same, for a message that lives in no folder.
     *
     * JMAP is the caller: its server hands over the RFC 822 bytes of an Email
     * as a blob, so the bytes go through the parser IMAP and POP3 use and
     * come out as the same row — but a JMAP Email is in any number of
     * mailboxes at once, which a Mailbox row cannot say. Its placement is
     * labels, as a Gmail message's is, and the caller applies them; there is
     * no folder label to add here and no folder to point the row at.
     */
    public function buildFromParsed(ParsedMessage $parsed, Account $account, ?Mailbox $mailbox): Message
    {
        $message = new Message();
        $message->account = $account;
        $message->mailbox = $mailbox;

        // The folder's label, or Archive for mail first met in All Mail — see
        // ImapCopies::labelFor() (#415).
        $mailboxLabel = null === $mailbox ? null : $this->copies->labelFor($mailbox);

        if (null !== $mailboxLabel) {
            $message->addLabel($mailboxLabel);
        }

        $message->imapUid   = $parsed->uid;
        $message->messageId = MessageIdHelper::normalise($parsed->messageId);
        $message->subject   = $parsed->subject;

        if (null !== $parsed->from) {
            $message->fromAddress = AddressHelper::email($parsed->from->address);
            $message->fromName    = AddressHelper::name($parsed->from->name);
        }

        $message->toAddresses  = self::addressesOf($parsed->to);
        $message->ccAddresses  = self::addressesOf($parsed->cc);
        $message->bccAddresses = self::addressesOf($parsed->bcc);

        $message->flags = $parsed->flags;

        // Keywords that are labels, where the account keeps labels as keywords
        // (#221 step 2): the mail arrives wearing them. Off, and they stay in
        // the flag mirror and nowhere else, as before. IMAP's only: a JMAP
        // Email (no folder here) carries its keywords as JMAP does.
        $owner = $account->usr;

        if (null !== $mailbox && true === $account->labelsAsKeywords && null !== $owner) {
            foreach (KeywordLabels::namesIn($parsed->flags) as $name) {
                $label = $this->keywordLabels->label($name, $owner, true);

                if (null !== $label) {
                    $message->addLabel($label);
                }
            }
        }

        // Dates. A draft has not been sent, whatever its Date header says (see
        // Message::$sentAt). Decided by the same test the rest of the app uses
        // on the stored flags, so a row is never "not a draft, never sent".
        $receivedAt = $this->receivedAtOf($parsed);

        $message->sentAt = true === $message->isDraft() ? null : $receivedAt;
        $message->receivedAt = $receivedAt;

        if (true === $message->hasFlag(MessageFlag::SEEN)) {
            $message->seenAt = new DateTimeImmutable();
        }

        // Threading headers
        $message->inReplyTo  = '' !== $parsed->inReplyTo ? MessageIdHelper::normaliseList($parsed->inReplyTo) : [];
        $message->references = '' !== $parsed->references ? MessageIdHelper::normaliseList($parsed->references) : [];

        $message->headers  = $this->headerNormalizer->normalize($parsed->headers);
        $message->priority = $this->priorityOf($message->headers);

        // UTF-8 already: MessageParser takes each body as bytes and converts
        // it by CharsetHelper's rule, whatever the part claimed (#81).
        $message->bodyText = $parsed->textBody;
        $message->bodyHtml = $parsed->htmlBody;

        // OpenPGP or S/MIME, decided from the top-level Content-Type and the
        // body — before anything is written, because an encrypted message's
        // body must never reach the row: search_vector is built from it
        // on insert. No part list: IMAP always has the header, and a plain
        // message that merely carries somebody's signature.asc is not signed.
        $crypto = $this->cryptoDetector->classify(
            $this->headerNormalizer->first($message->headers, 'content-type'),
            $message->bodyText,
            $message->bodyHtml,
        );
        $this->cryptoDetector->seal($message, $crypto);

        $message->syncedAt = new DateTimeImmutable();

        // Attachments — without the parts that are only the wrapping: the
        // signature, the ciphertext. The raw source keeps them for whoever
        // checks or decrypts. After the body is set, because whether an image
        // is embedded or attached is decided by whether the HTML names it.
        $hasAttachments = false;

        foreach ($parsed->attachments as $part) {
            if (null !== $crypto && true === $this->cryptoDetector->isWrapping($crypto, $part->contentType, $part->filename)) {
                continue;
            }

            if (false === $this->persistAttachment($part, $message, (int) $account->id)) {
                $hasAttachments = true;
            }
        }

        $message->hasAttachments = $hasAttachments;

        return $message;
    }

    /**
     * @return bool  true if the part is inline (embedded in the HTML body)
     */
    private function persistAttachment(ParsedPart $attachment, Message $message, int $accountId): bool
    {
        $filename = $attachment->filename ?? AttachmentStorageHelper::fallbackName($attachment->contentType);
        $content  = $attachment->content;

        // Zero for the folder and the uid of a message that has neither — a
        // JMAP one, see buildFromParsed(). The path only has to be somewhere
        // the file can be written: parts are found and deleted by their own
        // storagePath, never by the directory they are in, and a name that
        // repeats there is given a suffix by the store.
        $storagePath = $this->attachmentStorage->store(
            $accountId,
            (int) ($message->mailbox->id ?? 0),
            (int) ($message->imapUid ?? 0),
            $filename,
            $content,
        );

        $part = new MessagePart();
        $part->message     = $message;
        $part->contentType = '' !== $attachment->contentType ? $attachment->contentType : 'application/octet-stream';
        $part->filename    = $filename;
        $part->size        = strlen($content);
        $part->storagePath = $storagePath;

        // The id is compared with the body to decide whether the part is
        // embedded; both went through the same UTF-8 repair, so a `cid:`
        // reference with a raw byte in it still finds its part (#81).
        $normalizedCid = $this->inlineDetector->normalizeContentId($attachment->contentId);
        $isInline      = $this->inlineDetector->isInline($normalizedCid, $message->bodyHtml);

        $part->contentId   = '' !== $normalizedCid ? $normalizedCid : null;
        $part->disposition = $isInline ? 'inline' : 'attachment';
        $part->isInline    = $isInline;

        // Attached, not persisted: processBatch() persists the parts once the
        // whole message has been built. See there.
        $message->addMessagePart($part);

        return $isInline;
    }

    /**
     * The addresses of a `to`/`cc`/`bcc` header, as a row stores them.
     *
     * Entries whose addr-spec is empty are dropped, matching the Gmail and
     * Graph builders: a recipient we cannot name is worse than a list that
     * honestly has none in it.
     *
     * @param list<ParsedAddress> $addresses
     *
     * @return list<array{name: string, address: string}>
     */
    public static function addressesOf(array $addresses): array
    {
        $result = [];

        foreach ($addresses as $address) {
            $email = AddressHelper::email($address->address);

            if ('' === $email) {
                continue;
            }

            $result[] = [
                'name'    => AddressHelper::name($address->name),
                'address' => $email,
            ];
        }

        return $result;
    }
}
