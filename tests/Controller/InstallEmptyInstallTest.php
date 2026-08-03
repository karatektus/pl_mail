<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Infrastructure\Setup\GeneratedSecretsFile;
use App\Repository\User\UserRepository;
use App\Service\Monitoring\WorkerRestartSignal;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /install on the one install state it is meant for: no users at all.
 *
 * The seeded database has users, and this is the only page whose behaviour
 * depends on there being none — so the test empties it inside a transaction and
 * rolls back, rather than requiring a dedicated stack for one assertion. The
 * rollback is not a tidiness measure: without it this test destroys the seed
 * every other suite and the e2e run depend on.
 *
 * The request under test opens its own transaction — the advisory-locked write
 * in UserRepository::createFirstAdmin — which DBAL nests inside this one rather
 * than committing it, so the rollback still reaches everything.
 */
final class InstallEmptyInstallTest extends WebTestCase
{
    private ?Connection $connection = null;

    private ?string $configPath = null;

    protected function tearDown(): void
    {
        if (null !== $this->connection && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->connection = null;

        // Captured during the test: the kernel is gone by now, and asking the
        // container for anything here boots a second one.
        if (null !== $this->configPath && is_file($this->configPath)) {
            unlink($this->configPath);
        }

        $this->configPath = null;

        parent::tearDown();
    }

    public function testItCreatesTheFirstAdministratorAndSignsThemIn(): void
    {
        $client = static::createClient();
        // Without this the kernel is rebooted between requests, and the new
        // container's connection cannot see the uncommitted truncate.
        $client->disableReboot();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $users         = static::getContainer()->get(UserRepository::class);

        $this->configPath = static::getContainer()->get(GeneratedSecretsFile::class)->path();

        // Outside the transaction on purpose. Saving the public address nudges
        // the workers through the doctrine_dbal cache pool, which creates
        // cache_items on first write (see todo.md) — a DDL failure inside our
        // transaction would abort it and take the rest of the test with it.
        static::getContainer()->get(WorkerRestartSignal::class)->request();

        $this->connection = $entityManager->getConnection();
        $this->connection->beginTransaction();

        $this->emptyTheInstall();

        self::assertSame(0, $users->countAll(), 'the fixture is only valid on an empty install');

        // A signed-out visitor is sent to the login page, and from there here.
        $client->request('GET', '/login');
        self::assertResponseRedirects('/install');

        $crawler = $client->request('GET', '/install');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('install-submit')->form([
            'first_admin[nameFirst]'            => 'Ada',
            'first_admin[nameLast]'             => 'Lovelace',
            'first_admin[email]'                => 'ada@plmail.test',
            // https://localhost on purpose: plMail on a LAN has no top-level
            // domain, and this is the value the field prefills itself with.
            'first_admin[publicUrl]'            => 'https://localhost',
            'first_admin[plainPassword][first]' => 'correct-horse-battery-staple',
            'first_admin[plainPassword][second]' => 'correct-horse-battery-staple',
            'first_admin[locale]'               => 'de',
        ]));

        // Straight into the app: no second sign-in, which is the whole point of
        // logging them in programmatically.
        self::assertResponseRedirects('/');

        $entityManager->clear();
        $created = $users->findOneBy(['email' => 'ada@plmail.test']);

        self::assertNotNull($created);
        self::assertContains('ROLE_ADMIN', $created->getRoles(), 'the first user owns the install');
        self::assertNotSame('correct-horse-battery-staple', $created->getPassword(), 'the password must be hashed');
        // Chosen on the setup screen, and theirs from here on.
        self::assertSame('de', $created->locale);

        // The public address is asked for here because a worker building a push
        // subscription has no request to infer one from.
        self::assertSame(
            'https://localhost',
            static::getContainer()->get(GeneratedSecretsFile::class)->read()['APP_PUBLIC_URL'] ?? null,
        );

        // And the door closes behind them.
        $client->request('GET', '/install');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * The language selector reloads the page, so anything already typed has to
     * survive the round trip — otherwise correcting the language costs you the
     * form.
     */
    public function testSwitchingLanguageKeepsWhatWasAlreadyTyped(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->configPath = static::getContainer()->get(GeneratedSecretsFile::class)->path();
        static::getContainer()->get(WorkerRestartSignal::class)->request();

        $this->connection = $entityManager->getConnection();
        $this->connection->beginTransaction();

        $this->emptyTheInstall();

        $client->request('GET', '/install?_locale=de&first_admin%5BnameFirst%5D=Ada&first_admin%5Bemail%5D=ada%40plmail.test');

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('value="Ada"', $html);
        self::assertStringContainsString('value="ada@plmail.test"', $html);
        // And the page itself came back in the chosen language.
        self::assertStringContainsString('Konto anlegen', $html);
    }

    public function testAShortPasswordIsRejectedRatherThanCreatingTheAccount(): void
    {
        $client = static::createClient();
        // Without this the kernel is rebooted between requests, and the new
        // container's connection cannot see the uncommitted truncate.
        $client->disableReboot();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $users         = static::getContainer()->get(UserRepository::class);

        $this->configPath = static::getContainer()->get(GeneratedSecretsFile::class)->path();

        // Outside the transaction on purpose. Saving the public address nudges
        // the workers through the doctrine_dbal cache pool, which creates
        // cache_items on first write (see todo.md) — a DDL failure inside our
        // transaction would abort it and take the rest of the test with it.
        static::getContainer()->get(WorkerRestartSignal::class)->request();

        $this->connection = $entityManager->getConnection();
        $this->connection->beginTransaction();

        $this->emptyTheInstall();

        $crawler = $client->request('GET', '/install');

        $client->submit($crawler->selectButton('install-submit')->form([
            'first_admin[nameFirst]'             => 'Ada',
            'first_admin[nameLast]'              => 'Lovelace',
            'first_admin[email]'                 => 'ada@plmail.test',
            'first_admin[publicUrl]'             => 'https://mail.example.test',
            'first_admin[plainPassword][first]'  => 'short',
            'first_admin[plainPassword][second]' => 'short',
        ]));

        // 422, not 200: AbstractController::render() sets it automatically when
        // an invalid form is among the parameters.
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'an invalid form re-renders rather than redirecting');
        self::assertSame(0, $users->countAll());
    }

    /**
     * Empty every table, in one statement, so nothing has to know which
     * foreign keys point at a user. Enumerating tables by hand would go stale
     * the first time an entity is added — quietly, since a leftover row only
     * shows up as this test failing for an unrelated reason.
     *
     * TRUNCATE is transactional in Postgres, so this is undone by the rollback
     * in tearDown along with everything else.
     */
    private function emptyTheInstall(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $connection    = $entityManager->getConnection();

        $tables = array_filter(
            $connection->createSchemaManager()->listTableNames(),
            static fn (string $table): bool => 'doctrine_migration_versions' !== $table,
        );

        $connection->executeStatement(sprintf(
            'TRUNCATE TABLE %s CASCADE',
            // Already quoted where it matters: listTableNames() hands back
            // "user" with the quotes on, since it is a reserved word.
            implode(', ', $tables),
        ));

        $entityManager->clear();
    }
}
