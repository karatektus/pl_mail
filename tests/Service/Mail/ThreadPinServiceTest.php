<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Label\LabelResolver;
use App\Service\Mail\ThreadPinService;
use App\Service\Mail\ThreadStatusUpdater;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a pin is (#654): when it ends, when it counts, and what a move does to
 * it.
 *
 * The rules live in one service so the row, the toolbar, a swipe and a JMAP
 * client cannot disagree, and that is what these pin down: an end time taken
 * from the owner's setting at the moment of pinning, a re-pin that does not
 * restart a running pin, an ended pin that neither counts toward the reminder
 * nor survives the sweep, and a move that keeps or clears the pin by the
 * owner's choice — except the bin, which always clears it.
 *
 * Against the real container and database, for ThreadSnoozeServiceTest's
 * reasons: the services are final, and the moves are the status updater's
 * real label changes, which a double would only assert into existence.
 */
final class ThreadPinServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private ThreadPinService $pins;
    private ThreadStatusUpdater $status;
    private LabelResolver $labelResolver;

    private User $user;
    private Account $account;
    private Mailbox $inboxMailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->pins          = $container->get(ThreadPinService::class);
        $this->status        = $container->get(ThreadStatusUpdater::class);
        $this->labelResolver = $container->get(LabelResolver::class);

        // Never committed, so the suite leaves nothing behind.
        $this->connection->beginTransaction();

        $this->account      = $this->seedAccount();
        $this->user         = $this->account->usr;
        $this->inboxMailbox = $this->seedMailbox();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAPinLastsForeverUnlessTheOwnerSetADuration(): void
    {
        $forever = $this->inboxThread();
        $this->pins->pin([$forever]);

        self::assertNotNull($forever->pinnedAt, 'pinned');
        self::assertNull($forever->pinnedUntil, 'no end: forever is the default');

        $this->user->pinDurationDays = 3;
        $timed = $this->inboxThread();
        $now   = new DateTimeImmutable('2026-11-17 10:00:00');

        $this->pins->pin([$timed], $now);

        self::assertEquals($now->modify('+3 days'), $timed->pinnedUntil, 'the end is the setting at the moment of pinning');
    }

    /** Pinning a selection that includes a running pin must not restart that pin's clock. */
    public function testPinningAPinnedConversationKeepsItsEndRatherThanRestartingIt(): void
    {
        $this->user->pinDurationDays = 2;
        $thread = $this->inboxThread();
        $first  = new DateTimeImmutable('-1 day');

        $this->pins->pin([$thread], $first);
        $end = $thread->pinnedUntil;

        $this->pins->pin([$thread]);

        self::assertEquals($first, $thread->pinnedAt);
        self::assertEquals($end, $thread->pinnedUntil);
    }

    public function testTheReminderAsksAtTheCountAndNeverWhenSwitchedOff(): void
    {
        $this->user->pinReminderAt = 2;
        $this->pins->pin([$this->inboxThread(), $this->inboxThread()]);

        self::assertTrue($this->pins->wouldPassReminder($this->user), 'the third pin is past a reminder of two');
        self::assertFalse($this->pins->wouldPassReminder($this->user, 0), 'adding nothing passes nothing');

        $this->user->pinReminderAt = 0;

        self::assertFalse($this->pins->wouldPassReminder($this->user), '0 is off');
    }

    /** An ended pin is no pin: not at the top, not counted, and cleared by the sweep. */
    public function testAnEndedPinIsNotCountedAndTheSweepClearsIt(): void
    {
        $this->user->pinDurationDays = 1;
        $ended = $this->inboxThread();
        $this->pins->pin([$ended], new DateTimeImmutable('-2 days'));

        $live = $this->inboxThread();
        $this->pins->pin([$live]);

        self::assertFalse($ended->isPinned(), 'past its end');
        self::assertSame(1, $this->pins->activeCount($this->user), 'only the live pin counts');

        $cleared = $this->pins->clearEnded(new DateTimeImmutable(), 100);

        self::assertGreaterThanOrEqual(1, $cleared);
        self::assertNull($ended->pinnedAt, 'the sweep cleared the ended pin');
        self::assertNotNull($live->pinnedAt, 'and left the live one alone');
    }

    public function testArchivingKeepsThePinByDefault(): void
    {
        $thread = $this->inboxThread();
        $this->pins->pin([$thread]);

        $this->status->archive($thread->messages->toArray());

        self::assertTrue($thread->isPinned(), '"Keep the pin when moved" is on unless switched off');
    }

    public function testArchivingClearsThePinWhenTheOwnerSaidSo(): void
    {
        $this->user->pinKeepOnMove = false;
        $thread = $this->inboxThread();
        $this->pins->pin([$thread]);

        $this->status->archive($thread->messages->toArray());

        self::assertFalse($thread->isPinned(), 'archiving counts as moving');
    }

    /** The bin always takes a pin off, whatever the setting says. */
    public function testTrashClearsThePinEvenWhenMovesKeepIt(): void
    {
        $thread = $this->inboxThread();
        $this->pins->pin([$thread]);

        $this->status->trash($thread->messages->toArray());

        self::assertTrue($this->user->pinKeepOnMove, 'presence: the setting says keep');
        self::assertFalse($thread->isPinned(), 'and the bin cleared it anyway');
    }

    public function testUnpinClearsBothColumns(): void
    {
        $this->user->pinDurationDays = 5;
        $thread = $this->inboxThread();
        $this->pins->pin([$thread]);

        self::assertFalse($this->pins->toggle($thread), 'toggling a pinned conversation unpins it');
        self::assertNull($thread->pinnedAt);
        self::assertNull($thread->pinnedUntil);
    }

    // ── single emails (#615) ─────────────────────────────────────────────

    public function testAPinnedEmailPinsItsConversationAndUnpinningTheConversationTakesItOff(): void
    {
        $thread = $this->inboxThread();
        $email  = $this->addEmail($thread, '-10 minutes');

        $this->pins->pinEmails([$email]);

        self::assertTrue($email->isPinned());
        self::assertFalse($thread->isPinned(), "the conversation's own column is untouched");
        self::assertTrue(ThreadPinService::conversationPinned($thread), 'and the conversation list shows it pinned');
        self::assertSame(1, $this->pins->activeCount($this->user), 'one pin toward the reminder');

        self::assertFalse($this->pins->toggle($thread), 'the conversation thumbtack reads it as pinned, and unpins');
        self::assertNull($email->pinnedAt, "taking the email's pin off with it");
    }

    /**
     * Unpinning an email that shows pinned because its conversation is takes
     * the conversation's pin off: all of what pinned the row.
     */
    public function testUnpinningTheEmailAConversationPinShowsOnTakesTheConversationPinOff(): void
    {
        $thread = $this->inboxThread();
        $newest = $this->addEmail($thread, '-1 minute');
        $this->pins->pin([$thread]);

        self::assertTrue(ThreadPinService::emailPinned($newest), 'the newest received email carries the pin');

        self::assertFalse($this->pins->toggleEmail($newest));
        self::assertFalse($thread->isPinned());
    }

    public function testTheNewestReceivedEmailIsTheAnchorNotYourReply(): void
    {
        $thread   = $this->inboxThread();
        $received = $this->addEmail($thread, '-30 minutes');
        $reply    = $this->addEmail($thread, '-1 minute');
        $reply->addLabel($this->labelResolver->systemLabel(LabelRole::Sent, $this->account));

        self::assertSame($received, ThreadPinService::anchorOf($thread));
    }

    /** Archiving one email of a pinned conversation is not the conversation moving. */
    public function testMovingOneEmailKeepsTheConversationPin(): void
    {
        $this->user->pinKeepOnMove = false;
        $thread = $this->inboxThread();
        $other  = $this->addEmail($thread, '-5 minutes');
        $this->pins->pin([$thread]);
        $this->pins->pinEmails([$other]);

        $this->status->archive([$other]);

        self::assertTrue($thread->isPinned(), 'the rest of the conversation did not move');
        self::assertFalse($other->isPinned(), "the moved email's own pin follows the owner's rule");
    }

    private function addEmail(MessageThread $thread, string $at): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = 'Pin fixture reply';
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new DateTimeImmutable($at);
        $message->hasAttachments = false;
        $message->messageId      = sprintf('<pin-%s@example.test>', uniqid('', true));
        $message->mailbox        = $this->inboxMailbox;
        $message->imapUid        = random_int(1000, 999999);
        $message->addLabel($this->labelResolver->systemLabel(LabelRole::Inbox, $this->account));

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    private function inboxThread(): MessageThread
    {
        $inbox  = $this->labelResolver->systemLabel(LabelRole::Inbox, $this->account);
        $thread = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Pin fixture';
        $thread->normalizedSubject = 'pin fixture';
        $thread->lastMessageAt     = new DateTimeImmutable('-1 hour');
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->unreadCount       = 0;
        $this->em->persist($thread);

        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = 'Pin fixture';
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->messageId      = sprintf('<pin-%s@example.test>', uniqid('', true));
        $message->mailbox        = $this->inboxMailbox;
        $message->imapUid        = random_int(1000, 999999);

        $message->addLabel($inbox);
        $thread->addMessage($message);
        $this->em->persist($message);

        $this->em->flush();

        return $thread;
    }

    private function seedAccount(): Account
    {
        $user            = new User();
        $user->email     = 'pin-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Pin';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'Pin Fixture';
        $account->username       = 'pin-fixture@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->mailEnabled    = true;
        $this->em->persist($account);

        $this->em->flush();

        return $account;
    }

    private function seedMailbox(): Mailbox
    {
        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = 'INBOX';
        $mailbox->fullPath      = 'INBOX';
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;

        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
