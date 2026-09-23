<?php

declare(strict_types=1);

namespace App\Tests\Jmap\State;

use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\State\ChangeLogRepository;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Retention for the JMAP change log (app:jmap:prune-changes).
 *
 * Old history goes, a client below the new floor is told to resync, and the
 * state token of a type nobody has touched since does not go backwards —
 * pruning it to nothing would have reset it to "0".
 *
 * The cutoff is decades back so the prune, which is install-wide, touches only
 * this test's rows in a shared database; the transaction is rolled back.
 */
final class ChangeLogPruneTest extends KernelTestCase
{
    private const int ACCOUNT = 2_000_000_201;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testOldHistoryGoesAndTheStateStaysPut(): void
    {
        $container = self::getContainer();
        $state     = $container->get(StateManager::class);
        $em        = $container->get(EntityManagerInterface::class);

        $state->recordCreated(self::ACCOUNT, JmapObjectType::Email, 'e-1');
        $state->recordCreated(self::ACCOUNT, JmapObjectType::Email, 'e-2');
        $state->recordCreated(self::ACCOUNT, JmapObjectType::Mailbox, 'm-1');
        $state->recordUpdated(self::ACCOUNT, JmapObjectType::Mailbox, 'm-1');
        $em->flush();

        $emailFloor   = (int) $this->connection->fetchOne(
            "SELECT MIN(sequence) FROM jmap_change_log WHERE account_id = ? AND object_type = 'Email'",
            [self::ACCOUNT],
        );
        $mailboxState = $state->stateFor(self::ACCOUNT, JmapObjectType::Mailbox);

        // Everything old except one fresh Email change.
        $this->connection->executeStatement(
            "UPDATE jmap_change_log SET created_at = now() - interval '40 years' WHERE account_id = ?",
            [self::ACCOUNT],
        );
        $state->recordUpdated(self::ACCOUNT, JmapObjectType::Email, 'e-2');
        $em->flush();

        $pruned = $container->get(ChangeLogRepository::class)->pruneOlderThan(new \DateTimeImmutable('-30 years'));

        self::assertSame(3, $pruned, 'both old Email rows and the older Mailbox row');
        self::assertSame($mailboxState, $state->stateFor(self::ACCOUNT, JmapObjectType::Mailbox));
        self::assertSame(
            [],
            $state->changesSince(self::ACCOUNT, JmapObjectType::Mailbox, $mailboxState)->updated,
            'a client at the current state still gets "no changes"',
        );

        $this->expectException(MethodException::class);
        $state->changesSince(self::ACCOUNT, JmapObjectType::Email, (string) $emailFloor);
    }
}
