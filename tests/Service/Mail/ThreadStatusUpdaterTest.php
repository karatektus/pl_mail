<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Label\LabelResolver;
use App\Service\Mail\ThreadStatusUpdater;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * What the status buttons mean, pinned away from the controller they used to
 * live in.
 *
 * Two things make these worth having beyond the extraction. Archive and trash
 * are *label* operations with a mailbox move riding along for plain IMAP, and
 * getting the order wrong — moving the mailbox before the propagation is
 * queued — makes the IMAP job read the wrong source folder, which nothing else
 * would notice until a message failed to arrive in Archive. And every one of
 * these has to reach the provider: a star that only lands in our database is a
 * star the user's phone never sees.
 *
 * Against a real container and database rather than doubles: every
 * collaborator this service takes is `final`, and the behaviour worth pinning
 * is the one that emerges from them together.
 */
final class ThreadStatusUpdaterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private ThreadStatusUpdater $updater;
    private LabelResolver $labelResolver;

    private Account $account;
    private Mailbox $inboxMailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->updater       = $container->get(ThreadStatusUpdater::class);
        $this->labelResolver = $container->get(LabelResolver::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();

        $this->account      = $this->seedAccount();
        $this->inboxMailbox = $this->seedMailbox();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── star ─────────────────────────────────────────────────────────────────

    /**
     * The thread's star is what the list renders, so setting the message's
     * without the thread's produces a row that looks unstarred immediately
     * after being starred.
     */
    public function testStarringSetsTheFlagOnBothTheMessageAndTheThread(): void
    {
        $thread = $this->inboxThread(1);

        self::assertTrue($this->updater->star($thread->messages->toArray()));

        $message = $thread->messages->first();

        self::assertTrue($message->hasFlag(MessageFlag::FLAGGED));
        self::assertNotNull($message->starredAt);
        self::assertNotNull($thread->starredAt);
    }

    /**
     * One endpoint serves both directions: the first message's current state
     * decides, and the answer says which way it went so the caller can render
     * the right icon.
     */
    public function testStarringAgainUnstars(): void
    {
        $thread   = $this->inboxThread(1);
        $messages = $thread->messages->toArray();

        $this->updater->star($messages);

        self::assertFalse($this->updater->star($messages));

        $message = $thread->messages->first();

        self::assertFalse($message->hasFlag(MessageFlag::FLAGGED));
        self::assertNull($message->starredAt);
        self::assertNull($thread->starredAt);
    }

    /** A star only we know about is one the user's phone never sees. */
    public function testStarringReachesTheProvider(): void
    {
        $before = count($this->transport()->getSent());

        $this->updater->star($this->inboxThread(1)->messages->toArray());

        self::assertGreaterThan($before, count($this->transport()->getSent()));
    }

    // ── archive ──────────────────────────────────────────────────────────────

    /** Archive *is* the removal of the Inbox label. There is nothing else to it. */
    public function testArchivingRemovesTheInboxLabelFromEveryMessage(): void
    {
        $thread = $this->inboxThread(3);

        $this->updater->archive($thread->messages->toArray());

        $inbox = $this->role(LabelRole::Inbox);

        foreach ($thread->messages as $message) {
            self::assertFalse($message->labels->contains($inbox));
        }
    }

    /**
     * For plain IMAP the message physically moves, and the local pointer is
     * re-pointed optimistically so the sync layer does not go looking for it in
     * the folder it just left.
     */
    public function testArchivingRePointsAPlainImapMessageAtTheArchiveMailbox(): void
    {
        $archiveMailbox = $this->seedMailbox('Archive');
        $this->labelResolver->bindMailbox($this->role(LabelRole::Archive), $archiveMailbox);
        $this->em->flush();

        $thread = $this->inboxThread(1);

        $this->updater->archive($thread->messages->toArray());

        self::assertSame($archiveMailbox, $thread->messages->first()->mailbox);
    }

    /**
     * The account may have no Archive folder mapped at all — a Gmail-API
     * account never does. The label still comes off; only the move is skipped.
     */
    public function testArchivingWithNoArchiveFolderLeavesTheMailboxAlone(): void
    {
        $thread = $this->inboxThread(1);

        $this->updater->archive($thread->messages->toArray());

        self::assertSame($this->inboxMailbox, $thread->messages->first()->mailbox);
        self::assertFalse($thread->messages->first()->labels->contains($this->role(LabelRole::Inbox)));
    }

    public function testArchivingReachesTheProvider(): void
    {
        $before = count($this->transport()->getSent());

        $this->updater->archive($this->inboxThread(1)->messages->toArray());

        self::assertGreaterThan($before, count($this->transport()->getSent()));
    }

    // ── trash ────────────────────────────────────────────────────────────────

    /**
     * Trash is two label moves, not one. A message that gains Trash but keeps
     * Inbox is in both places at once, which is exactly what a user deleting
     * something is asking not to happen.
     */
    public function testTrashingAddsTrashAndRemovesInbox(): void
    {
        $thread = $this->inboxThread(2);

        $this->updater->trash($thread->messages->toArray());

        $inbox = $this->role(LabelRole::Inbox);
        $trash = $this->role(LabelRole::Trash);

        foreach ($thread->messages as $message) {
            self::assertTrue($message->labels->contains($trash));
            self::assertFalse($message->labels->contains($inbox));
        }
    }

    // ── custom labels ────────────────────────────────────────────────────────

    public function testAttachingACustomLabelAppliesItAcrossTheWholeSelection(): void
    {
        $thread = $this->inboxThread(3);
        $label  = $this->customLabel();

        $this->updater->applyLabel($thread->messages->toArray(), $label, true);

        foreach ($thread->messages as $message) {
            self::assertTrue($message->labels->contains($label));
        }
    }

    public function testDetachingACustomLabelRemovesItAgain(): void
    {
        $thread   = $this->inboxThread(2);
        $label    = $this->customLabel();
        $messages = $thread->messages->toArray();

        $this->updater->applyLabel($messages, $label, true);
        $this->updater->applyLabel($messages, $label, false);

        foreach ($thread->messages as $message) {
            self::assertFalse($message->labels->contains($label));
        }
    }

    // ── read state ───────────────────────────────────────────────────────────

    public function testMarkingReadSeesEveryMessageAndEmptiesTheUnreadCount(): void
    {
        $thread = $this->inboxThread(3);

        $this->updater->markRead($thread->messages->toArray(), true);

        foreach ($thread->messages as $message) {
            self::assertTrue($message->hasFlag(MessageFlag::SEEN));
            self::assertNotNull($message->seenAt);
        }

        self::assertSame(0, $thread->unreadCount);
    }

    /**
     * The count is what the list renders in bold, so it is derived from the
     * messages rather than decremented — an assigned count drifts the first
     * time a message is marked twice.
     */
    public function testMarkingUnreadRestoresTheCountFromTheMessagesThemselves(): void
    {
        $thread   = $this->inboxThread(3);
        $messages = $thread->messages->toArray();

        $this->updater->markRead($messages, true);
        $this->updater->markRead($messages, false);

        foreach ($thread->messages as $message) {
            self::assertFalse($message->hasFlag(MessageFlag::SEEN));
            self::assertNull($message->seenAt);
        }

        self::assertSame(3, $thread->unreadCount);
    }

    // ── account resolution ───────────────────────────────────────────────────

    /**
     * Ownership checks hang off this, and a Gmail-API message has no mailbox to
     * ask — the thread is the only thing that knows.
     */
    public function testTheAccountOfAMessageWithNoMailboxComesFromItsThread(): void
    {
        $thread = $this->inboxThread(1);

        $message = $thread->messages->first();
        $message->mailbox = null;
        $this->em->flush();

        self::assertSame($this->account, $this->updater->accountOf($message));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function role(LabelRole $role): Label
    {
        return $this->labelResolver->systemLabel($role, $this->account);
    }

    private function customLabel(): Label
    {
        $label = new Label();
        $label->usr = $this->account->usr;
        $label->name = 'Receipts ' . uniqid('', true);

        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function inboxThread(int $messages): MessageThread
    {
        $inbox  = $this->role(LabelRole::Inbox);
        $thread = new MessageThread();
        $thread->account = $this->account;
        $thread->subject = 'Status fixture';
        $thread->normalizedSubject = 'status fixture';
        $thread->lastMessageAt = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod = ThreadingMethod::References;
        $thread->unreadCount = $messages;
        $this->em->persist($thread);

        for ($i = 0; $i < $messages; $i++) {
            $message = new Message();
            $message->account = $this->account;
            $message->subject = sprintf('Status fixture %d', $i);
            $message->fromAddress = 'sender@example.test';
            $message->receivedAt = new \DateTimeImmutable('-1 hour');
            $message->hasAttachments = false;
            $message->messageId = sprintf('<status-%s-%d@example.test>', uniqid('', true), $i);
            // A mailbox and a UID, because LabelChangePropagator skips messages
            // that have neither — without them the propagation assertions would
            // be testing the fixture rather than the service.
            $message->mailbox = $this->inboxMailbox;
            $message->imapUid = 2000 + $i;

            $message->addLabel($inbox);
            $thread->addMessage($message);

            $this->em->persist($message);
        }

        $this->em->flush();

        return $thread;
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user->email = 'status-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Status';
        $user->nameLast = 'Fixture';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'Status Fixture';
        $account->username = 'status-fixture@example.test';
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);

        $this->em->flush();

        return $account;
    }

    private function seedMailbox(string $name = 'INBOX'): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->account = $this->account;
        $mailbox->name = $name;
        $mailbox->fullPath = $name;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;

        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');

        // The propagation assertions read the queue directly, so a test
        // environment wired to a real transport would silently measure nothing.
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
