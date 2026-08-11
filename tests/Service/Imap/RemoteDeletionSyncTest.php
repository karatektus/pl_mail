<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\SentCopyReconciler;
use App\Service\Imap\VanishedMessageReconciler;
use App\Service\Label\LabelResolver;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Mail that left the server, and how long plMail takes to believe it.
 *
 * v0.0.25 fixed the message that moves. It left the message that is *deleted*
 * untouched and said so in as many words: incremental sync asks for
 * `lastSeenUid+1:*`, so no UID below the high-water mark is ever looked at
 * again, and mail deleted in another client stayed in plMail indefinitely.
 * External moves healed only as a side effect of collapsing duplicate
 * Message-IDs, which needs two rows and an id to work with — a deletion leaves
 * neither.
 *
 * The rule these tests pin is that absence is evidence and never proof. Each
 * one states what the server said, exactly as MovedMessageReconciliationTest
 * states what the probe answered, and asserts what plMail was entitled to
 * conclude from it. The cases that assert *nothing happened* are the important
 * half: a listing that comes back empty, a folder that was rebuilt, a probe
 * that could not answer.
 */
final class RemoteDeletionSyncTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private VanishedMessageReconciler $vanished;
    private SentCopyReconciler $reconciler;
    private MessageRepository $messages;
    private LabelResolver $labels;

    private User $user;
    private Account $account;
    private Mailbox $inbox;
    private Mailbox $archive;
    private Mailbox $trash;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->vanished   = $container->get(VanishedMessageReconciler::class);
        $this->reconciler = $container->get(SentCopyReconciler::class);
        $this->messages   = $container->get(MessageRepository::class);
        $this->labels     = $container->get(LabelResolver::class);

        $this->connection->beginTransaction();

        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── the gap v0.0.25 named ─────────────────────────────────────────────

    /**
     * The reported behaviour, from the other side: incremental sync cannot see
     * a UID it has already passed, so nothing about a poll notices the message
     * is gone. This is the state before the sweep exists — and it is still the
     * state after one poll, because a poll does not sweep.
     */
    public function testAnIncrementalPollAloneNeverNoticesAMessageHasGone(): void
    {
        $message = $this->incoming('Rechnung', $this->inbox, 6);

        // The high-water mark is above this message, which is the whole point:
        // every future incremental range starts past it.
        $this->inbox->lastSeenUid = 40;
        $this->em->flush();

        self::assertNull($message->vanishedAt, 'nothing has looked, so nothing is known');
        self::assertNotNull($this->messages->find((int) $message->id));
    }

    /**
     * The sweep looks, and writing down that it looked is all it does. The row
     * keeps its address — which is what lets the move machinery still work on
     * it — and the mail is still there.
     */
    public function testASweepRecordsTheAbsenceAndDeletesNothing(): void
    {
        $message = $this->incoming('Rechnung', $this->inbox, 6);

        $this->sweep($this->inbox, [], now: '2026-08-11 09:00:00');

        self::assertNotNull($message->vanishedAt, 'the folder did not produce it, and that is worth recording');
        self::assertSame(6, $message->imapUid, 'but the address stays: it is what a probe can still be pointed at');
        self::assertNotNull($this->messages->find((int) $message->id), 'and one listing never deletes mail');
    }

    /**
     * A message that comes back — because it was there all along and the
     * listing was momentarily wrong — has its mark taken off by the next
     * listing that does produce it.
     */
    public function testAMessageTheNextListingProducesLosesItsMark(): void
    {
        $message = $this->incoming('Rechnung', $this->inbox, 6);

        $this->sweep($this->inbox, [], now: '2026-08-11 09:00:00');
        self::assertNotNull($message->vanishedAt);

        $this->sweep($this->inbox, [6], now: '2026-08-11 10:00:00');

        self::assertNull($message->vanishedAt, 'the server has it, which outranks anything written down');
    }

    // ── remote delete, the whole way through ──────────────────────────────

    /**
     * The user's sentence: "remote deleted email should be deleted as well."
     *
     * Every folder has been listed since the message went missing, none of them
     * produced it, and the final probe of its own address confirms it. Only
     * then does the row go.
     */
    public function testAMessageDeletedOnTheServerIsDeletedLocally(): void
    {
        $message = $this->incoming('Rechnung', $this->inbox, 6);
        $rowId   = (int) $message->id;

        $this->sweepAll(vanishing: [[$this->inbox, 6]], now: '2026-08-11 09:00:00');

        self::assertSame(1, $this->reap(server: []), 'the server confirms it is not there');

        self::assertNull($this->messages->find($rowId), 'the row goes');
    }

    /**
     * Coverage is a precondition, not a formality. While any folder in the
     * account has never been listed in full, "it is nowhere" is not a statement
     * plMail is in a position to make, so nothing is reaped however old the
     * mark is.
     */
    public function testNothingIsReapedWhileAnyFolderHasNeverBeenListed(): void
    {
        $message = $this->incoming('Rechnung', $this->inbox, 6);

        // Only INBOX is swept. Archive and Trash have never been looked in.
        $this->sweep($this->inbox, [], now: '2026-08-11 09:00:00');

        self::assertSame(0, $this->reap(server: []), 'two folders are unaccounted for');
        self::assertNotNull($this->messages->find((int) $message->id));
    }

    /**
     * The mark has to predate the coverage, not merely coexist with it. A
     * message that went missing *after* a folder was last listed has not been
     * looked for in that folder yet.
     */
    public function testAMarkYoungerThanTheCoverageIsNotYetReapable(): void
    {
        $message = $this->incoming('Rechnung', $this->inbox, 6);

        $this->sweepAll(vanishing: [], now: '2026-08-11 09:00:00');

        // It goes missing an hour after the last folder was listed.
        $this->sweep($this->inbox, [], now: '2026-08-11 10:00:00');

        self::assertSame(0, $this->reap(server: []), 'no folder has been listed since it vanished');
        self::assertNotNull($this->messages->find((int) $message->id));
    }

    /**
     * The last word before a delete is the server's, and "I could not tell" is
     * not that word. This is the v0.0.25 discipline applied to the one
     * operation that cannot be undone by the next poll.
     */
    public function testAnUnanswerableProbeNeverDeletesMail(): void
    {
        $message = $this->incoming('Rechnung', $this->inbox, 6);

        $this->sweepAll(vanishing: [[$this->inbox, 6]], now: '2026-08-11 09:00:00');

        $erased = $this->vanished->reap(
            $this->account,
            static fn (Mailbox $mailbox, int $uid): ?bool => null,
        );

        self::assertSame(0, $erased);
        self::assertNotNull($this->messages->find((int) $message->id));
    }

    public function testWithNoWayToAskTheServerNothingIsDeleted(): void
    {
        $this->incoming('Rechnung', $this->inbox, 6);

        $this->sweepAll(vanishing: [[$this->inbox, 6]], now: '2026-08-11 09:00:00');

        self::assertSame(0, $this->vanished->reap($this->account, null));
        self::assertSame(1, $this->countRowsForAccount());
    }

    /**
     * The sweep can be wrong, and the confirmation is where that gets caught.
     * A row the probe finds after all keeps its place and loses its mark.
     */
    public function testAProbeThatFindsTheMessageUndoesTheMark(): void
    {
        $message = $this->incoming('Rechnung', $this->inbox, 6);

        $this->sweepAll(vanishing: [[$this->inbox, 6]], now: '2026-08-11 09:00:00');
        self::assertNotNull($message->vanishedAt);

        self::assertSame(0, $this->reap(server: [[$this->inbox, 6]]));

        self::assertNull($message->vanishedAt, 'the server has it, so the sweep was wrong');
        self::assertNotNull($this->messages->find((int) $message->id));
    }

    /**
     * A remote deletion is a JMAP destroy, and has to reach the change log as
     * one.
     *
     * This is the difference between deleting a row and deleting a message. A
     * client holding this id — ltt.rs, a phone — must be told to drop it, not
     * left to discover a 404 or, worse, to go on showing mail the server threw
     * away. It is a genuine `destroyed` rather than the `updated` that
     * Email/set destroy produces, because that one moves to Trash and keeps the
     * row while this one takes the id away.
     */
    public function testARemoteDeletionIsAnnouncedToJmapClientsAsADestroy(): void
    {
        $message = $this->incoming('Rechnung', $this->inbox, 6);
        $rowId   = (int) $message->id;
        $thread  = $message->thread;

        $this->sweepAll(vanishing: [[$this->inbox, 6]], now: '2026-08-11 09:00:00');

        $since = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(sequence), 0) FROM jmap_change_log');

        self::assertSame(1, $this->reap(server: []));

        $rows = $this->connection->fetchAllAssociative(
            'SELECT object_type, change_type, entity_id FROM jmap_change_log WHERE sequence > ? ORDER BY sequence',
            [$since],
        );

        $announced = array_map(
            static fn (array $row): string => $row['object_type'] . ':' . $row['change_type'] . ':' . $row['entity_id'],
            $rows,
        );

        self::assertContains('Email:destroyed:' . $rowId, $announced);
        self::assertContains('Thread:updated:' . $thread->id, $announced, 'the conversation it left changed too');
    }

    /**
     * And the thread it leaves stops wearing labels no message in it carries
     * any more — the same rule a move obeys, arrived at from the other side. A
     * conversation whose only INBOX message was deleted is not in INBOX.
     */
    public function testAThreadStopsWearingTheLabelOfAMessageThatWasDeletedRemotely(): void
    {
        $thread = $this->thread('Gemischt');

        $this->rowIn($this->inbox, MessageIdHelper::mint('a@example.test'), 6, 'Gemischt', $thread);
        $this->rowIn($this->archive, MessageIdHelper::mint('b@example.test'), 210, 'Gemischt', $thread);
        $this->em->flush();

        $inboxLabel = $this->labels->systemLabel(LabelRole::Inbox, $this->account);
        $thread->addLabel($inboxLabel);
        $thread->addLabel($this->labels->systemLabel(LabelRole::Archive, $this->account));
        $this->em->flush();

        $this->sweepAll(vanishing: [[$this->inbox, 6]], now: '2026-08-11 09:00:00');

        self::assertSame(1, $this->reap(server: [[$this->archive, 210]]));

        self::assertFalse($thread->hasLabel($inboxLabel), 'no message of it is in INBOX any more');
        self::assertTrue($thread->hasLabel($this->labels->systemLabel(LabelRole::Archive, $this->account)));
    }

    // ── delete versus move, which look identical from the folder left ─────

    /**
     * The distinction the whole design turns on. A message missing from INBOX
     * and present in Archive is a move, and the existing v0.0.25 path is what
     * settles it: the row still has the address the probe needs, Archive's sync
     * meets the duplicate Message-ID, the probe says INBOX no longer has it,
     * and the row is relocated rather than doubled — or reaped.
     */
    public function testAMessageMovedOnTheServerIsRelocatedRatherThanDeleted(): void
    {
        $message   = $this->incoming('Angebot', $this->inbox, 6);
        $messageId = (string) $message->messageId;
        $rowId     = (int) $message->id;

        $this->sweep($this->inbox, [], now: '2026-08-11 09:00:00');
        self::assertNotNull($message->vanishedAt, 'INBOX no longer lists it');

        // Archive's incremental sync meets it under a UID it has never seen.
        $claimed = $this->reconciler->claim(
            $this->archive,
            $messageId,
            210,
            $this->serverHolding([[$this->archive, 210]]),
        );
        $this->em->flush();

        self::assertNotNull($claimed, 'the copy INBOX had is gone, so this is that message at a new address');
        self::assertSame($rowId, (int) $claimed->id);
        self::assertSame($this->archive->id, $claimed->mailbox?->id);
        self::assertNull($claimed->vanishedAt, 'and it is not missing any more — it was found');

        self::assertSame(1, $this->countRowsFor($messageId), 'one message, one row');
    }

    /**
     * And having been relocated, it must not then be reaped for having gone
     * missing from the folder it left. This is the ordering hazard the mark
     * exists to survive.
     */
    public function testAMessageThatMovedIsNotReapedAfterwards(): void
    {
        $message = $this->incoming('Angebot', $this->inbox, 6);

        $this->sweep($this->inbox, [], now: '2026-08-11 09:00:00');

        $this->reconciler->claim(
            $this->archive,
            (string) $message->messageId,
            210,
            $this->serverHolding([[$this->archive, 210]]),
        );
        $this->em->flush();

        $this->sweepAll(vanishing: [], now: '2026-08-11 09:30:00');

        self::assertSame(0, $this->reap(server: [[$this->archive, 210]]));
        self::assertNotNull($this->messages->find((int) $message->id));
    }

    /**
     * A remote move of a message plMail has no Message-ID for cannot be
     * recognised as a move — there is no identity to re-match on — so it ends
     * as a delete in the folder it left and an insert in the one it arrived in.
     *
     * Stated rather than fixed. Message-ID is the only identity that survives a
     * move, and inferring one from content is exactly the guess v0.0.23 refused
     * to make.
     */
    public function testAMoveOfAMessageWithNoMessageIdReadsAsADelete(): void
    {
        $message = $this->incoming('Ohne Id', $this->inbox, 6);
        $message->messageId = null;
        $this->em->flush();

        $this->sweepAll(vanishing: [[$this->inbox, 6]], now: '2026-08-11 09:00:00');

        self::assertSame(1, $this->reap(server: []), 'nothing identifies it elsewhere, so it reads as gone');
    }

    // ── trash, both of its endings ───────────────────────────────────────

    /**
     * A message deleted remotely out of INBOX while plMail shows it in INBOX
     * lands in Trash if that is where the other client put it — which is a
     * move, and arrives as one.
     */
    public function testARemoteDeleteThatIsReallyAMoveToTrashEndsInTrash(): void
    {
        $message = $this->incoming('Werbung', $this->inbox, 6);

        $this->sweep($this->inbox, [], now: '2026-08-11 09:00:00');

        $this->reconciler->claim(
            $this->trash,
            (string) $message->messageId,
            91,
            $this->serverHolding([[$this->trash, 91]]),
        );
        $this->em->flush();

        self::assertSame($this->trash->id, $message->mailbox?->id);
        self::assertTrue(
            $message->hasLabel($this->labels->systemLabel(LabelRole::Trash, $this->account)),
            'and plMail shows it where the server put it',
        );
    }

    /**
     * And the other ending: expunged out of Trash, which is the point at which
     * the message stops existing anywhere and the row has to go with it.
     */
    public function testAMessageExpungedFromTrashIsRemovedEntirely(): void
    {
        $message = $this->incoming('Werbung', $this->trash, 91);
        $rowId   = (int) $message->id;

        $this->sweepAll(vanishing: [[$this->trash, 91]], now: '2026-08-11 09:00:00');

        self::assertSame(1, $this->reap(server: []));
        self::assertNull($this->messages->find($rowId), 'emptying Trash on the server empties it here');
    }

    /**
     * \Deleted-but-not-expunged is not a deletion. The message is still in the
     * folder and still in `UID SEARCH ALL`, so the sweep sees it and nothing
     * happens — which is correct, and is why the sweep asks the folder what it
     * holds rather than asking about flags.
     */
    public function testAMessageFlaggedDeletedButNotExpungedIsLeftAlone(): void
    {
        $message = $this->incoming('Halb geloescht', $this->inbox, 6);
        $message->flags = ['Deleted'];
        $this->em->flush();

        $this->sweepAll(vanishing: [], now: '2026-08-11 09:00:00');

        self::assertNull($message->vanishedAt, 'the folder still lists it, so it is still there');
        self::assertSame(0, $this->reap(server: [[$this->inbox, 6]]));
        self::assertNotNull($this->messages->find((int) $message->id));
    }

    // ── the copy in two folders ──────────────────────────────────────────

    /**
     * Plain IMAP lets one message exist in two folders, and deleting one copy
     * must not take the other. The rows are separate addresses and the sweep
     * treats them separately.
     */
    public function testDeletingOneCopyOfAMultiFolderMessageLeavesTheOther(): void
    {
        $messageId = MessageIdHelper::mint('kunde@example.test');
        $thread    = $this->thread('Rundschreiben');

        $inboxCopy   = $this->rowIn($this->inbox, $messageId, 6, 'Rundschreiben', $thread);
        $archiveCopy = $this->rowIn($this->archive, $messageId, 210, 'Rundschreiben', $thread);
        $this->em->flush();

        $keptId = (int) $archiveCopy->id;
        $goneId = (int) $inboxCopy->id;

        $this->sweepAll(vanishing: [[$this->inbox, 6]], now: '2026-08-11 09:00:00');

        self::assertSame(1, $this->reap(server: [[$this->archive, 210]]));

        self::assertNull($this->messages->find($goneId), 'the copy that was deleted goes');
        self::assertNotNull($this->messages->find($keptId), 'and the copy that was not stays');
    }

    // ── a whole conversation ─────────────────────────────────────────────

    /**
     * Deleting a thread on the server deletes its messages here, and the thread
     * they were in is recounted rather than decremented — the drift that made
     * a header claim "3 messages" over two.
     */
    public function testDeletingAWholeThreadRemotelyEmptiesItAndRecountsIt(): void
    {
        $thread = $this->thread('Langer Verlauf');

        $first  = $this->rowIn($this->inbox, MessageIdHelper::mint('a@example.test'), 6, 'Langer Verlauf', $thread);
        $second = $this->rowIn($this->inbox, MessageIdHelper::mint('b@example.test'), 7, 'Langer Verlauf', $thread);
        $third  = $this->rowIn($this->inbox, MessageIdHelper::mint('c@example.test'), 8, 'Langer Verlauf', $thread);
        $this->em->flush();

        self::assertSame(3, $thread->messageCount);

        $this->sweepAll(vanishing: [[$this->inbox, 6], [$this->inbox, 7], [$this->inbox, 8]], now: '2026-08-11 09:00:00');

        self::assertSame(3, $this->reap(server: []));

        self::assertSame(0, $thread->messages->count());
        self::assertSame(0, $thread->messageCount, 'recounted from what is left, not decremented');
        self::assertSame(0, $thread->unreadCount);
    }

    /**
     * Deleting part of a thread recounts the rest, including the counters a
     * list row is rendered from.
     */
    public function testDeletingPartOfAThreadRecountsWhatRemains(): void
    {
        $thread = $this->thread('Teilweise');

        $this->rowIn($this->inbox, MessageIdHelper::mint('a@example.test'), 6, 'Teilweise', $thread);
        $unread = $this->rowIn($this->inbox, MessageIdHelper::mint('b@example.test'), 7, 'Teilweise', $thread);
        $unread->seenAt = null;
        $this->em->flush();

        $this->sweepAll(vanishing: [[$this->inbox, 6]], now: '2026-08-11 09:00:00');

        self::assertSame(1, $this->reap(server: [[$this->inbox, 7]]));

        self::assertSame(1, $thread->messageCount);
        self::assertSame(1, $thread->unreadCount, 'the unread one is the one that stayed');
    }

    // ── the guards ───────────────────────────────────────────────────────

    /**
     * The accident this class exists to not have. A folder that held thousands
     * and lists empty has been rebuilt, restored, or is being served by
     * something that lost its index — and reading that as thousands of
     * deletions is how a mail client destroys a mailbox in one poll.
     */
    public function testAFolderThatSuddenlyListsEmptyIsTreatedAsSuspicionNotAsAPurge(): void
    {
        for ($uid = 1; $uid <= 400; ++$uid) {
            $this->rowIn($this->inbox, MessageIdHelper::mint('bulk@example.test'), $uid, 'Bulk', $this->thread('Bulk'));
        }

        $this->em->flush();

        $believed = $this->sweep($this->inbox, [], now: '2026-08-11 09:00:00');

        self::assertFalse($believed, 'the listing is refused');
        self::assertNull($this->inbox->sweptAt, 'and the folder counts as unswept, which suspends reaping account-wide');
        self::assertSame(400, $this->countRowsForAccount(), 'nothing is even marked');
    }

    /**
     * Not only the empty case. Nine tenths of a folder disappearing between two
     * listings is the same accident wearing a less obvious shape.
     */
    public function testMostOfAFolderDisappearingAtOnceIsAlsoRefused(): void
    {
        for ($uid = 1; $uid <= 400; ++$uid) {
            $this->rowIn($this->inbox, MessageIdHelper::mint('bulk@example.test'), $uid, 'Bulk', $this->thread('Bulk'));
        }

        $this->em->flush();

        $believed = $this->sweep($this->inbox, range(1, 40), now: '2026-08-11 09:00:00');

        self::assertFalse($believed);
        self::assertSame(400, $this->countRowsForAccount());
    }

    /**
     * And the counter-case, which is what keeps the guard from being a refusal
     * to ever notice anything: a small folder genuinely emptied is an ordinary
     * thing a person does, and it is believed.
     */
    public function testASmallFolderGenuinelyEmptiedIsBelieved(): void
    {
        $this->incoming('Eins', $this->archive, 210);
        $this->incoming('Zwei', $this->archive, 211);

        $believed = $this->sweep($this->archive, [], now: '2026-08-11 09:00:00');

        self::assertTrue($believed);
        self::assertNotNull($this->archive->sweptAt);
    }

    // ── UIDVALIDITY ──────────────────────────────────────────────────────

    /**
     * A rebuilt folder invalidates every UID stored for it and no mail at all.
     * The rows are unlocated — which is the state claim() re-matches by
     * Message-ID — and the high-water mark is reset so the re-read starts from
     * the beginning.
     */
    public function testAUidValidityChangeVoidsTheAddressesAndKeepsTheMail(): void
    {
        $first  = $this->incoming('Eins', $this->inbox, 6);
        $second = $this->incoming('Zwei', $this->inbox, 7);

        $this->inbox->uidValidity = 111;
        $this->inbox->lastSeenUid = 7;
        $this->em->flush();

        $believed = $this->sweep($this->inbox, [1, 2], now: '2026-08-11 09:00:00', uidValidity: 222);

        self::assertFalse($believed, 'a rebuild is not a comparison');

        $this->em->refresh($first);
        $this->em->refresh($second);

        self::assertNull($first->imapUid, 'the address it had means nothing now');
        self::assertNull($second->imapUid);
        self::assertNull($first->vanishedAt, 'and an unlocated row is not a missing one');
        self::assertSame(2, $this->countRowsForAccount(), 'no mail was deleted');
        self::assertSame(0, $this->inbox->lastSeenUid, 'the folder is re-read from the start');
        self::assertSame(222, $this->inbox->uidValidity);
    }

    /**
     * And the rows the re-read produces are re-matched onto themselves by
     * Message-ID rather than inserted beside themselves, which is the whole
     * reason unlocating is safe.
     */
    public function testAfterARebuildTheRowsAreReMatchedByMessageId(): void
    {
        $message   = $this->incoming('Eins', $this->inbox, 6);
        $messageId = (string) $message->messageId;
        $rowId     = (int) $message->id;

        $this->inbox->uidValidity = 111;
        $this->em->flush();

        $this->sweep($this->inbox, [1], now: '2026-08-11 09:00:00', uidValidity: 222);

        // The re-read meets it under the UID the rebuilt folder issued.
        $claimed = $this->reconciler->claim($this->inbox, $messageId, 1);
        $this->em->flush();

        self::assertNotNull($claimed, 'the row has no address, which is exactly what claim() resolves');
        self::assertSame($rowId, (int) $claimed->id);
        self::assertSame(1, $claimed->imapUid);
        self::assertSame(1, $this->countRowsFor($messageId), 'one message, one row');
    }

    /**
     * A folder whose UIDVALIDITY plMail has never recorded is not a folder that
     * changed — there is nothing to have changed from. It is adopted, and the
     * comparison is guarded anyway: stored UIDs belonging to an older validity
     * would all read as missing at once, which is what the bulk guard refuses.
     */
    public function testAnUnknownUidValidityIsAdoptedRatherThanTreatedAsAChange(): void
    {
        $this->incoming('Eins', $this->inbox, 6);

        self::assertNull($this->inbox->uidValidity);

        $believed = $this->sweep($this->inbox, [6], now: '2026-08-11 09:00:00', uidValidity: 333);

        self::assertTrue($believed);
        self::assertSame(333, $this->inbox->uidValidity);
    }

    // ── cadence ──────────────────────────────────────────────────────────

    /**
     * The listing is two round trips and a response measured in hundreds of
     * kilobytes, so it is not in the poll loop. A folder swept a minute ago is
     * left alone; one swept an hour ago is not.
     */
    public function testAFolderSweptRecentlyIsNotSweptAgain(): void
    {
        $now = new DateTimeImmutable('2026-08-11 09:00:00');

        $this->inbox->sweptAt = new DateTimeImmutable('2026-08-11 08:59:00');

        self::assertFalse($this->vanished->isDueForSweep($this->inbox, $now));

        $this->inbox->sweptAt = new DateTimeImmutable('2026-08-11 08:00:00');

        self::assertTrue($this->vanished->isDueForSweep($this->inbox, $now));
    }

    /**
     * A folder nobody has ever listed is always due, which is what gets the
     * account to its first complete coverage at all.
     */
    public function testAFolderNeverSweptIsAlwaysDue(): void
    {
        self::assertNull($this->inbox->sweptAt);
        self::assertTrue($this->vanished->isDueForSweep($this->inbox, new DateTimeImmutable('2026-08-11 09:00:00')));
    }

    // ── fixture ───────────────────────────────────────────────────────────

    /**
     * One folder listing, stated the way the server would have answered it.
     *
     * @param list<int> $uids  every UID the folder holds
     */
    private function sweep(
        Mailbox $mailbox,
        array   $uids,
        string  $now,
        int     $uidValidity = 1,
    ): bool {
        $set = [];

        foreach ($uids as $uid) {
            $set[$uid] = true;
        }

        $believed = $this->vanished->apply(
            $mailbox,
            ['uidValidity' => $uidValidity, 'uids' => $set],
            new DateTimeImmutable($now),
        );

        // markVanished() and unlocateAll() are bulk updates, which by design go
        // round the identity map rather than through it — the whole reason they
        // exist is not to hydrate a folder's worth of entities to write one
        // column. So anything the test is still holding is now stale, and has
        // to be re-read before it is asserted on.
        $this->rereadMessages();

        return $believed;
    }

    /**
     * The account listed in full, twice, with the same answer both times.
     *
     * Two passes rather than one because that is genuinely what reaping costs.
     * A mark records the instant a row went missing, and the coverage that
     * justifies acting on it has to *postdate* that instant — a folder listed
     * at the same moment the row vanished has not been looked in since. So the
     * first pass marks and the second pass provides the coverage, which in
     * production is two poll cycles a sweep interval apart and is the floor on
     * how quickly a remote deletion can propagate.
     *
     * testAMarkYoungerThanTheCoverageIsNotYetReapable pins the other side of
     * that rule, using single passes.
     *
     * @param list<array{Mailbox, int}> $vanishing  the addresses the server
     *        should NOT list. Everything else the account holds is listed as
     *        present, which is what makes this a whole-account listing rather
     *        than a folder's.
     */
    private function sweepAll(array $vanishing, string $now): void
    {
        $this->listAll($vanishing, $now);
        $this->listAll($vanishing, (new DateTimeImmutable($now))->modify('+1 hour')->format('Y-m-d H:i:s'));
    }

    /**
     * @param list<array{Mailbox, int}> $vanishing
     */
    private function listAll(array $vanishing, string $now): void
    {
        $missing = [];

        foreach ($vanishing as [$mailbox, $uid]) {
            $missing[$mailbox->id . ':' . $uid] = true;
        }

        foreach ([$this->inbox, $this->archive, $this->trash] as $mailbox) {
            $present = [];

            foreach ($this->messages->findLocatedUidsById($mailbox) as $uid) {
                if (true === isset($missing[$mailbox->id . ':' . $uid])) {
                    continue;
                }

                $present[] = $uid;
            }

            $this->sweep($mailbox, $present, $now);
        }
    }

    /**
     * Re-read every Message the test still holds. See sweep().
     */
    private function rereadMessages(): void
    {
        $map = $this->em->getUnitOfWork()->getIdentityMap();

        foreach ($map[Message::class] ?? [] as $message) {
            $this->em->refresh($message);
        }
    }

    /**
     * @param list<array{Mailbox, int}> $server  the addresses that still exist
     */
    private function reap(array $server): int
    {
        $erased = $this->vanished->reap($this->account, $this->serverHolding($server));

        $this->em->flush();

        return $erased;
    }

    /**
     * @param list<array{Mailbox, int}> $present
     *
     * @return callable(Mailbox, int): bool
     */
    private function serverHolding(array $present): callable
    {
        $addresses = [];

        foreach ($present as [$mailbox, $uid]) {
            $addresses[$mailbox->id . ':' . $uid] = true;
        }

        return static fn (Mailbox $mailbox, int $uid): bool
            => true === isset($addresses[$mailbox->id . ':' . $uid]);
    }

    private function incoming(string $subject, Mailbox $mailbox, int $uid): Message
    {
        $thread  = $this->thread($subject);
        $message = $this->rowIn($mailbox, MessageIdHelper::mint('kunde@example.test'), $uid, $subject, $thread);

        $this->em->flush();

        return $message;
    }

    private function thread(string $subject): MessageThread
    {
        $thread = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->messageCount      = 0;
        $thread->unreadCount       = 0;
        $thread->attachmentCount   = 0;

        $this->em->persist($thread);

        return $thread;
    }

    private function rowIn(
        Mailbox       $mailbox,
        string        $messageId,
        int           $uid,
        string        $subject,
        MessageThread $thread,
    ): Message {
        $message = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $mailbox;
        $message->imapUid        = $uid;
        $message->messageId      = $messageId;
        $message->subject        = $subject;
        $message->fromAddress    = 'kunde@example.test';
        $message->bodyText       = $subject;
        $message->hasAttachments = false;
        $message->seenAt         = new DateTimeImmutable();
        $message->flags          = [];
        $message->receivedAt     = new DateTimeImmutable('2026-08-10 09:00:00');
        $message->sentAt         = $message->receivedAt;

        $mailboxLabel = $mailbox->label;

        if (null !== $mailboxLabel) {
            $message->addLabel($mailboxLabel);
        }

        $this->em->persist($message);

        $thread->addMessage($message);
        $thread->messageCount = $thread->messageCount + 1;

        return $message;
    }

    private function countRowsFor(string $messageId): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM ' . Message::class . ' m WHERE m.account = :account AND m.messageId = :messageId',
        )
            ->setParameter('account', $this->account)
            ->setParameter('messageId', $messageId)
            ->getSingleScalarResult();
    }

    private function countRowsForAccount(): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM ' . Message::class . ' m WHERE m.account = :account',
        )->setParameter('account', $this->account)->getSingleScalarResult();
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user->email = 'vanished-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Vanished';
        $this->user->nameLast = 'Message';
        $this->user->roles = ['ROLE_USER'];
        $this->user->password = 'x';
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account->usr = $this->user;
        $this->account->email = 'vanished-fixture@example.test';
        $this->account->username = 'vanished-fixture@example.test';
        $this->account->imapHost = 'localhost';
        $this->account->imapPort = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->smtpHost = 'localhost';
        $this->account->smtpPort = 587;
        $this->account->smtpEncryption = 'starttls';
        $this->account->password = 'x';
        $this->account->authType = 'password';
        $this->account->isActive = true;
        $this->em->persist($this->account);

        $this->inbox   = $this->mailbox('INBOX', 'INBOX', MailboxSpecialUse::INBOX);
        $this->archive = $this->mailbox('Archive', 'INBOX.Archive', MailboxSpecialUse::ARCHIVE);
        $this->trash   = $this->mailbox('Trash', 'INBOX.Trash', MailboxSpecialUse::TRASH);

        $this->em->flush();

        $this->user->addAccount($this->account);

        $this->labels->bindMailbox($this->labels->systemLabel(LabelRole::Inbox, $this->account), $this->inbox);
        $this->labels->bindMailbox($this->labels->systemLabel(LabelRole::Archive, $this->account), $this->archive);
        $this->labels->bindMailbox($this->labels->systemLabel(LabelRole::Trash, $this->account), $this->trash);

        $this->em->flush();
    }

    private function mailbox(string $name, string $fullPath, MailboxSpecialUse $specialUse): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $fullPath;
        $mailbox->specialUse    = $specialUse;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;

        $this->em->persist($mailbox);

        return $mailbox;
    }
}
