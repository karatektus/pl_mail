<?php

declare(strict_types=1);

namespace App\Service\Pop3;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Helper\ImapConnectionFactory;
use App\Domain\Interface\AccountSyncerInterface;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Infrastructure\Pop3\Pop3Connection;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MailboxRepository;
use App\Service\Imap\ImapDeadline;
use App\Service\Imap\MessageSyncer;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Mail\PostIngestPipeline;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Throwable;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Bringing a POP3 mailbox into plMail.
 *
 * ── The shape of the problem ─────────────────────────────────────────────────
 * POP3 is not a worse IMAP; it is a different model, and most of this class is
 * the difference written down. A POP3 server has ONE mailbox, numbered 1..N in
 * the order it happens to hold them; the numbers are reissued after any
 * deletion; and the only thing the client may do to a message is read it or
 * delete it. There are no folders, no flags, no UIDs and nothing to push back.
 *
 * What survives from the IMAP path is everything after the bytes are in hand:
 * webklex parses the message, MessageSyncer builds the row, PostIngestPipeline
 * does the rest. That reuse is the reason this is a syncer and not a second
 * mail stack.
 *
 * ── How it decides what is new ───────────────────────────────────────────────
 * The server lists every message in its mailbox on every sync — there is no
 * "since" and nothing to ask — so the whole of the sync's memory is a list of
 * UIDLs it has already stored, on the account (Account::$pop3SeenUidls). A
 * message is new exactly when its UIDL is not in that list.
 *
 * UIDL and not the message number, and not the Message-ID. The number is
 * reissued after deletions, so it names a different message next time. The
 * Message-ID is absent from real mail more often than one would like, and the
 * same Message-ID legitimately appears twice (a copy to two of the user's
 * addresses). UIDL is the one identifier RFC 1939 guarantees is unique and
 * stable for as long as the message exists — which is exactly the lifetime
 * that matters for "have I already got this one".
 *
 * ── Where the mail lands ─────────────────────────────────────────────────────
 * One Mailbox row, called after the account, with the Inbox special use. It
 * is not INBOX by name because it is not an IMAP INBOX — there is nothing to
 * select — and the sidebar and the label chain both key off the special use
 * rather than the name. Sent and Drafts are label-only: a POP3 server has
 * nowhere to put them, so mail plMail sends is labelled Sent and stays here.
 * See MessageSendService, which already appends to a Sent folder only when
 * one exists.
 *
 * ── What cannot work, and is therefore not attempted ─────────────────────────
 *  - Flags: marking read, starring and moving are LOCAL. Nothing is sent to
 *    the server, because POP3 has no way to receive it. The next sync does not
 *    undo them either, which is the part that makes this a real feature rather
 *    than a display of one: the sync only ever inserts messages it has never
 *    seen, and never revisits a row it has.
 *  - Pushing changes back: ApplyImapFlagsHandler is not reachable for these
 *    accounts, because LabelChangePropagator only queues it for messages that
 *    have an imapUid, and these rows have none. See the note there.
 *  - Deletion in the UI: deleting a message deletes the local row only. The
 *    server's copy is untouched unless the account's delete mode says so at
 *    the next sync, which is a different and emptier promise.
 */
