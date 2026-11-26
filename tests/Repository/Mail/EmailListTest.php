<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ListSortOrder;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\Message\MessageListBadgeRepository;
use App\Repository\Mail\Message\MessageListRepository;
use App\Repository\Mail\MessageThread\MessageThreadRepository;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The same mail listed both ways (#615): as conversations, and one row per
 * email for somebody with conversation view off.
 *
 * Both lists are one definition built at two levels (MailListQuery), so what
 * is pinned down here is where the two must differ and only there: a reply
 * you sent is in Sent and not in the Inbox beside the mail it answers, one
 * email thrown in the bin leaves the email list while its conversation stays,
 * unread is counted in emails, and a pin made at either level puts the right
 * row first in the other.
 *
 * The fixture has real labels on every email and the conversation's synced
 * from them (ThreadLabelSynchronizer), as sync files them:
 *
 *   A  a1  received 3h ago    Inbox, read
 *      a3  received 90m ago   Inbox, unread
 *      a2  sent     1h ago    Sent          (your reply, the newest of A)
 *   B  b1  received 30m ago   Inbox, read
 *      b2  received 20m ago   Inbox + Trash
 *
 * a2 being the newest of A is what makes the pinned-conversation test one of
 * "newest RECEIVED".
 */
final class EmailListTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessageListRepository $emails;
    private MessageThreadRepository $conversations;
    private LabelResolver $labels;

    private User $user;
    private Account $account;

    /** @var array<string, Message> */
    private array $mail = [];

    /** @var array<string, MessageThread> */
    private array $threads = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container           = self::getContainer();
        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->emails        = $container->get(MessageListRepository::class);
        $this->conversations = $container->get(MessageThreadRepository::class);
        $this->labels        = $container->get(LabelResolver::class);

        $this->connection->beginTransaction();

        $this->seed($container->get(ThreadLabelSynchronizer::class));
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheEmailInboxListsEachInboxEmailAndNotYourRepliesOrTheBin(): void
    {
        self::assertSame(
            [$this->mail['b1']->id, $this->mail['a3']->id, $this->mail['a1']->id],
            $this->ids($this->emails->findForUnifiedInbox($this->user, null)),
            'newest first, one row per email: the sent reply and the binned email are not here',
        );
        self::assertSame(3, $this->emails->countForUnifiedInbox($this->user, null), 'and the pager counts the same rows');

        self::assertSame(
            [$this->threads['B']->id, $this->threads['A']->id],
            $this->ids($this->conversations->findForUnifiedInbox($this->user, null)),
            'the same mail as conversations, unchanged: B is not wholly in the bin',
        );
    }

    public function testYourReplyIsInSentAndTheBinnedEmailInTrash(): void
    {
        self::assertSame([$this->mail['a2']->id], $this->ids($this->emails->findForRole($this->user, LabelRole::Sent)));
        self::assertSame([$this->mail['b2']->id], $this->ids($this->emails->findForRole($this->user, LabelRole::Trash)));
    }

    public function testUnreadIsCountedInEmails(): void
    {
        self::assertSame([$this->mail['a3']->id], $this->ids($this->emails->findForUnifiedInbox($this->user, null, unreadOnly: true)));
        self::assertSame(1, $this->emails->countForUnread($this->user));

        $badges = self::getContainer()->get(MessageListBadgeRepository::class)->countUnreadPerRole($this->user);

        self::assertSame(1, $badges[LabelRole::Inbox->value] ?? 0, "the Inbox badge is the email list's bold rows");
    }

    /**
     * A conversation pinned in the conversation list puts its newest RECEIVED
     * email first in the email list — a3, not your reply a2, which is newer
     * and is what the pin was not about.
     */
    public function testAPinnedConversationPutsItsNewestReceivedEmailFirst(): void
    {
        $this->threads['A']->pinnedAt = new DateTimeImmutable('-1 minute');
        $this->em->flush();

        self::assertSame(
            [$this->mail['a3']->id, $this->mail['b1']->id, $this->mail['a1']->id],
            $this->ids($this->emails->findForUnifiedInbox($this->user, null)),
            'a3 first, and only a3: the rest of A stays in date order',
        );
        self::assertSame(
            [$this->mail['a3']->id, $this->mail['a1']->id, $this->mail['b1']->id],
            $this->ids($this->emails->findForUnifiedInbox($this->user, null, sort: ListSortOrder::Oldest)),
            'first with the oldest first as well',
        );
    }

    /** An email pinned in the email list pins its conversation in the conversation list. */
    public function testAPinnedEmailPinsItsConversation(): void
    {
        $this->mail['a1']->pinnedAt = new DateTimeImmutable('-1 minute');
        $this->em->flush();

        self::assertSame($this->mail['a1']->id, $this->ids($this->emails->findForUnifiedInbox($this->user, null))[0], 'first in the email list');
        self::assertSame($this->threads['A']->id, $this->ids($this->conversations->findForUnifiedInbox($this->user, null))[0], 'and its conversation first in the other');
    }

    public function testAnEndedEmailPinIsNoPin(): void
    {
        $this->mail['a1']->pinnedAt    = new DateTimeImmutable('-2 days');
        $this->mail['a1']->pinnedUntil = new DateTimeImmutable('-1 day');
        $this->em->flush();

        self::assertSame($this->mail['b1']->id, $this->ids($this->emails->findForUnifiedInbox($this->user, null))[0]);
        self::assertSame($this->threads['B']->id, $this->ids($this->conversations->findForUnifiedInbox($this->user, null))[0]);
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    /**
     * @param list<MessageThread|Message> $rows
     *
     * @return list<int|null>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (MessageThread|Message $row): ?int => $row->id, $rows);
    }

    private function seed(ThreadLabelSynchronizer $sync): void
    {
        $this->user            = new User();
        $this->user->email     = 'flat-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Flat';
        $this->user->nameLast  = 'List';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';
        $this->em->persist($this->user);

        $this->account                 = new Account();
        $this->account->usr            = $this->user;
        $this->account->email          = 'flat@example.test';
        $this->account->username       = 'flat@example.test';
        $this->account->imapHost       = 'localhost';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->smtpHost       = 'localhost';
        $this->account->smtpPort       = 587;
        $this->account->smtpEncryption = 'starttls';
        $this->account->password       = 'x';
        $this->account->authType       = 'password';
        $this->account->mailEnabled    = true;
        $this->em->persist($this->account);
        $this->em->flush();

        $inbox = $this->labels->systemLabel(LabelRole::Inbox, $this->account);
        $sent  = $this->labels->systemLabel(LabelRole::Sent, $this->account);
        $trash = $this->labels->systemLabel(LabelRole::Trash, $this->account);

        $this->threads['A'] = $this->conversation('A', '-1 hour');
        $this->mail['a1']   = $this->email($this->threads['A'], 'a1', '-3 hours', [$inbox], read: true);
        $this->mail['a3']   = $this->email($this->threads['A'], 'a3', '-90 minutes', [$inbox], read: false);
        $this->mail['a2']   = $this->email($this->threads['A'], 'a2', '-1 hour', [$sent], read: true);

        $this->threads['B'] = $this->conversation('B', '-20 minutes');
        $this->mail['b1']   = $this->email($this->threads['B'], 'b1', '-30 minutes', [$inbox], read: true);
        $this->mail['b2']   = $this->email($this->threads['B'], 'b2', '-20 minutes', [$inbox, $trash], read: true);

        $this->em->flush();

        foreach ($this->threads as $thread) {
            $thread->unreadCount = MessageThread::unreadAmong($thread->messages);
            $sync->sync($thread);
        }

        $this->em->flush();

        // MessageThread::$binned is the database's to keep; read it back.
        foreach ($this->threads as $thread) {
            $this->em->refresh($thread);
        }
    }

    private function conversation(string $subject, string $lastMessageAt): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->lastMessageAt     = new DateTimeImmutable($lastMessageAt);
        $thread->category          = MessageCategory::Primary;
        $thread->unreadCount       = 0;
        $this->em->persist($thread);

        return $thread;
    }

    /** @param list<Label> $labels */
    private function email(MessageThread $thread, string $subject, string $at, array $labels, bool $read): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = $subject;
        $message->fromAddress    = 'someone@example.test';
        $message->receivedAt     = new DateTimeImmutable($at);
        $message->sentAt         = $message->receivedAt;
        $message->seenAt         = true === $read ? new DateTimeImmutable($at) : null;
        $message->hasAttachments = false;
        $message->messageId      = sprintf('<%s-%s@example.test>', $subject, uniqid('', true));

        foreach ($labels as $label) {
            $message->addLabel($label);
        }

        $thread->addMessage($message);
        $this->em->persist($message);

        return $message;
    }
}
