<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\MessageEraser;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\Client;

/**
 * Mail that left the server, and what plMail is allowed to conclude from that.
 *
 * v0.0.25 made a message that moves stay one message. It could not make a
 * message that is deleted stop existing, and said so: there is no vanished-UID
 * detection anywhere in the syncer, because `lastSeenUid+1:*` never looks at an
 * old UID again. A remote move healed only as a side effect of collapsing
 * duplicates — bounded, and needing a Message-ID and a second row. A remote
 * delete produced neither, so it was never reflected at all. Mail the user
 * deleted in Roundcube last month is still in plMail today.
 *
 * ## The rule
 *
 * Absence from one folder's listing is evidence. It is not proof of anything,
 * because a message missing from INBOX is equally consistent with three
 * different events, and from INBOX they are indistinguishable:
 *
 *   - it was deleted;
 *   - it was moved to a folder this cycle has not reached yet;
 *   - the listing was wrong, because the connection wobbled or the server
 *     answered a question about a folder it had just rebuilt.
 *
 * So a sweep never deletes. It writes down that the row went missing and when —
 * Message::$vanishedAt — and leaves the row's address alone, which is what lets
 * the existing v0.0.25 machinery keep working on it: if the message turns up in
 * another folder, that folder's sync meets it as a duplicate Message-ID, probes
 * the source with ImapUidPresence, finds it gone, and relocates the row. The
 * move repairs itself through the path that already existed, and relocateTo()
 * clears the mark on the way past.
 *
 * Deletion is what is left when that does not happen, and it takes two further
 * conditions, neither of which any single listing can satisfy:
 *
 *   1. **Coverage.** Every sync-enabled folder in the account has been listed
 *      in full since the instant the row vanished, and none of them produced
 *      it. That is what MailboxRepository::earliestSweepAcross() computes, and
 *      why it answers null — refusing to reap anything — while even one folder
 *      has never been swept.
 *
 *   2. **Confirmation.** One last tri-state probe of the row's own address,
 *      through the same ImapUidPresence the move reconciliation uses. Present
 *      clears the mark; "could not tell" changes nothing; only an explicit
 *      "not there" from the server erases mail. A sweep that went wrong is
 *      undone here rather than acted on.
 *
 * ## The empty listing
 *
 * A folder that answers `UID SEARCH ALL` with nothing, having held four
 * thousand messages an hour ago, is not a folder somebody emptied by hand. It
 * is a folder that has been rebuilt, restored from backup, or is being served
 * by something that has lost its index — and treating that answer as four
 * thousand deletions is how a mail client destroys a mailbox in one poll.
 *
 * So a sweep refuses itself when it would mark most of a folder as missing at
 * once. Not merely the empty case: the same guard covers "nine tenths of INBOX
 * disappeared", which is the same accident with a less obvious shape. Below
 * BULK_FLOOR rows the guard does not apply, because a small folder genuinely
 * being emptied is an ordinary thing a user does and refusing it forever would
 * mean plMail never notices.
 *
 * A refusal leaves sweptAt untouched, which is deliberate: an unswept folder
 * withholds coverage from the whole account, so a suspicious answer in one
 * folder suspends reaping everywhere until it is understood.
 *
 * ## UIDVALIDITY
 *
 * A changed UIDVALIDITY says every UID stored for this folder now addresses
 * either nothing or somebody else's mail. It says nothing whatsoever about the
 * mail, which the server may still have all of. So it is not a deletion event
 * and must never become one: the rows are *unlocated* — stripped of the UIDs
 * that stopped meaning anything, left in the folder they are in — and the
 * high-water mark is reset so the next sync walks the folder from the start and
 * SentCopyReconciler::claim() re-matches each row by the Message-ID that
 * survived. A rebuild costs one full re-read, and no mail.
 *
 * The vanish marks are cleared in the same breath, and that is not tidiness. A
 * marked row is a candidate for erasure, and after an invalidation none of them
 * has an address left to confirm against, so leaving the marks on would hand
 * the reaper a folder's worth of rows it could only ever answer "cannot tell"
 * about. Rows the re-read fails to re-match stay in place, unlocated: dead
 * weight rather than lost mail, and `app:reset` is the cure.
 */