final readonly class Pop3AccountSyncer implements AccountSyncerInterface
{
    /**
     * Messages fetched per connection before the session is recycled.
     *
     * POP3 holds the mailbox locked for the whole transaction, and providers
     * cap both the number of messages per session and how long it may stay
     * open. A hundred is what the largest of these mailboxes comfortably
     * affords; the number is small on purpose because the cost of recycling is
     * one re-LIST, and the cost of not recycling is a server that hangs up
     * half way through a first import.
     */
    private const int BATCH_SIZE = 100;

    /**
     * How many messages a single sync will fetch.
     *
     * The first sync of a mailbox the user has been reading for years is
     * thousands of messages, and a sync that runs for an hour holds the
     * mailbox locked against the user's phone for that hour. The remainder is
     * picked up by the next sync — the account is a normal polled account and
     * the message it did not reach is still on the server — so the limit costs
     * nothing but wall time and is invisible to the user except as a progress
     * line that moves.
     */
    private const int MAX_MESSAGES_PER_SYNC = 500;

    public function __construct(
        private readonly MessageSyncer         $messageSyncer,
        private readonly AccountRepository     $accountRepository,
        private readonly MailboxRepository     $mailboxRepository,
        private readonly LabelResolver         $labelResolver,
        private readonly ThreadLabelSynchronizer $threadLabels,
        private readonly PostIngestPipeline    $postIngest,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry        $registry,
        private readonly ImapDeadline           $deadline,
        private readonly LoggerInterface        $logger,
    ) {
    }

    /**
     * POP3 only. Gmail, Microsoft and plain IMAP all answer false and are
     * served by their own syncers.
     */
    public function supports(Account $account): bool
    {
        return true === $account->isPop3();
    }

    /**
     * @return list<int> the mailboxes written to, for the caller's per-mailbox
     *                  follow-up work
     */
    public function sync(Account $account): array
    {
        $mailbox = $this->mailboxFor($account);

        $connection = $this->openSession($account);
        $stored     = 0;

        try {
            $listing = $connection->list();
            $uidls   = $connection->uidl();

            if ([] === $listing) {
                $this->logger->info('POP3 sync: the mailbox is empty', ['accountId' => $account->id]);

                $connection->quit();

                return [];
            }

            // A message the server lists but does not name is not one this
            // sync can recognise, so it is skipped rather than guessed at.
            // That is a broken server, and the alternative — fetching it under
            // a made-up identifier — stores it again on every sync for ever.
            $unnamed = count($listing) - count($uidls);

            if ($unnamed > 0) {
                $this->logger->warning('POP3 sync: the server listed messages without UIDLs; they cannot be recognised', [
                    'accountId' => $account->id,
                    'count'     => $unnamed,
                ]);
            }

            $new  = [];
            $seen = $account->pop3SeenUidls;

            foreach ($listing as $entry) {
                $uidl = $uidls[$entry->number] ?? null;

                if (null === $uidl || true === in_array($uidl, $seen, true)) {
                    continue;
                }

                $new[] = ['number' => $entry->number, 'uidl' => $uidl];
            }

            if ([] === $new) {
                $this->logger->debug('POP3 sync: nothing new', ['accountId' => $account->id, 'listed' => count($listing)]);

                // The delete mode still has work to do on an account that has
                // caught up: a message stored on an earlier sync may be one
                // the user has only now asked to be removed from the server.
                $this->deleteStored($account, $connection, $uidls);

                $connection->quit();

                return [];
            }

            $pending = count($new);

            if ($pending > self::MAX_MESSAGES_PER_SYNC) {
                $new = array_slice($new, 0, self::MAX_MESSAGES_PER_SYNC);

                $this->logger->info('POP3 sync: message limit reached; the rest is left for the next sync', [
                    'accountId' => $account->id,
                    'limit'     => self::MAX_MESSAGES_PER_SYNC,
                    'remaining' => $pending - count($new),
                ]);
            }

            foreach (array_chunk($new, self::BATCH_SIZE) as $index => $batch) {
                // A fresh session per batch. quit() ends the one before it,
                // which is also the moment that batch's delete marks become
                // real on the server — see Pop3Connection on the transaction
                // state, and why the marks travel with the session and not
                // with the row.
                if (0 !== $index) {
                    $connection->quit();

                    $account    = $this->refindAccount($account);
                    $mailbox    = $this->mailboxFor($account);
                    $connection = $this->openSession($account);
                }

                $stored += $this->fetchBatch($account, $mailbox, $connection, $batch);
            }

            $account = $this->refindAccount($account);
            $account->recordSyncSuccess();

            $this->em->flush();

            $connection->quit();
        } catch (Throwable $failure) {
            // Nothing that was marked for deletion is deleted: abort() does
            // not send QUIT, and a POP3 server rolls the transaction back when
            // the session ends any other way. The UIDLs of whatever did store
            // are already committed — rememberStored() flushes — so the next
            // sync does not fetch them again.
            $connection->abort();

            throw $failure;
        }

        return 0 === $stored ? [] : [(int) $mailbox->id];
    }

    /**
     * Fetch and store one batch, then mark its messages for deletion if this
     * account wants them gone.
     *
     * @param list<array{number: int, uidl: string}> $batch
     *
     * @return int how many were stored
     */
    private function fetchBatch(Account $account, Mailbox $mailbox, Pop3Connection $connection, array $batch): int
    {
        $stored  = [];
        $rawSour = [];

        foreach ($batch as $entry) {
            try {
                $raw = $connection->retrieve($entry['number']);
            } catch (Throwable $failure) {
                // One message the server will not hand over is one message.
                // The rest of the batch is still worth fetching, and the UIDL
                // is not recorded, so the next sync asks for it again.
                $this->logger->warning('POP3 sync: the server would not send a message; it is asked for again next sync', [
                    'accountId' => $account->id,
                    'uidl'      => $entry['uidl'],
                    'error'     => $failure->getMessage(),
                ]);

                continue;
            }

            try {
                $message = $this->buildMessage($raw, $mailbox, (int) $account->id);

                // No UID: there is none, and inventing one would point the row
                // at a number the server reassigns. The unique constraint on
                // (mailbox_id, imap_uid) permits any number of NULLs, which is
                // exactly what is wanted here.
                $message->imapUid = null;

                $this->em->persist($message);

                foreach ($message->messageParts as $part) {
                    $this->em->persist($part);
                }

                $stored[]    = $message;
                $rawSour[]   = $raw;
            } catch (Throwable $failure) {
                // A refused write closes the manager; reopen it so the rest of
                // the batch is not blamed for it. Same contract as the IMAP
                // syncer's one-at-a-time fallback. The registry is used rather
                // than the manager itself because resetManager() lives on the
                // one, and a closed manager cannot reopen itself.
                if (false === $this->em->isOpen()) {
                    $this->registry->resetManager();

                    $mailbox = $this->mailboxRepository->find($mailbox->id);

                    if (null === $mailbox) {
                        // The row is gone — an account deleted during a long
                        // first import. There is nowhere left to file anything.
                        throw $failure;
                    }
                }

                $this->logger->error('POP3 sync: a message could not be stored; it is asked for again next sync', [
                    'accountId' => $account->id,
                    'uidl'      => $entry['uidl'],
                    'error'     => $failure->getMessage(),
                    'exception' => $failure,
                ]);
            }
        }

        // The rows exist, and this is the commit that makes them permanent.
        // The delete marks below are only ever made AFTER it returns: a
        // message told to the server as "got it" must be a message that is
        // actually here.
        $this->em->flush();

        $ingested = [];

        foreach ($stored as $index => $message) {
            $ingested[] = new IngestedMessage($message, $account, $rawSour[$index] ?? '');
        }

        if ([] !== $ingested) {
            $this->postIngest->run($account, $ingested);

            // The pipeline threads the messages, and MessageThreader copies a
            // message's labels onto its thread only when the message is FIRST
            // attached. That is enough for IMAP, whose mailbox label always
            // exists by then. Here the label is attached by
            // buildMessageFromParsed() before the pipeline runs, so it is
            // already there — but a message that joins a conversation which
            // already had messages contributes its label to a thread the
            // threader does not revisit, so the union is re-stated.
            //
            // Cheap and idempotent: sync() sets the union both ways, and the
            // threads are already loaded by the pipeline's threading pass.
            foreach ($ingested as $item) {
                if (null !== $item->message->thread) {
                    $this->threadLabels->sync($item->message->thread);
                }
            }

            $this->em->flush();
        }

        $this->rememberStored($account, array_column($batch, 'uidl'));

        if (true === $account->pop3DeleteMode->deletes()) {
            foreach ($batch as $entry) {
                if (false === in_array($entry['uidl'], $account->pop3SeenUidls, true)) {
                    // It was never stored — the server's copy is the only one.
                    continue;
                }

                try {
                    $connection->markDeleted($entry['number'], $entry['uidl']);
                } catch (Throwable $failure) {
                    $this->logger->warning('POP3 sync: a message could not be marked for deletion', [
                        'accountId' => $account->id,
                        'uidl'      => $entry['uidl'],
                        'error'     => $failure->getMessage(),
                    ]);
                }
            }
        }

        return count($stored);
    }

    /**
     * Delete from the server what is already here, for an account that has
     * asked for it.
     *
     * The path taken when nothing new arrived. Delete mode is a standing
     * instruction, not a one-off at fetch time: an account switched to Delete
     * should not have to wait for new mail before the old mail goes.
     *
     * @param array<int, string> $uidls message number => UIDL
     */
    private function deleteStored(Account $account, Pop3Connection $connection, array $uidls): void
    {
        if (false === $account->pop3DeleteMode->deletes()) {
            return;
        }

        foreach ($uidls as $number => $uidl) {
            if (false === $account->hasStoredPop3Uidl($uidl)) {
                continue;
            }

            try {
                $connection->markDeleted($number, $uidl);
            } catch (Throwable $failure) {
                $this->logger->warning('POP3 sync: a message could not be marked for deletion', [
                    'accountId' => $account->id,
                    'uidl'      => $uidl,
                    'error'     => $failure->getMessage(),
                ]);
            }
        }
    }

    /**
     * The one mailbox a POP3 account's mail lives in, created on first use and
     * bound to the account's Inbox label.
     *
     * ── The binding is not optional ──────────────────────────────────────────
     * Every view in the application is label-driven, not mailbox-driven: the
     * Inbox list, the sidebar counts and the JMAP Mailbox object all read the
     * label binding that MailboxSyncer would normally create. A Mailbox row
     * with no binding is a row nothing displays, and MessageSyncer's
     * buildMessageFromParsed() attaches `$mailbox->label` — null here — so the
     * messages landed with no label at all and were invisible in the UI while
     * being present in the database.
     *
     * Found by deploying it: five messages synced, none of them appeared. IMAP
     * never hits this because MailboxSyncer::linkLabel() binds every folder it
     * creates, and its `$mailbox->label` is therefore never null.
     */
    private function mailboxFor(Account $account): Mailbox
    {
        $existing = $this->mailboxRepository->findOneBy([
            'account'    => $account,
            'specialUse' => MailboxSpecialUse::INBOX,
        ]);

        if (null !== $existing) {
            // A row created before the binding existed — including one this
            // syncer made on an earlier run — is healed here rather than left
            // as mail the user cannot see.
            if (null === $existing->label) {
                $this->labelResolver->bindMailbox(
                    $this->labelResolver->systemLabel(LabelRole::Inbox, $account),
                    $existing,
                );

                $this->em->flush();
            }

            // The import tracker reads a mailbox as "still importing" while
            // $importFloorUid is null — "nobody has looked yet" — and this
            // syncer never sets it, because that marker is the IMAP importer's
            // page-walk bookkeeping and a POP3 mailbox has no page walk: one
            // pass brings in everything the server has. Left null, every POP3
            // account read as importing for ever and the topbar badge spun
            // for ever with it. Zero is the IMAP importer's own "done" value
            // (ImapFolderProvisioner sets it the same way), so this is the
            // same state that machinery arrives at, written by the one path
            // that has no reason ever to arrive at it.
            if (null === $existing->importFloorUid) {
                $existing->importFloorUid = 0;

                $this->em->flush();
            }

            // The mailbox's own binding is only half of it. Messages already
            // stored carry the labels THEY were given at build time, and the
            // ones written before this fix were given none — binding the
            // mailbox now does not retroactively label them, and the sync will
            // never see them again because their UIDLs are in the ledger. So
            // anything sitting in this mailbox with no labels at all gets the
            // Inbox label now.
            //
            // Deliberately "no labels at all" rather than "not this label":
            // a message the user has since filed somewhere else carries its own
            // labels and must be left alone, and re-adding Inbox to it would
            // pull it back into a list they had taken it out of.
            $this->labelOrphanedMessages($existing);

            return $existing;
        }

        $mailbox = new Mailbox();
        $mailbox->account      = $account;
        $mailbox->name         = 'Inbox';
        $mailbox->fullPath     = 'INBOX';
        $mailbox->specialUse   = MailboxSpecialUse::INBOX;
        $mailbox->isSelectable = true;
        $mailbox->isSyncEnabled = true;
        // No import walk to do — see the healing branch above for why the
        // tracker needs this rather than null.
        $mailbox->importFloorUid = 0;

        // isSyncEnabled on a POP3 account is a switch with nothing to switch:
        // there is one mailbox and no folder tree, so there is no second one to
        // turn on or off. It is true so that the rest of the application's
        // "is this folder polled" checks read a POP3 account's mail as polled,
        // which it is.
        //
        // Persisted before the binding, for the same reason MailboxSyncer does:
        // bindMailbox() flushes to mint the binding id, and the FK needs the
        // mailbox to be managed by then.
        $this->em->persist($mailbox);

        $this->labelResolver->bindMailbox(
            $this->labelResolver->systemLabel(LabelRole::Inbox, $account),
            $mailbox,
        );

        $this->em->flush();

        return $mailbox;
    }

    /**
     * Give the Inbox label to messages in this mailbox that carry no label at
     * all.
     *
     * The repair half of the label fix, and deliberately narrow: "no labels"
     * means no view can show the message, which is unambiguously wrong. A
     * message with ANY label is one somebody or something has filed on purpose,
     * and adding Inbox to it would pull it back into a list it was taken out of
     * — which is what archiving is. So this touches only the invisible ones.
     *
     * One query and one flush, and only when there is something to repair: a
     * healthy account finds nothing and pays an indexed count.
     */
    private function labelOrphanedMessages(Mailbox $mailbox): void
    {
        $label = $mailbox->label;

        if (null === $label) {
            return;
        }

        $accountId = (int) $mailbox->account->id;
        $mailboxId = (int) $mailbox->id;

        /** @var list<Message> $orphans */
        $orphans = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Message::class, 'm')
            ->where('m.account = :accountId')
            ->andWhere('m.mailbox = :mailboxId')
            // A DQL subselect cannot name the join table, so this is written as
            // "has no labels" through the collection, which Doctrine compiles
            // to the same NOT EXISTS on message_label.
            ->andWhere('m.labels IS EMPTY')
            ->setParameter('accountId', $accountId)
            ->setParameter('mailboxId', $mailboxId)
            ->getQuery()
            ->getResult();

        if ([] !== $orphans) {
            foreach ($orphans as $orphan) {
                $orphan->addLabel($label);
            }

            $this->logger->info('POP3 sync: gave the Inbox label to messages that had none', [
                'accountId' => $accountId,
                'count'     => count($orphans),
            ]);

            $this->em->flush();
        }

        // The conversations, ALWAYS — and separately from the messages, because
        // they are two different repairs and an account can need only the
        // second. The inbox lists threads, and its query joins the THREAD's
        // labels; a message labelled while its conversation carries none is
        // still invisible.
        //
        // Learned the hard way: the message repair above ran first and did its
        // job, so on the next run there were no orphan messages left and this
        // half never ran at all — the mail stayed invisible while every row
        // looked correct.
        $this->labelUnlabelledThreads($accountId, $mailboxId);
    }

    /**
     * Threads in this mailbox that carry a label their messages have and they
     * do not.
     *
     * Stated as "the union is out of step" rather than "the thread has none",
     * because the threader attaches labels when a message first joins a
     * conversation and never revisits one — so a reply landing on an existing
     * conversation can leave its label on the message and not on the thread.
     * ThreadLabelSynchronizer is the application's own rule for this and is
     * used rather than a hand-written copy.
     */
    private function labelUnlabelledThreads(int $accountId, int $mailboxId): void
    {
        /** @var list<MessageThread> $threads */
        $threads = $this->em->createQueryBuilder()
            ->select('DISTINCT t')
            ->from(MessageThread::class, 't')
            ->join('t.messages', 'm')
            ->where('t.account = :accountId')
            ->andWhere('m.mailbox = :mailboxId')
            ->setParameter('accountId', $accountId)
            ->setParameter('mailboxId', $mailboxId)
            ->getQuery()
            ->getResult();

        $repaired = 0;

        foreach ($threads as $thread) {
            $before = count($thread->labels);

            $this->threadLabels->sync($thread);

            if (count($thread->labels) !== $before) {
                ++$repaired;
            }
        }

        if ($repaired > 0) {
            $this->logger->info('POP3 sync: brought conversation labels back in step with their messages', [
                'accountId' => $accountId,
                'count'     => $repaired,
            ]);

            $this->em->flush();
        }
    }

    /**
     * Parse one RETR answer into a Message row, through the same parser an IMAP
     * fetch goes through.
     *
     * The handover is the whole point of the design: webklex's Message is a
     * MIME parser with a protocol client attached, and fromString() builds one
     * from bytes with no client at all. Everything downstream — the body
     * extraction, the attachment parts, the charset repair, the date fallback
     * — behaves identically to IMAP because it is the same code.
     *
     * Reflective only for buildMessage(), which is private and takes an
     * ImapMessage because that is what it has always taken. Making it public
     * and typed to the parser would be the cleaner change and is a follow-up:
     * this commit's job is to add a protocol, not to refactor a class with
     * twelve call sites.
     */
    private function buildMessage(string $raw, Mailbox $mailbox, int $accountId): Message
    {
        $imapMessage = ImapMessage::fromString($raw, ImapConnectionFactory::config());

        return $this->messageSyncer->buildMessageFromParsed($imapMessage, $mailbox, $accountId);
    }

    /**
     * Record what was stored, and tell the account's sync mark.
     */
    private function rememberStored(Account $account, array $uidls): void
    {
        $account->rememberPop3Uidls(array_values(array_filter($uidls, static fn (mixed $uidl): bool => true === is_string($uidl) && '' !== $uidl)));
        $account->lastSyncedAt = new DateTimeImmutable();

        $this->em->flush();
    }

    private function openSession(Account $account): Pop3Connection
    {
        return Pop3Connection::open(
            (string) $account->imapHost,
            (int) $account->imapPort,
            $account->imapEncryption,
            (string) $account->username,
            (string) $account->password,
            $this->deadline,
        );
    }

    /**
     * The account again, after a session that may have cleared the manager.
     */
    private function refindAccount(Account $account): Account
    {
        $found = $this->accountRepository->find($account->id);

        return $found instanceof Account ? $found : $account;
    }
}
