<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User\User;
use App\Repository\System\WorkerSettings\WorkerSettingsRepository;
use App\Repository\User\User\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * How many mail jobs run at once, set on Admin → Performance (#610).
 *
 * The empty choice stores null rather than the default's current value, for
 * the reason LogLevelSettingTest pins the same thing: an installation that
 * never chose follows a later release's better default.
 *
 * Skips itself without the seeded admin, as the admin tests next door do.
 */
final class MailConcurrencySettingTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $container        = static::getContainer();
        $this->connection = $container->get(Connection::class);

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $admin instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->client->loginUser($admin);

        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM worker_settings');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testWithNothingChosenTheWorkerRunsTheDefault(): void
    {
        self::assertSame(WorkerSettingsRepository::DEFAULT_CONCURRENCY, $this->repository()->concurrency());
    }

    public function testAnAdminCanChooseHowManyRunAtOnce(): void
    {
        $this->post('2');

        self::assertResponseRedirects();
        self::assertSame(2, $this->stored());
        self::assertSame(2, $this->repository()->concurrency(), 'what the worker reads');
    }

    public function testTheEmptyChoiceGoesBackToTheDefault(): void
    {
        $this->post('2');
        $this->post('');

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM worker_settings'));
        self::assertNull($this->stored());
    }

    /** A hand-made request gets the nearest number the worker allows. */
    public function testANumberOutsideTheRangeIsClamped(): void
    {
        $this->post('500');
        self::assertSame(WorkerSettingsRepository::MAX_CONCURRENCY, $this->stored());

        $this->post('0');
        self::assertSame(WorkerSettingsRepository::MIN_CONCURRENCY, $this->stored());
    }

    public function testSomethingThatIsNotANumberMeansTheDefault(): void
    {
        $this->post('lots');

        self::assertNull($this->stored());
    }

    public function testItRefusesAPostWithoutAValidToken(): void
    {
        $this->client->request('POST', '/admin/performance/mail-concurrency', ['concurrency' => '2', '_token' => 'nope']);

        self::assertNotSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->stored());
    }

    private function repository(): WorkerSettingsRepository
    {
        return static::getContainer()->get(WorkerSettingsRepository::class);
    }

    /** From the table, for the identity-map reason LogLevelSettingTest gives. */
    private function stored(): ?int
    {
        $value = $this->connection->fetchOne('SELECT mail_concurrency FROM worker_settings ORDER BY id ASC LIMIT 1');

        return false === $value || null === $value ? null : (int) $value;
    }

    private function post(string $concurrency): void
    {
        $this->client->request('POST', '/admin/performance/mail-concurrency', [
            'concurrency' => $concurrency,
            '_token'      => $this->token(),
        ]);
    }

    /** Off the rendered form: the token store is the session. */
    private function token(): string
    {
        $crawler = $this->client->request('GET', '/admin/performance');

        self::assertResponseIsSuccessful();

        return (string) $crawler
            ->filter('form[action$="/performance/mail-concurrency"] input[name="_token"]')
            ->attr('value');
    }
}
