<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The admin sync overview, whose one difficult question is what "a message
 * belongs to this account" means.
 *
 * There are two answers, and an install can hold both at once: plain IMAP mail
 * reaches its account through a mailbox, Gmail-API mail has no mailbox row at
 * all and reaches it through its thread. Counting through either one alone
 * reports zero for half the world's accounts — and reports it as a fact, on the
 * page an operator consults when sync looks broken.
 */
final class AccountRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private AccountRepository $repository;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(AccountRepository::class);

        $this->connection->beginTransaction();

        $this->user = $this->seedUser();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testMailIsCountedThroughItsMailboxAndThroughItsThread(): void
    {
        $account = $this->account('overview@example.test');
        $mailbox = $this->mailbox($account);
        $thread  = $this->thread($account, '2026-03-01 09:00');

        // Plain IMAP: a mailbox, no thread.
        $this->message($account, mailbox: $mailbox);

        // Gmail API: a thread, no mailbox.
        $this->message($account, thread: $thread);

        $row = $this->overviewFor($account);

        self::assertSame(2, (int) $row['messages']);
        self::assertSame(1, (int) $row['threads']);
    }

    /** A message that has both must still be one message, not two. */
    public function testAMessageWithBothAMailboxAndAThreadIsCountedOnce(): void
    {
        $account = $this->account('overview@example.test');
        $mailbox = $this->mailbox($account);
        $thread  = $this->thread($account, '2026-03-01 09:00');

        $this->message($account, mailbox: $mailbox, thread: $thread);

        self::assertSame(1, (int) $this->overviewFor($account)['messages']);
    }

    public function testLastActivityIsTheNewestConversation(): void
    {
        $account = $this->account('overview@example.test');

        $this->thread($account, '2026-01-01 09:00');
        $this->thread($account, '2026-03-01 09:00');

        self::assertStringStartsWith('2026-03-01', (string) $this->overviewFor($account)['last_activity']);
    }

    public function testAnAccountWithNoMailIsStillListed(): void
    {
        $account = $this->account('empty@example.test');

        $row = $this->overviewFor($account);

        self::assertSame(0, (int) $row['messages']);
        self::assertSame(0, (int) $row['threads']);
        self::assertNull($row['last_activity']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function overviewFor(Account $account): array
    {
        foreach ($this->repository->findSyncOverviewRows() as $row) {
            if ((int) $row['id'] === (int) $account->id) {
                return $row;
            }
        }

        self::fail(sprintf('Account #%d is missing from the overview.', (int) $account->id));
    }

    private function message(
        Account        $account,
        ?Mailbox       $mailbox = null,
        ?MessageThread $thread = null,
    ): Message {
        $message                 = new Message();
        $message->account        = $account;
        $message->mailbox        = $mailbox;
        $message->thread         = $thread;
        $message->subject        = 'Overview fixture';
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new DateTimeImmutable('2026-03-01 09:00');
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function thread(Account $account, string $lastMessageAt): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = 'Overview conversation';
        $thread->normalizedSubject = 'overview conversation';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable($lastMessageAt);

        $this->em->persist($thread);
        $this->em->flush();

        return $thread;
    }

    private function mailbox(Account $account): Mailbox
    {
        $mailbox                = new Mailbox();
        $mailbox->account       = $account;
        $mailbox->name          = 'INBOX';
        $mailbox->fullPath      = 'INBOX';
        $mailbox->isSyncEnabled = true;

        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }

    private function account(string $email): Account
    {
        $account                 = new Account();
        $account->usr            = $this->user;
        $account->name           = 'Overview fixture';
        $account->email          = $email;
        $account->username       = uniqid('overview-', true);
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
        $user->email     = 'overview-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Overview';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
