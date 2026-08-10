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
use App\Service\Label\LabelResolver;
use App\Service\Mail\ThreadStatusUpdater;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A message that moves is still one message.
 *
 * The reported bug: an IMAP account synced and every trashed mail was in the
 * list twice. The account's own numbers were worse than doubling — 35 messages
 * on the server against 86 rows in plMail — because the duplication was not per
 * message but per *move*, and mail that had been dragged from INBOX to a spam
 * folder and then to Trash had left a row at every stop.
 *
 * The mechanism was the one v0.0.23 named and then fixed only for Sent: a row is
 * keyed on mailbox plus IMAP UID, which is an address rather than an identity.
 * Trashing re-pointed the mailbox and kept the UID the *source* folder had
 * issued, so the row claimed an address that no longer existed, while the
 * destination's next sync met the real UID as mail it had never seen and
 * inserted a second row beside it.
 *
 * These tests fix both halves of the rule. A move is reconciled onto the row
 * that already exists — and a copy, which looks identical from the destination,
 * is not. What separates them is never the content: it is whether the copy in
 * the source folder is still there, which is a question only the server can
 * answer, so the answer is what these tests supply.
 */
final class MovedMessageReconciliationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private SentCopyReconciler $reconciler;
    private MessageRepository $messages;
    private LabelResolver $labels;
    private ThreadStatusUpdater $status;

    private User $user;
    private Account $account;
    private Mailbox $inbox;
    private Mailbox $junk;
    private Mailbox $trash;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->reconciler = $container->get(SentCopyReconciler::class);
        $this->messages   = $container->get(MessageRepository::class);
        $this->labels     = $container->get(LabelResolver::class);
        $this->status     = $container->get(ThreadStatusUpdater::class);

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

    // ── the move we make ourselves ────────────────────────────────────────

    /**
     * The reported bug, from the button the user pressed.
     *
     * Trashing must leave the row naming Trash and naming no address inside it.
     * The UID it held was INBOX's and describes nothing once the message has
     * left — and keeping it is exactly what made the Trash sync insert a twin.
     */
    public function testTrashingAMessageDoesNotLeaveItClaimingItsOldAddress(): void
    {
        $message = $this->incoming('Rechnung', 'kunde@example.test', $this->inbox, 6);

        $this->status->trash([$message]);

        self::assertSame($this->trash->id, $message->mailbox?->id, 'the row has to name the folder the mail is going to');
        self::assertNull($message->imapUid, 'and no UID, because Trash has not issued one yet');
    }

    /**
     * The other half of the same journey: the Trash sync meets the real UID and
     * has to recognise the row rather than insert beside it.
     *
     * No probe is passed, and that is the point — a move we made ourselves needs
     * no server to confirm it, because the row already says it has no address.
     */
    public function testTheTrashSyncGivesTheMovedRowItsNewAddressInsteadOfASecondRow(): void
    {
        $message   = $this->incoming('Rechnung', 'kunde@example.test', $this->inbox, 6);
        $messageId = (string) $message->messageId;
        $rowId     = (int) $message->id;

        $this->status->trash([$message]);

        $claimed = $this->reconciler->claim($this->trash, $messageId, 91);
        $this->em->flush();

        self::assertNotNull($claimed, 'the trashed message must be recognised when it reappears in Trash');
        self::assertSame($rowId, (int) $claimed->id, 'and recognised as the row the user has been looking at');
        self::assertSame(91, $claimed->imapUid);
        self::assertSame($this->trash->id, $claimed->mailbox?->id);

        self::assertSame(1, $this->countRowsFor($messageId), 'one message, one row');
    }

    // ── the move somebody else makes ──────────────────────────────────────

    /**
     * A server-side filter drops the mail into a spam folder. plMail learns
     * about it only by finding it there, under a UID it has never seen — the
     * INBOX.spambucket case the report's warning came from.
     */
    public function testAnExternalMoveIsReconciledOntoTheRowThatAlreadyExists(): void
    {
        $message   = $this->incoming('Angebot', 'kunde@example.test', $this->inbox, 6);
        $messageId = (string) $message->messageId;
        $rowId     = (int) $message->id;

        $claimed = $this->reconciler->claim(
            $this->junk,
            $messageId,
            12,
            $this->serverHolding([]),
        );
        $this->em->flush();

        self::assertNotNull($claimed, 'the copy in INBOX has gone, so this is that message at a new address');
        self::assertSame($rowId, (int) $claimed->id);
        self::assertSame($this->junk->id, $claimed->mailbox?->id);
        self::assertSame(12, $claimed->imapUid);

        self::assertSame(1, $this->countRowsFor($messageId));
    }

    /**
     * The labels travel with the message, because a mail the server moved out of
     * INBOX is not in INBOX any more. Leaving the Inbox label on would show it
     * in a list it has left — the duplicate's mistake, made from the other side.
     */
    public function testAMovedMessageStopsWearingTheLabelOfTheFolderItLeft(): void
    {
        $message = $this->incoming('Gewinnspiel', 'spam@example.test', $this->inbox, 6);

        $inboxLabel = $this->labels->systemLabel(LabelRole::Inbox, $this->account);
        $message->addLabel($inboxLabel);
        $this->em->flush();

        $this->reconciler->claim($this->junk, (string) $message->messageId, 12, $this->serverHolding([]));
        $this->em->flush();

        self::assertFalse($message->hasLabel($inboxLabel), 'it is not in INBOX any more');
        self::assertTrue(
            $message->hasLabel($this->labels->systemLabel(LabelRole::Spam, $this->account)),
            'and it is in the folder it was moved to',
        );
    }

    // ── the copy, which must survive all of this ──────────────────────────

    /**
     * The counter-case the whole design turns on. Plain IMAP lets one message
     * exist in two folders at once, and from the destination a COPY is
     * indistinguishable from a MOVE — same id, same bytes, new UID. The source
     * copy is what tells them apart, and here it is still there.
     */
    public function testACopyWhoseOriginalIsStillInPlaceKeepsItsOwnRow(): void
    {
        $message = $this->incoming('Wichtig', 'kunde@example.test', $this->inbox, 6);

        $claimed = $this->reconciler->claim(
            $this->junk,
            (string) $message->messageId,
            12,
            $this->serverHolding([[$this->inbox, 6]]),
        );

        self::assertNull($claimed, 'the original is still in INBOX, so this is a second copy and gets its own row');
        self::assertSame($this->inbox->id, $message->mailbox?->id, 'and the original must not have been dragged anywhere');
        self::assertSame(6, $message->imapUid);
    }

    /**
     * "I could not reach the folder" is not "the message is gone". A probe that
     * fails must leave the syncer doing what it did before any of this existed,
     * because the cost of not merging a move is a duplicate the repair pass will
     * collect, and the cost of merging a copy is a row the user can still see.
     */
    public function testAnUnanswerableProbeNeverInfersAMove(): void
    {
        $message = $this->incoming('Unklar', 'kunde@example.test', $this->inbox, 6);

        self::assertNull(
            $this->reconciler->claim(
                $this->junk,
                (string) $message->messageId,
                12,
                static fn (Mailbox $mailbox, int $uid): ?bool => null,
            ),
        );
    }

    public function testWithNoWayToAskTheServerNoMoveIsInferred(): void
    {
        $message = $this->incoming('Offline', 'kunde@example.test', $this->inbox, 6);

        self::assertNull(
            $this->reconciler->claim($this->junk, (string) $message->messageId, 12, null),
            'no evidence, no merge',
        );
    }

    // ── self-repair of what is already on disk ────────────────────────────

    /**
     * The install as reported. A mail that went INBOX → spambucket → Trash left
     * a row at every stop, and only the last one names an address the server
     * will confirm. All the ghosts go, not merely one — which is why this
     * counts survivors instead of pairing twins.
     */
    public function testRepairCollapsesEveryGhostAChainOfMovesLeftBehind(): void
    {
        $messageId = MessageIdHelper::mint('kunde@example.test');
        $thread    = $this->thread('Mahnung');

        $inboxGhost = $this->rowIn($this->inbox, $messageId, 6, 'Mahnung', $thread);
        $junkGhost  = $this->rowIn($this->junk, $messageId, 12, 'Mahnung', $thread);
        $live       = $this->rowIn($this->trash, $messageId, 91, 'Mahnung', $thread);
        $this->em->flush();

        $liveId = (int) $live->id;

        self::assertSame(3, $this->countRowsFor($messageId), 'the reported state: one message, three rows');

        $removed = $this->reconciler->repairRelocated(
            $this->trash,
            $this->serverHolding([[$this->trash, 91]]),
        );

        self::assertSame(2, $removed, 'both ghosts go');
        self::assertSame(1, $this->countRowsFor($messageId));
        self::assertNotNull($this->messages->find($liveId), 'the row the server still confirms is the one that stays');
    }

    /**
     * The repair may not eat a message that genuinely lives in two folders.
     * Two confirmed copies is not a duplicate, it is a copy.
     */
    public function testRepairLeavesAMessageThatGenuinelyExistsInTwoFoldersAlone(): void
    {
        $messageId = MessageIdHelper::mint('kunde@example.test');
        $thread    = $this->thread('Rundschreiben');

        $this->rowIn($this->inbox, $messageId, 6, 'Rundschreiben', $thread);
        $this->rowIn($this->trash, $messageId, 91, 'Rundschreiben', $thread);
        $this->em->flush();

        $removed = $this->reconciler->repairRelocated(
            $this->trash,
            $this->serverHolding([[$this->inbox, 6], [$this->trash, 91]]),
        );

        self::assertSame(0, $removed);
        self::assertSame(2, $this->countRowsFor($messageId), 'both copies are real, so both rows stay');
    }

    /**
     * The guard that stops a bad night on the server from deleting mail. If
     * nothing is confirmed present there is no anchor, and the last row of a
     * message is never removed on the strength of a probe that answered "no" to
     * everything — that shape is equally consistent with the mail having been
     * deleted, or the server having been unreachable.
     */
    public function testRepairRemovesNothingWhenNoCopyIsConfirmed(): void
    {
        $messageId = MessageIdHelper::mint('kunde@example.test');
        $thread    = $this->thread('Verschwunden');

        $this->rowIn($this->inbox, $messageId, 6, 'Verschwunden', $thread);
        $this->rowIn($this->trash, $messageId, 91, 'Verschwunden', $thread);
        $this->em->flush();

        self::assertSame(0, $this->reconciler->repairRelocated($this->trash, $this->serverHolding([])));
        self::assertSame(2, $this->countRowsFor($messageId), 'nothing is confirmed, so nothing is destroyed');
    }

    public function testRepairDoesNothingWithoutAWayToAskTheServer(): void
    {
        $messageId = MessageIdHelper::mint('kunde@example.test');
        $thread    = $this->thread('Ohne Server');

        $this->rowIn($this->inbox, $messageId, 6, 'Ohne Server', $thread);
        $this->rowIn($this->trash, $messageId, 91, 'Ohne Server', $thread);
        $this->em->flush();

        self::assertSame(0, $this->reconciler->repairRelocated($this->trash, null));
        self::assertSame(2, $this->countRowsFor($messageId));
    }

    /**
     * A single message with one row is not a candidate for anything, and the
     * repair must not so much as look at it — this is the query that runs on
     * every sync of every folder on every healthy account.
     */
    public function testRepairFindsNothingOnAnAccountThatNeverHadTheBug(): void
    {
        $this->incoming('Alles gut', 'kunde@example.test', $this->inbox, 6);
        $this->incoming('Auch gut', 'kunde@example.test', $this->trash, 91);

        self::assertSame(0, $this->reconciler->repairRelocated($this->trash, $this->serverHolding([
            [$this->inbox, 6],
            [$this->trash, 91],
        ])));
    }

    // ── the number the user actually complained about ─────────────────────

    /**
     * The invariant behind "35 on the server, 86 in plMail": after a sync of
     * every folder, the account holds exactly one row per message the server
     * has, however many folders those messages have passed through.
     *
     * Driven the way the syncer drives it — claim() per UID the server offers,
     * then the repair pass — over a server whose state is stated once, here.
     */
    public function testTheAccountEndsWithOneRowPerMessageOnTheServer(): void
    {
        // Three messages that have been moved around, and the rows an affected
        // install is carrying for them: a ghost at every stop of every move.
        $moved   = MessageIdHelper::mint('kunde@example.test');
        $spammed = MessageIdHelper::mint('spam@example.test');
        $quiet   = MessageIdHelper::mint('kollege@example.test');

        $movedThread   = $this->thread('Verschoben');
        $spammedThread = $this->thread('Werbung');
        $quietThread   = $this->thread('Ruhig');

        $this->rowIn($this->inbox, $moved, 6, 'Verschoben', $movedThread);
        $this->rowIn($this->junk, $moved, 12, 'Verschoben', $movedThread);
        $this->rowIn($this->trash, $moved, 91, 'Verschoben', $movedThread);

        $this->rowIn($this->inbox, $spammed, 7, 'Werbung', $spammedThread);
        $this->rowIn($this->junk, $spammed, 13, 'Werbung', $spammedThread);

        $this->rowIn($this->inbox, $quiet, 8, 'Ruhig', $quietThread);
        $this->em->flush();

        self::assertSame(6, $this->countRowsForAccount(), 'the broken state: six rows for three messages');

        // What the server actually holds.
        $server = [
            [$this->trash, 91],
            [$this->junk, 13],
            [$this->inbox, 8],
        ];

        $probe = $this->serverHolding($server);

        foreach ([$this->inbox, $this->junk, $this->trash] as $mailbox) {
            $this->reconciler->repairRelocated($mailbox, $probe);
        }

        $this->em->flush();

        self::assertSame(
            count($server),
            $this->countRowsForAccount(),
            'one row per message the server has, which is the number the user was counting',
        );
    }

    // ── fixture ───────────────────────────────────────────────────────────

    /**
     * A stand-in for the IMAP server: the set of (folder, UID) addresses that
     * still exist. Everything else is reported gone, which is what a real probe
     * reports for a UID whose message has moved.
     *
     * This one always knows: it is standing in for a server that answered.
     * "Could not tell" is a third answer with its own consequences, and it gets
     * its own probe in testAnUnanswerableProbeNeverInfersAMove().
     *
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

    private function incoming(string $subject, string $from, Mailbox $mailbox, int $uid): Message
    {
        $thread  = $this->thread($subject);
        $message = $this->rowIn($mailbox, MessageIdHelper::mint($from), $uid, $subject, $thread);
        $message->fromAddress = $from;

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
        $this->user->email = 'moved-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Moved';
        $this->user->nameLast = 'Message';
        $this->user->roles = ['ROLE_USER'];
        $this->user->password = 'x';
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account->usr = $this->user;
        $this->account->email = 'moved-fixture@example.test';
        $this->account->username = 'moved-fixture@example.test';
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

        // The naming the report came with: a Courier-style INBOX-prefixed
        // namespace, where the spam folder is a child of INBOX.
        $this->inbox = $this->mailbox('INBOX', 'INBOX', MailboxSpecialUse::INBOX);
        $this->junk  = $this->mailbox('spambucket', 'INBOX.spambucket', MailboxSpecialUse::JUNK);
        $this->trash = $this->mailbox('Trash', 'INBOX.Trash', MailboxSpecialUse::TRASH);

        $this->em->flush();

        $this->user->addAccount($this->account);

        $this->labels->bindMailbox($this->labels->systemLabel(LabelRole::Inbox, $this->account), $this->inbox);
        $this->labels->bindMailbox($this->labels->systemLabel(LabelRole::Spam, $this->account), $this->junk);
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
