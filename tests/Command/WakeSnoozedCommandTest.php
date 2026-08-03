<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Label\LabelResolver;
use App\Service\Mail\ThreadSnoozeService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The sweep is what makes a snooze a snooze.
 *
 * Without it a snoozed conversation leaves the inbox and never comes back,
 * which is strictly worse than not offering the feature — the mail is not lost
 * but the user has no reason to expect it anywhere. The scheduler runs this
 * every minute; these tests cover what it does when it fires.
 */
final class WakeSnoozedCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private LabelResolver $labelResolver;
    private ThreadSnoozeService $snoozeService;
    private CommandTester $command;

    private Account $account;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em            = $container->get(EntityManagerInterface::class);
        $this->connection    = $container->get(Connection::class);
        $this->labelResolver = $container->get(LabelResolver::class);
        $this->snoozeService = $container->get(ThreadSnoozeService::class);

        $this->connection->beginTransaction();

        $this->account = $this->seedAccount();
        $this->mailbox = $this->seedMailbox();

        $this->command = new CommandTester(
            new Application(self::$kernel)->find('app:mail:wake-snoozed'),
        );
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAThreadWhoseTimeHasPassedComesBack(): void
    {
        $thread = $this->snoozedThread('-5 minutes');

        $this->command->execute([]);

        self::assertSame(0, $this->command->getStatusCode());
        self::assertStringContainsString('Woke 1', $this->command->getDisplay());

        self::assertNull($thread->snoozedUntil);
        self::assertContains(LabelRole::Inbox, $this->rolesOn($thread));
    }

    /** The whole point of a wake time: before it, nothing happens. */
    public function testAThreadStillInTheFutureIsLeftAlone(): void
    {
        $thread = $this->snoozedThread('+2 days');

        $this->command->execute([]);

        self::assertNotNull($thread->snoozedUntil);
        self::assertNotContains(LabelRole::Inbox, $this->rolesOn($thread));
    }

    /**
     * The scheduler replays a missed run when a worker comes back up, so a
     * second sweep over the same threads must be a no-op rather than a second
     * round of label churn and provider calls.
     */
    public function testASecondSweepDoesNothing(): void
    {
        $thread = $this->snoozedThread('-5 minutes');

        $this->command->execute([]);
        $this->command->execute([]);

        // Silence, not "Woke 0": with nothing due the command returns before
        // it writes anything, which is what keeps a once-a-minute sweep out of
        // the logs.
        self::assertSame('', $this->command->getDisplay());
        self::assertSame(0, $this->command->getStatusCode());
        self::assertNull($thread->snoozedUntil);
    }

    /** Nothing due is the normal case, and must stay quiet and successful. */
    public function testAnEmptySweepSucceeds(): void
    {
        $this->command->execute([]);

        self::assertSame(0, $this->command->getStatusCode());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** @return list<LabelRole|null> */
    private function rolesOn(MessageThread $thread): array
    {
        $roles = [];

        foreach ($thread->messages as $message) {
            foreach ($message->labels as $label) {
                $roles[] = $label->role;
            }
        }

        return $roles;
    }

    private function snoozedThread(string $when): MessageThread
    {
        $thread = new MessageThread();
        $thread->account = $this->account;
        $thread->subject = 'Wake fixture';
        $thread->normalizedSubject = 'wake fixture';
        $thread->lastMessageAt = new \DateTimeImmutable('-1 hour');
        $thread->threadingMethod = ThreadingMethod::References;
        $thread->unreadCount = 0;
        $this->em->persist($thread);

        $message = new Message();
        $message->account = $this->account;
        $message->subject = 'Wake fixture';
        $message->fromAddress = 'sender@example.test';
        $message->receivedAt = new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->messageId = sprintf('<wake-%s@example.test>', uniqid('', true));
        $message->mailbox = $this->mailbox;
        $message->imapUid = 7000;
        $message->addLabel($this->labelResolver->systemLabel(LabelRole::Inbox, $this->account));

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        // Through the service, so the fixture is in the state a real snooze
        // leaves behind rather than one this test invented.
        $this->snoozeService->snooze($thread, new \DateTimeImmutable($when));

        return $thread;
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user->email = 'wake-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Wake';
        $user->nameLast = 'Fixture';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account
            ->setUsr($user)
            ->setEmail('Wake Fixture')
            ->setUsername('wake-fixture@example.test')
            ->setImapHost('localhost')
            ->setImapPort(993)
            ->setImapEncryption('ssl')
            ->setSmtpHost('localhost')
            ->setSmtpPort(587)
            ->setSmtpEncryption('starttls')
            ->setPassword('x')
            ->setAuthType('password')
            ->setIsActive(true);
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedMailbox(): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->account = $this->account;
        $mailbox->name = 'INBOX';
        $mailbox->fullPath = 'INBOX';
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $mailbox->createdAt = new \DateTimeImmutable();
        $mailbox->updatedAt = new \DateTimeImmutable();

        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
