<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Imap\MessageThreader;
use App\Service\Label\LabelResolver;
use App\Service\Mail\ThreadStatusUpdater;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What dropping a conversation on a folder, and on a category tab, actually
 * does to it.
 *
 * Both are new ways to reach operations that already existed in some form, and
 * both have one way of going wrong that the user could not undo:
 *
 *  - A MOVE is not a label. Attaching the destination and leaving everything
 *    else alone would put the conversation in two folders at once; stripping
 *    everything else would take the user's own tags off it, and the tags are
 *    the part nobody can get back. So the assertion that matters is which
 *    labels survive, not which one arrives.
 *  - A CATEGORY chosen by hand has to outlive the next message in the thread.
 *    Categories are derived most-recent-wins, so without the pin the choice
 *    holds until the newsletter's next issue lands and then silently reverts —
 *    days later, with nothing on screen connecting the two events.
 */
final class ThreadMoveTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private ThreadStatusUpdater $status;
    private LabelResolver $labelResolver;
    private MessageThreader $threader;

    private Account $account;
    private Mailbox $inboxMailbox;
    private Mailbox $receiptsMailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->status        = $container->get(ThreadStatusUpdater::class);
        $this->labelResolver = $container->get(LabelResolver::class);
        $this->threader      = $container->get(MessageThreader::class);

        $this->connection->beginTransaction();

        $this->account         = $this->seedAccount();
        $this->inboxMailbox    = $this->seedMailbox('INBOX');
        $this->receiptsMailbox = $this->seedMailbox('Receipts');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The core of it: in the destination, out of the source, tags untouched.
     *
     * All three in one test on purpose. They are not three behaviours — they
     * are the one behaviour stated from three sides, and a move that got any of
     * them wrong would be a different operation.
     */
    public function testAMoveLeavesTheSourceFolderAndKeepsTheConversationsTags(): void
    {
        $inbox    = $this->labelResolver->systemLabel(LabelRole::Inbox, $this->account);
        $receipts = $this->folderLabel('Receipts', $this->receiptsMailbox);
        $tag      = $this->tagLabel('Important');

        $thread  = $this->thread();
        $message = $thread->messages->first();

        $message->addLabel($inbox);
        $message->addLabel($tag);
        $this->em->flush();

        $this->status->move([$message], $receipts);

        self::assertTrue($message->labels->contains($receipts), 'the conversation did not arrive in the destination');
        self::assertFalse($message->labels->contains($inbox), 'the conversation is in two folders at once');
        self::assertTrue(
            $message->labels->contains($tag),
            'a move stripped a label that is a tag, not a place — see ThreadStatusUpdater::locationLabelsOf()',
        );
    }

    /**
     * Plain IMAP: the row has to name the folder the mail is now in.
     *
     * The label change is the source of truth, but the mailbox pointer is what
     * the sync layer addresses the message by. Left pointing at the source, the
     * next flag pass would look for the message under a UID the destination
     * never issued.
     */
    public function testTheMessageIsRepointedAtTheDestinationMailbox(): void
    {
        $inbox    = $this->labelResolver->systemLabel(LabelRole::Inbox, $this->account);
        $receipts = $this->folderLabel('Receipts', $this->receiptsMailbox);

        // The Inbox label has to be BOUND to the mailbox the message sits in,
        // or there is no physical move to make: LabelChangePropagator only
        // relocates a message whose current mailbox is the folder behind the
        // label being detached. Unbound, the detach is a database-only change
        // and the pointer legitimately stays put — which is what this fixture
        // asserted the first time it was written, and it is not the case the
        // test is about.
        $this->labelResolver->bindMailbox($inbox, $this->inboxMailbox);

        $thread  = $this->thread();
        $message = $thread->messages->first();

        $message->addLabel($inbox);
        $this->em->flush();

        $this->status->move([$message], $receipts);

        self::assertSame($this->receiptsMailbox, $message->mailbox);
    }

    /**
     * The reason MessageThread::$categoryPinnedAt exists.
     *
     * recordActivity() is the arrival path — it is what adopts a new message's
     * derived category onto the thread — so calling it with a newer message in
     * a different category is exactly the event that used to undo the move.
     */
    public function testAChosenCategorySurvivesTheNextMessageInTheThread(): void
    {
        $thread           = $this->thread();
        $thread->category = MessageCategory::Promotions;
        $this->em->flush();

        $this->status->setCategory($thread, MessageCategory::Primary);

        $arrival             = $this->message('a later issue');
        $arrival->category   = MessageCategory::Promotions;
        $arrival->receivedAt = new \DateTimeImmutable('+1 hour');
        $thread->addMessage($arrival);
        $this->em->persist($arrival);
        $this->em->flush();

        $this->threader->recordActivity($arrival, $thread);

        self::assertSame(
            MessageCategory::Primary,
            $thread->category,
            'the next message in the thread overwrote a category the user had chosen',
        );
    }

    /**
     * And a thread nobody has an opinion about still follows the cascade.
     *
     * The pin has to be the exception rather than the rule, or the tabs stop
     * responding to what actually arrives — which would be the same feature
     * broken the other way round, and much harder to notice.
     */
    public function testAnUnpinnedThreadStillFollowsTheNewestMessage(): void
    {
        $thread           = $this->thread();
        $thread->category = MessageCategory::Primary;
        $this->em->flush();

        $arrival             = $this->message('a later issue');
        $arrival->category   = MessageCategory::Promotions;
        $arrival->receivedAt = new \DateTimeImmutable('+1 hour');
        $thread->addMessage($arrival);
        $this->em->persist($arrival);
        $this->em->flush();

        $this->threader->recordActivity($arrival, $thread);

        self::assertSame(MessageCategory::Promotions, $thread->category);
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /** A custom label with a real folder behind it — a place mail can be. */
    private function folderLabel(string $name, Mailbox $mailbox): \App\Entity\Label\Label
    {
        $label = $this->labelResolver->customChain([$name], $this->account);

        self::assertNotNull($label);

        $this->labelResolver->bindMailbox($label, $mailbox);
        $this->em->flush();

        return $label;
    }

    /** A custom label with no folder — a tag the mail wears wherever it lives. */
    private function tagLabel(string $name): \App\Entity\Label\Label
    {
        $label = $this->labelResolver->customChain([$name], $this->account);

        self::assertNotNull($label);
        $this->em->flush();

        return $label;
    }

    private function thread(): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Move fixture';
        $thread->normalizedSubject = 'move fixture';
        $thread->lastMessageAt     = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->unreadCount       = 0;
        $thread->messageCount      = 1;
        $this->em->persist($thread);

        $message = $this->message('Move fixture');
        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $thread;
    }

    private function message(string $subject): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = $subject;
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->messageId      = sprintf('<move-%s@example.test>', uniqid('', true));
        // A mailbox and a UID, or the propagator treats the row as having no
        // address at a provider and skips the physical move this pins.
        $message->mailbox = $this->inboxMailbox;
        $message->imapUid = random_int(3000, 90000);

        return $message;
    }

    private function seedAccount(): Account
    {
        $user            = new User();
        $user->email     = 'mover-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Thread';
        $user->nameLast  = 'Mover';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $email = 'mover-fixture-' . uniqid('', true) . '@example.test';

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = $email;
        $account->username       = $email;
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedMailbox(string $name): Mailbox
    {
        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $name;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
