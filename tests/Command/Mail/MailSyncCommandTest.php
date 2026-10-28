<?php

declare(strict_types=1);

namespace App\Tests\Command\Mail;

use App\Command\Mail\MailSyncCommand;
use App\Service\Demo\DemoMode;
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

    // ── --due: each account at its own interval (#202) ────────────────────

    /**
     * The scheduler runs `--due` every minute. An account never polled is due,
     * one polled within its interval is not, and one whose interval ran out is
     * — measured from the account's own interval, not the default.
     */
    public function testDueDispatchesOnlyTheAccountsWhoseIntervalRanOut(): void
    {
        $never   = $this->account();
        $recent  = $this->polled(minutesAgo: 3, interval: 5);
        $overdue = $this->polled(minutesAgo: 6, interval: 5);
        $slow    = $this->polled(minutesAgo: 20, interval: 30);

        $this->tester()->execute(['--due' => true]);

        $ids = $this->dispatchedAccountIds();

        self::assertContains($never->id, $ids);
        self::assertContains($overdue->id, $ids);
        self::assertNotContains($recent->id, $ids);
        self::assertNotContains($slow->id, $ids, 'twenty minutes is not due on a thirty-minute interval');
    }

    /**
     * The scheduler fires on the minute and the stamp lands a moment after, so
     * a poll is asked again a few seconds short of its interval. That has to
     * count, or every interval comes out a minute longer than chosen.
     */
    public function testAPollAFewSecondsShortOfItsIntervalIsDue(): void
    {
        $account = $this->account();
        $account->syncIntervalMinutes = 5;
        $account->lastPolledAt        = new \DateTimeImmutable('-298 seconds');
        $this->em->flush();

        $this->tester()->execute(['--due' => true]);

        self::assertContains($account->id, $this->dispatchedAccountIds());
    }

    /** What makes the next tick skip it. */
    public function testADispatchStampsTheAccountAsPolled(): void
    {
        $account = $this->account();

        $this->tester()->execute(['--due' => true]);
        $this->dispatched = [];
        $this->tester()->execute(['--due' => true]);

        self::assertNotNull($account->lastPolledAt);
        self::assertNotContains($account->id, $this->dispatchedAccountIds(), 'polled a moment ago, so not due again');
    }

    private function polled(int $minutesAgo, int $interval): Account
    {
        $account                      = $this->account();
        $account->syncIntervalMinutes = $interval;
        $account->lastPolledAt        = new \DateTimeImmutable(sprintf('-%d minutes', $minutesAgo));
        $this->em->flush();

        return $account;
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

    /**
     * @param bool $demo whether the command should believe it is on a demo
     *                   install, which is the one condition under which it
     *                   dispatches nothing at all
     */
    private function tester(bool $demo = false): CommandTester
    {
        return new CommandTester(
            new MailSyncCommand($this->repository(), $this->bus(), new DemoMode($demo, 'PT2H'), $this->em),
        );
    }

    /**
     * A demo instance's accounts point at documentation domains, so every
     * dispatch would be a worker spending its full retry ladder on a host that
     * does not resolve. The scheduler runs this every minute, so this
     * branch is what actually keeps a demo's queue clean.
     */
    public function testItDispatchesNothingOnADemoInstall(): void
    {
        $this->account();
        $this->account();

        $tester = $this->tester(demo: true);

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([], $this->dispatched);
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
