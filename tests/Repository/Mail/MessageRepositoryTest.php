<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\MessageRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The reads a Mailbox/get and the backfills are built on, against a real
 * Postgres.
 *
 * These moved into the repository from four different callers, and the risk of
 * moving them is not that they stop working — it is that they quietly stop
 * meaning the same thing. So the assertions are about meaning: that "unread" is
 * seen_at and not a flag, that a thread is counted once however many messages it
 * has in a mailbox, that another account's mail never leaks into either total,
 * and that an empty HTML body is not a body waiting to be sanitized.
 */
final class MessageRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessageRepository $repository;
    private User $user;
    private Account $account;
    private Label $inbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(MessageRepository::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount($this->user);
        $this->inbox   = $this->seedLabel($this->user, 'Counted');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── per-label counts ─────────────────────────────────────────────────────

    public function testEmailCountsSeparateUnreadFromTheTotal(): void
    {
        $this->message(seen: false, labels: [$this->inbox]);
        $this->message(seen: false, labels: [$this->inbox]);
        $this->message(seen: true, labels: [$this->inbox]);

        $counts = $this->repository->countEmailsPerLabelForAccount((int) $this->account->id);

        self::assertSame(
            ['total' => 3, 'unread' => 2],
            $counts[(int) $this->inbox->id],
        );
    }

    /**
     * The distinction the docblock insists on: flags is an IMAP mirror only one
     * sync path writes, seen_at is what the UI writes. A message marked read in
     * the browser has no \Seen flag, and must still not be counted as unread.
     */
    public function testUnreadFollowsSeenAtRatherThanTheImapSeenFlag(): void
    {
        $read = $this->message(seen: true, labels: [$this->inbox]);
        $read->flags = [];
        $this->em->flush();

        $counts = $this->repository->countEmailsPerLabelForAccount((int) $this->account->id);

        self::assertSame(0, $counts[(int) $this->inbox->id]['unread']);
    }

    public function testAnotherAccountsMailIsNotCounted(): void
    {
        $other = $this->seedAccount($this->user, 'second@example.test');

        $this->message(seen: false, labels: [$this->inbox]);
        $this->message(seen: false, labels: [$this->inbox], account: $other);

        $counts = $this->repository->countEmailsPerLabelForAccount((int) $this->account->id);

        self::assertSame(1, $counts[(int) $this->inbox->id]['total']);
    }

    /** Three messages of one conversation are one row in the list. */
    public function testThreadCountsCollapseAConversationToOne(): void
    {
        $thread = $this->thread();

        $this->message(seen: false, labels: [$this->inbox], thread: $thread);
        $this->message(seen: false, labels: [$this->inbox], thread: $thread);
        $this->message(seen: true, labels: [$this->inbox], thread: $thread);

        $counts = $this->repository->countThreadsPerLabelForAccount((int) $this->account->id);

        self::assertSame(
            ['total' => 1, 'unread' => 1],
            $counts[(int) $this->inbox->id],
        );
    }

    /**
     * Gmail-style mail can be labelled before it has been threaded. Counting it
     * as a thread would make unreadThreads exceed totalThreads, which is the
     * one invariant clients assert on.
     */
    public function testMessagesWithNoThreadAreNotCountedAsThreads(): void
    {
        $this->message(seen: false, labels: [$this->inbox]);

        $counts = $this->repository->countThreadsPerLabelForAccount((int) $this->account->id);

        self::assertArrayNotHasKey((int) $this->inbox->id, $counts);
    }

    // ── mailbox counters ─────────────────────────────────────────────────────

    public function testMailboxCountersSplitSeenFromUnseen(): void
    {
        $mailbox = $this->mailbox();

        $this->message(seen: false, mailbox: $mailbox);
        $this->message(seen: false, mailbox: $mailbox);
        $this->message(seen: true, mailbox: $mailbox);

        self::assertSame(3, $this->repository->countTotalForMailbox($mailbox));
        self::assertSame(2, $this->repository->countUnseenForMailbox($mailbox));
    }

    public function testMailboxCountersIgnoreMailInAnotherMailbox(): void
    {
        $mailbox = $this->mailbox();
        $other   = $this->mailbox('Archive');

        $this->message(seen: false, mailbox: $mailbox);
        $this->message(seen: false, mailbox: $other);

        self::assertSame(1, $this->repository->countTotalForMailbox($mailbox));
        self::assertSame(1, $this->repository->countUnseenForMailbox($mailbox));
    }

    // ── backfill cursors ─────────────────────────────────────────────────────

    /**
     * An empty string is not an HTML body, and a message that has one is not
     * waiting to be sanitized — it is a message with nothing to sanitize. Left
     * in the set, the walk would hand it to the sanitizer on every run forever.
     */
    public function testTheSafeHtmlWalkSkipsEmptyAndAlreadySanitizedBodies(): void
    {
        // The walk is install-wide, so the assertion is on what these three
        // rows add to it rather than on an absolute total.
        $before = $this->repository->countPendingSafeHtml();

        $pending = $this->message(seen: false);
        $pending->bodyHtml = '<p>needs sanitizing</p>';

        $empty = $this->message(seen: false);
        $empty->bodyHtml = '';

        $done = $this->message(seen: false);
        $done->bodyHtml     = '<p>already done</p>';
        $done->bodyHtmlSafe = '<p>already done</p>';

        $this->em->flush();

        $found = $this->repository->findPendingSafeHtml((int) $pending->id - 1, 50);

        self::assertSame([(int) $pending->id], array_map(
            static fn (Message $message): int => (int) $message->id,
            $found,
        ));
        self::assertSame($before + 1, $this->repository->countPendingSafeHtml());
    }

    /** Keyset: the walk resumes strictly after the id it was handed. */
    public function testTheSafeHtmlWalkResumesAfterTheGivenId(): void
    {
        $first = $this->message(seen: false);
        $first->bodyHtml = '<p>one</p>';

        $second = $this->message(seen: false);
        $second->bodyHtml = '<p>two</p>';

        $this->em->flush();

        $found = $this->repository->findPendingSafeHtml((int) $first->id, 50);

        self::assertSame([(int) $second->id], array_map(
            static fn (Message $message): int => (int) $message->id,
            $found,
        ));
    }

    /**
     * Threading replays history, so arrival order decides which message is a
     * parent. Two messages that arrived in the same second break the tie on id,
     * or a rebuild is not reproducible.
     */
    public function testArrivalOrderBreaksTiesOnId(): void
    {
        $sameInstant = new DateTimeImmutable('2026-03-01 09:00:00');

        $late   = $this->message(seen: false, receivedAt: new DateTimeImmutable('2026-03-01 12:00:00'));
        $firstA = $this->message(seen: false, receivedAt: $sameInstant);
        $firstB = $this->message(seen: false, receivedAt: $sameInstant);

        $ids = array_map(
            static fn (Message $message): int => (int) $message->id,
            $this->repository->findByIdsInArrivalOrder([
                (int) $late->id,
                (int) $firstB->id,
                (int) $firstA->id,
            ]),
        );

        self::assertSame([(int) $firstA->id, (int) $firstB->id, (int) $late->id], $ids);
    }

    public function testMessageIdsAreReadableFromTheirThreads(): void
    {
        $thread = $this->thread();

        $inThread = $this->message(seen: false, thread: $thread);
        $this->message(seen: false);

        self::assertSame(
            [(int) $inThread->id],
            $this->repository->findIdsForThreads([(int) $thread->id]),
        );
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * @param list<Label> $labels
     */
    private function message(
        bool               $seen,
        array              $labels = [],
        ?Account           $account = null,
        ?MessageThread     $thread = null,
        ?Mailbox           $mailbox = null,
        ?DateTimeImmutable $receivedAt = null,
    ): Message {
        $now = new DateTimeImmutable();

        $message                 = new Message();
        $message->account        = $account ?? $this->account;
        $message->mailbox        = $mailbox;
        $message->thread         = $thread;
        $message->subject        = 'Counted fixture';
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = $receivedAt ?? $now;
        $message->sentAt         = $receivedAt ?? $now;
        $message->hasAttachments = false;
        $message->flags          = true === $seen ? ['\\Seen'] : [];
        $message->seenAt         = true === $seen ? $now : null;

        foreach ($labels as $label) {
            $message->addLabel($label);
        }

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function thread(): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Counted conversation';
        $thread->normalizedSubject = 'counted conversation';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable();

        $this->em->persist($thread);
        $this->em->flush();

        return $thread;
    }

    private function mailbox(string $name = 'INBOX'): Mailbox
    {
        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $name;
        $mailbox->isSyncEnabled = true;
        $mailbox->specialUse    = 'INBOX' === $name ? MailboxSpecialUse::INBOX : null;

        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }

    private function seedLabel(User $user, string $name): Label
    {
        $label            = new Label();
        $label->usr       = $user;
        $label->name      = $name;
        $label->isVisible = true;

        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function seedAccount(User $user, string $email = 'counts@example.test'): Account
    {
        $account                 = new Account();
        $account->usr            = $user;
        $account->name           = 'Counts fixture';
        $account->email          = $email;
        $account->username       = uniqid('counts-', true);
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'counts-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Counts';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
