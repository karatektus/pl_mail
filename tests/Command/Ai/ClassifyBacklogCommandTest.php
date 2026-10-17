<?php

declare(strict_types=1);

namespace App\Tests\Command\Ai;

use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ClassifyMailMessage;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Mail older than the age limit is sorted only when somebody asks (#208).
 *
 * The scheduled run stops at the limit in Admin → AI; `--any-age` is the one
 * way past it, and the promise the issue was accepted on — "older mail can
 * still be classified on demand, so nothing is lost" — is this option. Without
 * it the limit would be a one-way door.
 */
final class ClassifyBacklogCommandTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private CommandTester $command;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);

        $this->connection->beginTransaction();

        $this->seedYearOldMailbox(3);

        $this->command = new CommandTester(
            new Application(self::$kernel)->find('app:ai:classify-backlog'),
        );
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheScheduledRunLeavesMailPastTheLimitAlone(): void
    {
        self::assertSame(Command::SUCCESS, $this->command->execute(['--email' => $this->user->email]));
        self::assertSame([], $this->queued());
    }

    public function testAnyAgeQueuesTheMailPastTheLimit(): void
    {
        self::assertSame(Command::SUCCESS, $this->command->execute([
            '--email'   => $this->user->email,
            '--any-age' => true,
        ]));

        $queued = $this->queued();

        self::assertCount(3, array_merge(...array_map(static fn (ClassifyMailMessage $message): array => $message->messageIds, $queued)));

        foreach ($queued as $message) {
            self::assertTrue($message->anyAge, 'the handler applies the limit again unless the envelope says otherwise');
        }
    }

    /** @return list<ClassifyMailMessage> */
    private function queued(): array
    {
        $transport = self::getContainer()->get('messenger.transport.enrich_backlog');

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $messages = [];

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            self::assertInstanceOf(ClassifyMailMessage::class, $message);

            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * An imported mailbox whose import is over, of mail a year old, for
     * somebody the assistant sorts for under the shipped thirty-day limit.
     */
    private function seedYearOldMailbox(int $count): void
    {
        $settings = $this->em->getRepository(AiSettings::class)->findOneBy([]) ?? new AiSettings();

        $settings->isEnabled             = true;
        $settings->baseUrl               = 'http://model-host.invalid:11434';
        $settings->chatModel             = 'qwen3:4b-instruct';
        $settings->categorisationEnabled = true;
        $settings->categoriseBacklogDays = AiSettings::DEFAULT_CATEGORISE_BACKLOG_DAYS;
        $this->em->persist($settings);

        $this->user = new User();
        $this->user->email = 'classify-backlog-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Classify';
        $this->user->nameLast = 'Backlog';
        $this->user->roles = ['ROLE_USER'];
        $this->user->password = 'x';
        $this->em->persist($this->user);

        $account = new Account();
        $account->usr = $this->user;
        $account->email = 'classify-backlog-fixture@example.test';
        $account->username = 'classify-backlog-fixture@example.test';
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

        // What ends an IMAP import: no folder has history left to bring in.
        $mailbox = new Mailbox();
        $mailbox->account = $account;
        $mailbox->name = 'INBOX';
        $mailbox->fullPath = 'INBOX';
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $mailbox->syncedAt = new DateTimeImmutable('-1 day');
        $mailbox->importFloorUid = 0;
        $this->em->persist($mailbox);

        for ($i = 0; $i < $count; $i++) {
            $message = new Message();
            $message->account = $account;
            $message->mailbox = $mailbox;
            $message->subject = 'Year-old fixture ' . $i;
            $message->fromAddress = 'somebody@elsewhere.test';
            $message->receivedAt = new DateTimeImmutable(sprintf('-%d days', 365 + $i));
            $message->hasAttachments = false;
            $message->messageId = sprintf('<year-old-%d-%s@example.test>', $i, uniqid('', true));
            $this->em->persist($message);
        }

        $this->em->flush();
    }
}
