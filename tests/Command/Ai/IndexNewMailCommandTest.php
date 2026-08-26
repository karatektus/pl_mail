<?php

declare(strict_types=1);

namespace App\Tests\Command\Ai;

use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\EmbedMessagesMessage;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The nightly backstop, and the ceiling that keeps it from becoming a backfill.
 *
 * Mail is no longer indexed as it arrives. The daytime trigger — a small batch
 * queued in the warm window after somebody searches — does most of the work,
 * and this is what covers the person who has not searched in a fortnight.
 *
 * THE CEILING IS THE PROPERTY WORTH ASSERTING. Without it, the first night this
 * runs on an installation that never did a pass would find every message it has
 * ever received outstanding, queue all of them onto the ingest transport, and
 * put arriving mail behind hours of catalogue work — with no state row, no
 * resume and no pause button, because those belong to `app:ai:embed-mailbox`.
 * That is not a slower version of this command; it is a second backfill nobody
 * can stop.
 */
final class IndexNewMailCommandTest extends KernelTestCase
{
    /** Comfortably fewer than the mailbox holds, so a missing ceiling is obvious. */
    private const int CEILING = 3;

    private Connection $connection;
    private EntityManagerInterface $em;
    private CommandTester $command;
    private User $user;

    /** @var list<int> oldest first */
    private array $messageIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);

        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM ai_settings');

        $this->seedMailbox(10);

        $this->command = new CommandTester(
            new Application(self::$kernel)->find('app:ai:index-new-mail'),
        );
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The sweep stops at the ceiling, and spends it on the newest mail.
     *
     * Scoped with --email rather than run over the whole install, because the
     * command walks every mailbox by design and the test database holds the
     * seeded users the browser suite needs. Their mailboxes are empty, so a
     * full run would still queue exactly this — but it would be an assertion
     * about what else happens to be in the database, which is the shape of a
     * test that fails on a second afternoon.
     */
    public function testTheSweepQueuesAtMostItsCeilingPerMailbox(): void
    {
        $this->enableSemanticSearch();

        $this->command->execute(['--email' => (string) $this->user->email, '--limit' => (string) self::CEILING]);

        self::assertSame(Command::SUCCESS, $this->command->getStatusCode());
        self::assertStringContainsString('Queued 3 message(s)', $this->command->getDisplay());

        self::assertSame(array_slice(array_reverse($this->messageIds), 0, self::CEILING), $this->queuedIds());
    }

    /**
     * A ceiling of zero would be a job that runs every night and does nothing,
     * so it is clamped rather than obeyed — the same bargain
     * app:ai:prune-metrics strikes with its retention window, and for the same
     * reason: this reads its number from a schedule nobody watches.
     */
    public function testANonsenseCeilingIsClampedRatherThanObeyed(): void
    {
        $this->enableSemanticSearch();

        $this->command->execute(['--email' => (string) $this->user->email, '--limit' => '0']);

        self::assertSame(Command::SUCCESS, $this->command->getStatusCode());
        self::assertCount(1, $this->queuedIds(), 'a limit of zero clamps to one, not to everything');
    }

    /**
     * It walks every mailbox when told to, which is how the schedule runs it.
     */
    public function testItSweepsEveryMailboxWhenNoneIsNamed(): void
    {
        $this->enableSemanticSearch();

        $this->command->execute(['--limit' => (string) self::CEILING]);

        self::assertSame(Command::SUCCESS, $this->command->getStatusCode());
        self::assertSame(array_slice(array_reverse($this->messageIds), 0, self::CEILING), $this->queuedIds());
    }

    /**
     * Success, not failure, on an installation that never switched the AI on.
     *
     * This runs nightly everywhere, and almost nowhere is it wanted. A non-zero
     * exit would put a red line in the scheduler log every night for a feature
     * nobody asked for — where `app:ai:embed-mailbox` refuses loudly, and
     * should, because somebody typed that one.
     */
    public function testASwitchedOffInstallationSaysSoAndSucceeds(): void
    {
        $this->command->execute([]);

        self::assertSame(Command::SUCCESS, $this->command->getStatusCode());
        self::assertStringContainsString('nothing to index', $this->command->getDisplay());
        self::assertSame([], $this->queue()->getSent());
    }

    /** @return list<int> */
    private function queuedIds(): array
    {
        $ids = [];

        foreach ($this->queue()->getSent() as $envelope) {
            $message = $envelope->getMessage();

            self::assertInstanceOf(EmbedMessagesMessage::class, $message);

            $ids = [...$ids, ...$message->messageIds];
        }

        return $ids;
    }

    private function queue(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.ingest');

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function enableSemanticSearch(): void
    {
        $settings = $this->em->getRepository(AiSettings::class)->findOneBy([]) ?? new AiSettings();

        $settings->isEnabled      = true;
        $settings->baseUrl        = 'http://model-host.invalid:11434';
        $settings->embeddingModel = 'qwen3-embedding:0.6b';
        $settings->searchEnabled  = true;

        $this->em->persist($settings);
        $this->em->flush();
    }

    /** A threaded mailbox, which is the join the finder walks to decide whose mail it is. */
    private function seedMailbox(int $messages): void
    {
        $user = new User();
        $user->email     = 'sweep-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Sweep';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr            = $user;
        $account->email          = 'sweep-fixture@example.test';
        $account->username       = 'sweep-fixture@example.test';
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

        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = 'Sweep fixture';
        $thread->normalizedSubject = 'sweep fixture';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable();
        $this->em->persist($thread);

        $created = [];

        for ($i = 0; $i < $messages; $i++) {
            $message = new Message();
            $message->account        = $account;
            $message->thread         = $thread;
            $message->subject        = sprintf('Sweep fixture %d', $i);
            $message->fromAddress    = 'sender@example.test';
            $message->messageId      = sprintf('sweep-%s-%d@example.test', uniqid('', true), $i);
            $message->receivedAt     = new DateTimeImmutable();
            $message->sentAt         = $message->receivedAt;
            $message->hasAttachments = false;
            $message->flags          = [];
            $this->em->persist($message);

            $created[] = $message;
        }

        $this->em->flush();

        $this->user       = $user;
        $this->messageIds = array_map(static fn (Message $m): int => (int) $m->id, $created);
    }
}