final readonly class VanishedMessageReconciler
{
    /**
     * How long a folder's full listing stays fresh enough not to redo.
     *
     * The listing is two round trips and a few hundred kilobytes for a large
     * folder, which is far too much for the poll loop and nothing at all on
     * this clock. It also sets the floor on how quickly a remote deletion shows
     * up locally, which is the trade being made: a quarter of an hour.
     */
    public const int SWEEP_INTERVAL_MINUTES = 15;

    /**
     * How many vanished rows one reap will ask the server to confirm.
     *
     * Each is a round trip, and unlike the sweep this one deletes, so it drains
     * rather than sprints: an account that lost a thousand messages to a
     * mailbox clean-out catches up over several polls instead of turning one
     * into a probing run.
     */
    private const int REAP_BATCH = 200;

    /**
     * Below this many rows in a folder, a mass disappearance is believed.
     *
     * The number is a judgement and worth naming as one. Emptying a folder of
     * twenty by hand is routine; emptying one of four thousand between two
     * polls is not something a person did through a mail client, and the
     * asymmetry of being wrong — a duplicate versus destroyed mail — is what
     * puts the floor here rather than at zero.
     */
    private const int BULK_FLOOR = 100;

    /**
     * And above the floor, the share of a folder that vanishing at once is
     * treated as the server being wrong rather than the user being busy.
     */
    private const float BULK_RATIO = 0.5;

    public function __construct(
        private ImapFolderListing      $listing,
        private MessageRepository      $messages,
        private MailboxRepository      $mailboxes,
        private MessageEraser          $eraser,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    /**
     * Compare one folder against the server and write down what is missing.
     *
     * Deletes nothing, ever. See the class docblock.
     *
     * @return bool whether the folder was actually listed and compared
     */
    public function sweep(Mailbox $mailbox, Client $client, ?DateTimeImmutable $now = null): bool
    {
        $now = $now ?? new DateTimeImmutable();

        if (false === $this->isDueForSweep($mailbox, $now)) {
            return false;
        }

        $listing = $this->listing->read($client, $mailbox);

        if (null === $listing) {
            // No answer is not an answer. sweptAt stays where it was, so this
            // folder goes on withholding coverage until it can be read.
            return false;
        }

        return $this->apply($mailbox, $listing, $now);
    }

    /**
     * What a folder listing means, separated from the business of obtaining
     * one.
     *
     * Public because this is where every rule in the class docblock actually
     * lives, and a rule about when mail may be deleted has to be testable
     * against a stated server rather than a real one — the same split
     * MovedMessageReconciliationTest works through, where the test supplies the
     * answer the probe would have given.
     *
     * @param array{uidValidity: int, uids: array<int,true>} $listing
     *
     * @return bool whether the listing was believed and acted on
     */
    public function apply(Mailbox $mailbox, array $listing, ?DateTimeImmutable $now = null): bool
    {
        $now = $now ?? new DateTimeImmutable();

        if (true === $this->isRebuilt($mailbox, $listing['uidValidity'])) {
            $this->invalidate($mailbox, $listing['uidValidity']);

            return false;
        }

        $located = $this->messages->findLocatedUidsById($mailbox);

        $vanished = [];
        $returned = [];

        foreach ($located as $messageId => $uid) {
            if (true === isset($listing['uids'][$uid])) {
                $returned[] = $messageId;

                continue;
            }

            $vanished[] = $messageId;
        }

        if (true === $this->isSuspicious($vanished, $located, $mailbox, $listing['uids'])) {
            return false;
        }

        $marked  = $this->messages->markVanished($vanished, $now);
        $cleared = $this->messages->clearVanished($returned);

        $mailbox->uidValidity = $listing['uidValidity'];
        $mailbox->sweptAt     = $now;

        // The sweep owns this flush. MessageSyncer clears the entity manager
        // between batches of the incremental pass that follows, and a mailbox
        // carrying unflushed changes into that would simply lose them.
        $this->em->flush();

        if ($marked > 0 || $cleared > 0) {
            $this->logger->info('Folder listing compared against local rows', [
                'mailbox'  => $mailbox->fullPath,
                'onServer' => count($listing['uids']),
                'local'    => count($located),
                'vanished' => $marked,
                'returned' => $cleared,
            ]);
        }

        return true;
    }

    /**
     * Erase the rows that stayed missing everywhere, and only those.
     *
     * @param (callable(Mailbox, int): ?bool)|null $stillExists  the tri-state
     *        probe. Null — no way to ask — reaps nothing, because the last word
     *        before a delete has to be the server's.
     *
     * @return int how many rows were erased
     */
    public function reap(Account $account, ?callable $stillExists): int
    {
        if (null === $stillExists) {
            return 0;
        }

        $cutoff = $this->mailboxes->earliestSweepAcross($account);

        if (null === $cutoff) {
            // At least one folder has never been listed, so the account has no
            // moment at which "nowhere has it" is a true statement yet.
            return 0;
        }

        $candidates = $this->messages->findReapable($account, $cutoff, self::REAP_BATCH);

        if (0 === count($candidates)) {
            return 0;
        }

        $erased    = 0;
        $recovered = 0;

        foreach ($candidates as $candidate) {
            $mailbox = $candidate->mailbox;
            $uid     = $candidate->imapUid;

            if (null === $mailbox || null === $uid) {
                // Unlocated: a UIDVALIDITY rebuild, or a row waiting for the
                // copy of itself the server has not filed yet. There is no
                // address to confirm, so there is nothing to conclude.
                continue;
            }

            $present = $stillExists($mailbox, $uid);

            if (true === $present) {
                // The sweep was wrong, or the mail came back. Either way the
                // server has it, and that outranks anything written down here.
                $candidate->vanishedAt = null;
                ++$recovered;

                continue;
            }

            if (null === $present) {
                continue;
            }

            $this->eraser->erase($candidate);
            ++$erased;
        }

        $this->em->flush();

        if ($erased > 0 || $recovered > 0) {
            $this->logger->info('Reconciled messages the server no longer has', [
                'account'   => $account->id,
                'erased'    => $erased,
                'recovered' => $recovered,
            ]);
        }

        return $erased;
    }

    /**
     * Whether this folder is far enough past its last full listing to be worth
     * listing again.
     *
     * Public because it is the cost control, and a cost control nobody can
     * assert on is one that quietly stops working. It is also the floor on how
     * quickly a remote deletion can show up locally, which makes it a stated
     * behaviour rather than an implementation detail.
     */
    public function isDueForSweep(Mailbox $mailbox, ?DateTimeImmutable $now = null): bool
    {
        $now     = $now ?? new DateTimeImmutable();
        $sweptAt = $mailbox->sweptAt;

        if (null === $sweptAt) {
            return true;
        }

        return $sweptAt < $now->modify('-' . self::SWEEP_INTERVAL_MINUTES . ' minutes');
    }

    /**
     * A UIDVALIDITY we have seen before and that has changed. An unknown one is
     * not a change — there is nothing to have changed from — and adopting it
     * silently is safe because the comparison that follows is guarded: if the
     * stored UIDs really do belong to an older validity, every one of them will
     * read as missing at once and the bulk guard refuses the sweep.
     */
    private function isRebuilt(Mailbox $mailbox, int $uidValidity): bool
    {
        $known = $mailbox->uidValidity;

        return null !== $known && $known !== $uidValidity;
    }

    private function invalidate(Mailbox $mailbox, int $uidValidity): void
    {
        $unlocated = $this->messages->unlocateAll($mailbox);

        $mailbox->uidValidity = $uidValidity;
        $mailbox->lastSeenUid = 0;
        $mailbox->sweptAt     = null;

        $this->em->flush();

        $this->logger->warning('Folder was rebuilt on the server; every stored UID for it is void', [
            'mailbox'     => $mailbox->fullPath,
            'uidValidity' => $uidValidity,
            'unlocated'   => $unlocated,
        ]);
    }

    /**
     * @param list<int>        $vanished
     * @param array<int,int>   $located
     * @param array<int,true>  $onServer
     */
    private function isSuspicious(array $vanished, array $located, Mailbox $mailbox, array $onServer): bool
    {
        $missing = count($vanished);
        $total   = count($located);

        if ($missing < self::BULK_FLOOR) {
            return false;
        }

        if ($missing < (int) ceil($total * self::BULK_RATIO)) {
            return false;
        }

        $this->logger->warning('Refusing a folder listing that would mark most of the folder as gone', [
            'mailbox'  => $mailbox->fullPath,
            'local'    => $total,
            'onServer' => count($onServer),
            'missing'  => $missing,
            'reason'   => 0 === count($onServer)
                ? 'the folder listed empty, which reads as a rebuild rather than a purge'
                : 'too much of the folder disappeared between two listings',
        ]);

        return true;
    }
}
