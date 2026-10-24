<?php

declare(strict_types=1);

namespace App\Service\Pop3;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Helper\ImapConnectionFactory;
use App\Domain\Helper\MessageIdHelper;
use App\Domain\Interface\AccountSyncerInterface;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\Pop3Uidl;
use App\Infrastructure\Pop3\Pop3ConnectionFactory;
use App\Infrastructure\Pop3\Pop3RefusedException;
use App\Infrastructure\Pop3\Pop3Session;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\Pop3UidlRepository;
use App\Service\Imap\MessageSyncer;
use App\Service\Label\LabelResolver;
use App\Service\Mail\PostIngestPipeline;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Throwable;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Bringing a POP3 mailbox into plMail (#289).
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
 * "since" and nothing to ask — so the sync's memory is the pop3_uidl table: a
 * message is new exactly when no row names its UIDL as stored. See Pop3Uidl
 * for why that is a table, and why a message that failed is a row too.
 *
 * UIDL and not the message number, and not the Message-ID. The number is
 * reissued after deletions, so it names a different message next time — and
 * within one sync too, once a session has ended and committed its deletions,
 * which is why every session asks for the UIDL list afresh. The Message-ID is
 * absent from real mail more often than one would like, and the same
 * Message-ID legitimately arrives twice (a copy to two of the user's
 * addresses). The one exception is the pass after an IMAP account became a
 * POP3 one; see Account::$pop3Adopting.
 *
 * ── What is stored, and what may be deleted ──────────────────────────────────
 * A UIDL is recorded as stored only once its row is committed, one message at
 * a time. A message the server would not send or the parser could not build
 * is recorded as an attempt, asked for again on the next sync, and given up on
 * after Pop3Uidl::MAX_ATTEMPTS — left on the server either way. The delete
 * mode only ever marks UIDLs recorded as stored, and only from the session
 * that is about to QUIT; a sync that fails rolls every mark back.
 *
 * ── Where the mail lands ─────────────────────────────────────────────────────
 * One Mailbox row with the Inbox special use, bound to the account's Inbox
 * label (every view is label-driven: a mailbox with no binding is mail nobody
 * can see). Sent and Drafts are label-only: a POP3 server has nowhere to put
 * them, so mail plMail sends is labelled Sent and stays here. See
 * MessageSendService, which appends to a Sent folder only when one exists.
 *
 * ── What cannot work, and is therefore not attempted ─────────────────────────
 *  - Flags: marking read, starring and moving are LOCAL. The next sync does
 *    not undo them, because it only ever inserts messages it has never stored
 *    and never revisits a row it has.
 *  - Pushing changes back: nothing that talks IMAP is reachable for these
 *    rows, because they have no imapUid, and ImapConnectionFactory refuses a
 *    POP3 account outright.
 *  - Deletion in the UI deletes the local row only. The server's copy goes
 *    only if the account's delete mode says so, and only because it is stored.
 */
final readonly class Pop3AccountSyncer implements AccountSyncerInterface
{
    /**
     * Messages fetched per session before the session is recycled.
     *
     * POP3 holds the mailbox locked for the whole transaction, and providers
     * cap both the number of messages per session and how long it may stay
     * open. The cost of recycling is a QUIT, a login and a fresh UIDL list;
     * the cost of not recycling is a server that hangs up half way through a
     * first import.
     */
    public const int BATCH_SIZE = 100;

    /**
     * How many messages a single sync will fetch.
     *
     * The first sync of a mailbox somebody has read for years is thousands of
     * messages, and a sync that runs for an hour holds the mailbox locked
     * against their phone for that hour. The rest is picked up by the next
     * sync, newest first, so what arrives first is what they are waiting for.
     */
    public const int MAX_MESSAGES_PER_SYNC = 500;

    public function __construct(
        private MessageSyncer          $messageSyncer,
        private AccountRepository      $accountRepository,
        private MailboxRepository      $mailboxRepository,
        private MessageRepository      $messageRepository,
        private Pop3UidlRepository     $uidls,
        private LabelResolver          $labelResolver,
        private PostIngestPipeline     $postIngest,
        private Pop3ConnectionFactory  $connections,
        private EntityManagerInterface $em,
        private ManagerRegistry        $registry,
        private LoggerInterface        $logger,
        private int                    $batchSize = self::BATCH_SIZE,
        private int                    $maxPerSync = self::MAX_MESSAGES_PER_SYNC,
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
     *                   follow-up work
     */
    public function sync(Account $account): array
    {
        $accountId = (int) $account->id;
        $mailbox   = $this->mailboxFor($account);
        $mailboxId = (int) $mailbox->id;
        $ledger    = $this->uidls->ledgerFor($account);
        $session   = $this->connections->open($account);
        $stored    = 0;

        try {
            $numbers = $this->numbered($session, $account);

            // What the server no longer lists is gone for good, and its row
            // says nothing any more. This is what bounds the ledger: it never
            // holds more rows than the server has messages.
            $this->uidls->prune($account, array_keys($numbers), $ledger);

            $wanted = [];

            foreach (array_keys($numbers) as $uidl) {
                $known = $ledger[$uidl] ?? null;

                if (null !== $known && (true === $known['stored'] || $known['attempts'] >= Pop3Uidl::MAX_ATTEMPTS)) {
                    continue;
                }

                $wanted[] = (string) $uidl;
            }

            // Newest first. Servers number their mailbox in the order mail
            // arrived, so the end of the list is what came in last — and on a
            // first sync capped at a few hundred, that is the part somebody is
            // waiting to see.
            $wanted   = array_reverse($wanted);
            $complete = count($wanted) <= $this->maxPerSync;

            if (false === $complete) {
                $this->logger->info('POP3 sync: message limit reached; the rest is left for the next sync', [
                    'accountId' => $accountId,
                    'limit'     => $this->maxPerSync,
                    'remaining' => count($wanted) - $this->maxPerSync,
                ]);

                $wanted = array_slice($wanted, 0, $this->maxPerSync);
            }

            foreach (array_chunk($wanted, max(1, $this->batchSize)) as $index => $batch) {
                if (0 !== $index) {
                    // A new session per batch. quit() commits the last one's
                    // marks — none, here: deletion waits for the last session
                    // — and the server may renumber, so the numbers are asked
                    // for again and only UIDLs carry over.
                    $session->quit();

                    $account = $this->accountRepository->find($accountId) ?? $account;
                    $session = $this->connections->open($account);
                    $numbers = $this->numbered($session, $account);
                }

                $stored += $this->fetchBatch($accountId, $mailboxId, $session, $batch, $numbers);
            }

            $account = $this->accountRepository->find($accountId) ?? $account;

            // The delete mode is a standing instruction, not a step of the
            // fetch: everything this account has stored and the server still
            // lists goes, including mail stored before the mode was switched
            // on. From the last session only, so a sync that fails anywhere
            // before its final QUIT has deleted nothing.
            if (true === $account->pop3DeleteMode->deletes()) {
                $this->markStored($account, $session, $numbers);
            }

            // The pass after a protocol switch is over once it has seen the
            // whole listing; from here on a repeated Message-ID is new mail.
            if (true === $complete && true === $account->pop3Adopting) {
                $account->pop3Adopting = false;
                $this->em->flush();
            }

            $session->quit();
        } catch (Throwable $failure) {
            // Nothing marked for deletion is deleted: abort() does not send
            // QUIT, and a POP3 server rolls the transaction back when the
            // session ends any other way. What did store is in the ledger
            // already, row by row, so the next sync does not fetch it again.
            $session->abort();

            throw $failure;
        }

        return 0 === $stored ? [] : [$mailboxId];
    }

    /**
     * Fetch and store one batch, a message at a time.
     *
     * One commit per message, so that "recorded as stored" can only ever mean
     * "this row is in the database". Batched flushes would be faster and would
     * reopen the hole this class is built around: a refused flush closes the
     * manager, a reset drops every row persisted since the last commit, and
     * the UIDLs of those rows would have said they were here.
     *
     * @param list<string>       $batch   UIDLs, in the order to fetch them
     * @param array<string, int> $numbers this session's numbering, UIDL => number
     *
     * @return int how many were stored
     */
    private function fetchBatch(int $accountId, int $mailboxId, Pop3Session $session, array $batch, array $numbers): int
    {
        $account = $this->accountRepository->find($accountId);
        $mailbox = $this->mailboxRepository->find($mailboxId);

        if (null === $account || null === $mailbox) {
            // Deleted while a long first import was running: nowhere to file
            // anything, and nobody to file it for.
            return 0;
        }

        $storedIds = [];
        $raws      = [];

        foreach ($batch as $uidl) {
            $number = $numbers[$uidl] ?? null;

            if (null === $number) {
                // Listed by the session that planned the batch, gone from this
                // one: deleted on the server in between (another client).
                continue;
            }

            if (true === $account->pop3Adopting && true === $this->alreadyHeld($account, $session, $number)) {
                $this->uidls->recordStored($account, [$uidl]);

                continue;
            }

            try {
                $raw = $session->retrieve($number);
            } catch (Pop3RefusedException $refused) {
                // The server will not hand over this one message. The session
                // is fine, so the rest of the batch is still worth fetching. A
                // dropped connection is any other Pop3Exception, and that one
                // ends the sync instead: every later command would fail too,
                // and none of those messages would be at fault.
                $this->failed($account, $uidl, $refused, 'the server would not send it');

                continue;
            }

            try {
                $message = $this->messageSyncer->buildMessageFromParsed(
                    ImapMessage::fromString($raw, ImapConnectionFactory::config()),
                    $mailbox,
                    $accountId,
                );

                // No UID: there is none, and inventing one would point the row
                // at a number the server reassigns. The unique constraint on
                // (mailbox_id, imap_uid) permits any number of NULLs.
                $message->imapUid = null;

                $this->em->persist($message);

                foreach ($message->messageParts as $part) {
                    $this->em->persist($part);
                }

                $this->em->flush();
            } catch (Throwable $failure) {
                // A refused write closes the manager; reopen it so the rest of
                // the batch is not blamed for it. Everything stored before this
                // message is committed already and survives the reset.
                if (false === $this->em->isOpen()) {
                    $this->registry->resetManager();
                }

                $account = $this->accountRepository->find($accountId);
                $mailbox = $this->mailboxRepository->find($mailboxId);

                if (null === $account || null === $mailbox) {
                    throw $failure;
                }

                $this->failed($account, $uidl, $failure, 'it could not be stored');

                continue;
            }

            // Committed: only now may this message be called stored, and so
            // only now may a delete mode ever touch it.
            $this->uidls->recordStored($account, [$uidl]);

            $storedIds[] = (int) $message->id;
            $raws[]      = $raw;
        }

        if ([] === $storedIds) {
            return 0;
        }

        // Found again by id: a reset part way through the batch detached the
        // rows written before it, and the pipeline writes onto them.
        $ingested = [];

        foreach ($storedIds as $index => $id) {
            $message = $this->messageRepository->find($id);

            if (null !== $message) {
                $ingested[] = new IngestedMessage($message, $account, $raws[$index]);
            }
        }

        $this->postIngest->run($account, $ingested);
        $this->em->flush();

        return count($storedIds);
    }

    /**
     * Mark every message this account has stored for deletion, in the session
     * that is about to QUIT.
     *
     * @param array<string, int> $numbers this session's numbering, UIDL => number
     */
    private function markStored(Account $account, Pop3Session $session, array $numbers): void
    {
        foreach ($this->uidls->ledgerFor($account) as $uidl => $state) {
            $number = $numbers[$uidl] ?? null;

            if (false === $state['stored'] || null === $number || true === $session->isMarkedDeleted($uidl)) {
                continue;
            }

            try {
                $session->markDeleted($number, $uidl);
            } catch (Pop3RefusedException $refused) {
                $this->logger->warning('POP3 sync: a message could not be marked for deletion', [
                    'accountId' => $account->id,
                    'uidl'      => $uidl,
                    'error'     => $refused->getMessage(),
                ]);
            }
        }
    }

    /**
     * Whether the account already holds this message, judged by its
     * Message-ID — the adoption pass only; see Account::$pop3Adopting.
     *
     * The headers are read with TOP so that recognising a message does not
     * mean downloading it. A server without TOP gets RETR instead, which costs
     * the bytes and nothing else. A message with no Message-ID cannot be
     * recognised and is fetched; that is a duplicate at worst.
     */
    private function alreadyHeld(Account $account, Pop3Session $session, int $number): bool
    {
        try {
            $head = $session->top($number);
        } catch (Pop3RefusedException) {
            try {
                $head = $session->retrieve($number);
            } catch (Pop3RefusedException) {
                return false;
            }
        }

        $messageId = self::messageIdOf($head);

        if ('' === $messageId) {
            return false;
        }

        return 0 < $this->messageRepository->count(['account' => $account, 'messageId' => $messageId]);
    }

    /**
     * The Message-ID of a header block, normalised the way the column stores
     * it, or '' when there is none.
     *
     * Headers are unfolded first, because a long Message-ID is folded onto
     * the next line as often as not; and the name must start the line, so a
     * DKIM signature listing `message-id` among its signed headers is not
     * mistaken for one.
     */
    public static function messageIdOf(string $raw): string
    {
        $end  = strpos($raw, "\r\n\r\n");
        $head = false === $end ? $raw : substr($raw, 0, $end);
        $head = (string) preg_replace("/\r\n[ \t]+/", ' ', $head);

        if (1 !== preg_match('/^Message-ID:[ \t]*(.+)$/mi', $head, $match)) {
            return '';
        }

        // `<id@host> (a comment)` is legal; the id is what is in the brackets.
        $value = trim($match[1]);

        if (1 === preg_match('/<([^>]+)>/', $value, $bracketed)) {
            $value = $bracketed[1];
        }

        return MessageIdHelper::normalise($value);
    }

    /**
     * This session's numbering, keyed by UIDL.
     *
     * A message the server lists without a UIDL is not one this sync can
     * recognise, so it is left out rather than guessed at: fetched under a
     * made-up identifier, it would be stored again on every sync for ever.
     *
     * @return array<string, int> UIDL => message number, in server order
     */
    private function numbered(Pop3Session $session, Account $account): array
    {
        $listed  = $session->list();
        $numbers = [];

        foreach ($session->uidl() as $number => $uidl) {
            $numbers[$uidl] = $number;
        }

        $unnamed = count($listed) - count($numbers);

        if ($unnamed > 0) {
            $this->logger->warning('POP3 sync: the server listed messages without UIDLs; they cannot be recognised', [
                'accountId' => $account->id,
                'count'     => $unnamed,
            ]);
        }

        asort($numbers);

        return $numbers;
    }

    private function failed(Account $account, string $uidl, Throwable $failure, string $why): void
    {
        $this->uidls->recordFailure($account, $uidl);

        $this->logger->warning('POP3 sync: a message was not fetched, because ' . $why . '; it is asked for again next sync', [
            'accountId' => $account->id,
            'uidl'      => $uidl,
            'error'     => $failure->getMessage(),
            'exception' => $failure,
        ]);
    }

    /**
     * The one mailbox a POP3 account's mail lives in.
     *
     * The account's Inbox-use mailbox when it has one — which, for an account
     * that was IMAP until now, is its IMAP INBOX row, and that is what is
     * wanted: the mail the IMAP sync filed there and the mail POP3 brings in
     * are the same inbox. Otherwise one is created, bound to the Inbox label
     * at once, with nothing to import.
     */
    private function mailboxFor(Account $account): Mailbox
    {
        $existing = $this->mailboxRepository->findOneBy([
            'account'    => $account,
            'specialUse' => MailboxSpecialUse::INBOX,
        ]);

        if (null !== $existing) {
            return $existing;
        }

        $mailbox = new Mailbox();
        $mailbox->account       = $account;
        $mailbox->name          = 'Inbox';
        $mailbox->fullPath      = 'INBOX';
        $mailbox->specialUse    = MailboxSpecialUse::INBOX;
        $mailbox->isSelectable  = true;
        $mailbox->isSyncEnabled = true;

        // The import tracker reads a mailbox as "still importing" while this
        // is null. A POP3 mailbox has no page walk to do — one pass brings in
        // everything the server has — so it is born finished, with the IMAP
        // importer's own "done" value.
        $mailbox->importFloorUid = 0;

        // Persisted before the binding, for the same reason MailboxSyncer does:
        // bindMailbox() flushes to mint the binding id, and the FK needs the
        // mailbox to be managed by then. Without the binding the messages
        // would carry no label, and no view would show them.
        $this->em->persist($mailbox);

        $this->labelResolver->bindMailbox(
            $this->labelResolver->systemLabel(LabelRole::Inbox, $account),
            $mailbox,
        );

        $this->em->flush();

        return $mailbox;
    }
}
