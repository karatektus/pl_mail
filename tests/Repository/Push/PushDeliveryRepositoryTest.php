<?php

declare(strict_types=1);

namespace App\Tests\Repository\Push;

use App\Domain\Enum\PushDeliveryOutcome;
use App\Domain\Enum\PushTransport;
use App\Entity\Push\PushDelivery;
use App\Entity\User\User;
use App\Repository\Push\PushDeliveryRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The two reads the delivery log exists for, and the one query that cannot be
 * written in DQL.
 *
 * `lastDeliveryPerDevice()` is the interesting one. It is a Postgres
 * `DISTINCT ON`, and what makes it correct is an ORDER BY that looks
 * decorative: Postgres keeps the first row of each group as ordered, so
 * dropping or reordering it returns an arbitrary delivery per device rather
 * than the newest — which nothing downstream could detect, because an arbitrary
 * row is a plausible row. The test seeds an older success after a newer failure
 * so insertion order and correct order disagree.
 *
 * The admin filters are the other read, and they are tested together with the
 * count because the two must describe the same set: a table showing three rows
 * under a heading that says seven is a bug report about the log rather than
 * about push.
 */
final class PushDeliveryRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private PushDeliveryRepository $repository;

    private User $user;
    private User $other;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(PushDeliveryRepository::class);

        $this->connection->beginTransaction();

        $this->user  = $this->seedUser('owner');
        $this->other = $this->seedUser('stranger');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheLastDeliveryPerDeviceIsTheNewestAndNotWhicheverRowCameBackFirst(): void
    {
        // Inserted newest-first on purpose, so a query that trusted insertion
        // order would answer with the older row.
        $this->record('a-phone', PushDeliveryOutcome::Failed, '-1 hour');
        $this->record('a-phone', PushDeliveryOutcome::Accepted, '-1 day');
        $this->record('a-browser', PushDeliveryOutcome::Accepted, '-2 days');

        $byDevice = $this->repository->lastDeliveryPerDevice((int) $this->user->id);

        self::assertCount(2, $byDevice, 'one entry per device, not per delivery');
        self::assertSame(PushDeliveryOutcome::Failed, $byDevice['a-phone']->outcome, 'the newest row wins');
        self::assertSame(PushDeliveryOutcome::Accepted, $byDevice['a-browser']->outcome);
    }

    /** A device nothing has been sent to has no entry, which is what the settings pane reads as "nothing sent yet". */
    public function testADeviceWithNoDeliveriesIsSimplyAbsent(): void
    {
        $this->record('a-phone', PushDeliveryOutcome::Accepted, '-1 hour');

        self::assertArrayNotHasKey('a-browser', $this->repository->lastDeliveryPerDevice((int) $this->user->id));
    }

    public function testOneUsersDevicesAreNeverAnothersBusiness(): void
    {
        $this->record('a-phone', PushDeliveryOutcome::Accepted, '-1 hour');
        $this->record('a-phone', PushDeliveryOutcome::Failed, '-1 hour', $this->other);

        $byDevice = $this->repository->lastDeliveryPerDevice((int) $this->user->id);

        self::assertSame(PushDeliveryOutcome::Accepted, $byDevice['a-phone']->outcome, 'a device id is only unique per user');
    }

    public function testTheFilterAndTheCountDescribeTheSameRows(): void
    {
        $this->record('a-phone', PushDeliveryOutcome::Failed, '-1 hour');
        $this->record('a-browser', PushDeliveryOutcome::Accepted, '-2 hours', transport: PushTransport::WebPush);
        $this->record('a-phone', PushDeliveryOutcome::Accepted, '-3 hours');

        $found = $this->repository->search((int) $this->user->id, PushTransport::Fcm, null, 50, 0);

        self::assertCount(2, $found);
        self::assertSame(2, $this->repository->countSearch((int) $this->user->id, PushTransport::Fcm, null));
        self::assertSame(
            PushDeliveryOutcome::Failed,
            $found[0]->outcome,
            'newest first — the admin page is read from the top',
        );
    }

    public function testFilteringByOutcomeNarrowsToThatOutcomeAlone(): void
    {
        $this->record('a-phone', PushDeliveryOutcome::SubscriptionDestroyed, '-1 hour');
        $this->record('a-phone', PushDeliveryOutcome::Accepted, '-2 hours');

        $found = $this->repository->search(null, null, PushDeliveryOutcome::SubscriptionDestroyed, 50, 0);

        self::assertCount(1, $found);
        self::assertSame(PushDeliveryOutcome::SubscriptionDestroyed, $found[0]->outcome);
    }

    public function testPruningDropsWhatIsOlderThanTheCutoffAndKeepsTheRest(): void
    {
        $this->record('a-phone', PushDeliveryOutcome::Accepted, '-40 days');
        $this->record('a-phone', PushDeliveryOutcome::Accepted, '-1 day');

        self::assertSame(1, $this->repository->pruneOlderThan(new DateTimeImmutable('-30 days')));
        self::assertSame(1, $this->repository->countSearch((int) $this->user->id, null, null));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function record(
        string              $deviceClientId,
        PushDeliveryOutcome $outcome,
        string              $ago,
        ?User               $usr = null,
        PushTransport       $transport = PushTransport::Fcm,
    ): void {
        $delivery = new PushDelivery(
            $usr ?? $this->user,
            $deviceClientId,
            $transport,
            'StateChange',
            $outcome,
            null,
            12,
        );

        $this->em->persist($delivery);
        $this->em->flush();

        // createdAt is stamped by the trait on persist, and these tests are
        // entirely about ordering by it — so it is moved afterwards, in SQL,
        // rather than by making the column writable for the sake of a test.
        $this->connection->executeStatement(
            'UPDATE push_delivery SET created_at = :at WHERE id = :id',
            ['at' => new DateTimeImmutable($ago)->format('Y-m-d H:i:s'), 'id' => $delivery->id],
        );

        // Not em->clear(): the seeded users are held by this test class, and
        // detaching them would make the next persist() insert a second copy.
        // The rows below are read through queries, which see the UPDATE.
        $this->em->refresh($delivery);
    }

    private function seedUser(string $name): User
    {
        $user = new User();
        $user->email     = $name . '-' . uniqid('', true) . '@example.test';
        $user->nameFirst = $name;
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
