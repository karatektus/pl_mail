<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261016090000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Everybody here when What's new ships starts at 0.3.1, and nothing else
 * moves (#165).
 *
 * Run the way Doctrine Migrations runs it, prepared, against real rows: the
 * statement is one a jsonb `?` would have broken on upgrade.
 */
final class WhatsNewSeenMigrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
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

    public function testSomebodyAlreadyHereStartsAtTheReleaseBeforeTheDialog(): void
    {
        $here = $this->user(null, ['theme.mode' => 'dark']);

        $this->migrate();

        $settings = $this->settingsOf($here);

        self::assertSame('0.3.1', $settings['whats_new.seen_version']);
        self::assertSame('dark', $settings['theme.mode'], 'the rest of the bag is left as it was');
    }

    public function testARecordedVersionIsLeftAlone(): void
    {
        $seen = $this->user('0.3.5');

        $this->migrate();

        self::assertSame('0.3.5', $this->settingsOf($seen)['whats_new.seen_version']);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function migrate(): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/Version20261016090000.php';

        $migration = new Version20261016090000($this->connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->prepare($query->getStatement())->executeStatement();
        }

        $this->em->clear();
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function user(?string $seen, array $extra = []): User
    {
        $user            = new User();
        $user->email     = 'migration-' . uniqid('', true) . '@plmail.test';
        $user->nameFirst = 'Whats';
        $user->nameLast  = 'New';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        foreach ($extra as $key => $value) {
            $user->setSetting($key, $value);
        }

        if (null !== $seen) {
            $user->whatsNewSeenVersion = $seen;
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @return array<string, mixed> */
    private function settingsOf(User $user): array
    {
        return json_decode((string) $this->connection->fetchOne('SELECT settings FROM "user" WHERE id = :id', ['id' => $user->id]), true);
    }
}
