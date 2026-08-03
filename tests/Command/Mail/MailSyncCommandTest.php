<?php

declare(strict_types=1);

namespace App\Tests\Command\Mail;

use App\Command\Mail\MailSyncCommand;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Repository\Mail\AccountRepository;
use App\Tests\Command\MailFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * The manual way to make a mailbox sync.
 *
 * It dispatches rather than syncs, which is the property worth protecting: the
 * command returns immediately and a worker does the work, so an operator
 * running this on a box with a stalled worker gets a job queued, not a
 * half-finished sync in a terminal they might close. Every assertion below is
 * about *what was dispatched* rather than what was printed.
 *
 * The bus is doubled — dispatching for real would put a job on the transport
 * the running stack's worker consumes.
 */
final class MailSyncCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    /** @var list<SyncAccountMessage> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->dispatched = [];

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItDispatchesASyncForTheAccountItWasGiven(): void
    {
        $account = $this->account();
        $other   = $this->account();

        $exit = $this->tester()->execute(['account-id' => (string) $account->id]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame([$account->id], $this->dispatchedAccountIds());
        self::assertNotContains($other->id, $this->dispatchedAccountIds());
    }

    public function testAnUnknownAccountIdDispatchesNothingAndFails(): void
    {
        $exit = $this->tester()->execute(['account-id' => '99999999']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame([], $this->dispatched, 'A typo must not queue a sync for somebody else.');
    }

    public function testWithNoArgumentItDispatchesForEveryActiveAccount(): void
    {
        $active   = $this->account();
        $inactive = $this->account(isActive: false);

        $exit = $this->tester()->execute([]);

        self::assertSame(Command::SUCCESS, $exit);

        $ids = $this->dispatchedAccountIds();

        self::assertContains($active->id, $ids);

        // Disabled accounts have no working credentials by definition; syncing
        // one produces a failed job and an auth error in the log every run.
        self::assertNotContains($inactive->id, $ids);
    }

    public function testItDispatchesExactlyOneJobPerActiveAccount(): void
    {
        $this->account();
        $this->account();

        $this->tester()->execute([]);

        $ids = $this->dispatchedAccountIds();

        self::assertSame(
            count($ids),
            count(array_unique($ids)),
            'A duplicated dispatch means the same mailbox is synced twice concurrently.',
        );
        self::assertCount(
            count($this->repository()->findBy(['isActive' => true])),
            $ids,
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** @return list<int> */
    private function dispatchedAccountIds(): array
    {
        return array_map(
            static fn (SyncAccountMessage $message): int => $message->accountId,
            $this->dispatched,
        );
    }

    private function account(bool $isActive = true): Account
    {
        return MailFixtures::account($this->em, MailFixtures::user($this->em, 'sync'), isActive: $isActive);
    }

    private function repository(): AccountRepository
    {
        return self::getContainer()->get(AccountRepository::class);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new MailSyncCommand($this->repository(), $this->bus()));
    }

    private function bus(): MessageBusInterface
    {
        $recorder = function (object $message): void {
            self::assertInstanceOf(SyncAccountMessage::class, $message);

            $this->dispatched[] = $message;
        };

        return new class ($recorder) implements MessageBusInterface {
            /** @param \Closure(object): void $recorder */
            public function __construct(private readonly \Closure $recorder) {}

            /** @param array<StampInterface> $stamps */
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                ($this->recorder)($message);

                return new Envelope($message);
            }
        };
    }
}
