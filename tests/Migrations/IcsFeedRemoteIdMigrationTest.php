<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Integration\Provider;
use App\Entity\Calendar\Calendar;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Service\Calendar\Sync\IcsUrl\IcsUrlCalendarDriver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261028150000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That a feed's calendar stored as `feed` comes out carrying the id the driver
 * now discovers it by (#424).
 *
 * The one claim worth a test is that PostgreSQL's sha256() and PHP's hash()
 * agree on the stored address. If they did not, every existing feed would stop
 * being recognised as its own subscription's calendar, and the next "Find
 * calendars" would offer to mirror it a second time.
 */
final class IcsFeedRemoteIdMigrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

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

    public function testAFeedCalendarTakesTheIdTheDriverDiscoversItBy(): void
    {
        // A query string, because signed feed addresses are the ones that
        // carry characters worth getting the bytes of right.
        $feed     = $this->seedIntegration(Provider::Ics, 'https://calendar.example.test/ical/a%40b.test/private-1f2e/basic.ics?hl=de&ü=1');
        $calendar = $this->seedCalendar($feed, 'feed');

        $this->migrate();

        self::assertSame(IcsUrlCalendarDriver::remoteIdOf($feed), $this->remoteIdOf($calendar));
    }

    /** Only feeds: a CalDAV calendar's id is the provider's, whatever it says. */
    public function testOtherProvidersAreLeftAlone(): void
    {
        $nextcloud = $this->seedIntegration(Provider::CalDav, 'https://cloud.example.test/remote.php/dav');
        $calendar  = $this->seedCalendar($nextcloud, 'feed');

        $this->migrate();

        self::assertSame('feed', $this->remoteIdOf($calendar));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function migrate(): void
    {
        // The migrations directory is read by Doctrine's own loader, not by
        // Composer, so nothing has autoloaded the class by the time a test runs.
        require_once dirname(__DIR__, 2) . '/migrations/Version20261028150000.php';

        $migration = new Version20261028150000($this->connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $this->em->clear();
    }

    private function remoteIdOf(Calendar $calendar): string
    {
        return (string) $this->connection->fetchOne('SELECT remote_id FROM calendar WHERE id = :id', ['id' => $calendar->id]);
    }

    private function seedIntegration(Provider $provider, string $url): Integration
    {
        $integration          = new Integration($this->user, $provider, $provider->value . ' fixture');
        $integration->baseUrl = $url;

        $this->em->persist($integration);
        $this->em->flush();

        return $integration;
    }

    private function seedCalendar(Integration $integration, string $remoteId): Calendar
    {
        $calendar              = new Calendar();
        $calendar->usr         = $this->user;
        $calendar->name        = $integration->name;
        $calendar->integration = $integration;
        $calendar->role        = CalendarRole::Remote;
        $calendar->remoteId    = $remoteId;
        $calendar->timeZone    = 'UTC';

        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'feed-id-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Feed';
        $user->nameLast  = 'Id';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
