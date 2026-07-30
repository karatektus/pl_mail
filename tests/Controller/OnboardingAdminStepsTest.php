<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The two admin steps of the setup wizard render.
 *
 * They cannot go in PageRendersTest: both answer 404 once credentials are on
 * file, which is the whole point of them, and whether the test database has any
 * depends on what the e2e suite last did. So this empties the two config tables
 * inside a transaction and rolls back — the same trick InstallEmptyInstallTest
 * uses, for the same reason.
 *
 * Worth its own file because the gap is real: these steps only render on an
 * install that has nothing configured, which is exactly the install nobody
 * tests on. A missing enum method got all the way to a running instance that
 * way.
 */
final class OnboardingAdminStepsTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private ?Connection $connection = null;

    protected function tearDown(): void
    {
        if (null !== $this->connection && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->connection = null;

        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function adminSteps(): iterable
    {
        yield 'mail credentials' => ['/onboarding/admin-mail'];
        yield 'mail credentials, microsoft' => ['/onboarding/admin-mail?provider=microsoft'];
        yield 'integration credentials' => ['/onboarding/admin-integrations'];
        yield 'integration credentials, dropbox' => ['/onboarding/admin-integrations?provider=dropbox'];
    }

    /**
     * Half a registration is worse than none: it looks configured and fails at
     * the consent screen. Switching provider posts the step, so this is also
     * what stops a click on the other provider silently storing the fragment.
     */
    public function testAClientIdWithNoSecretIsRefused(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user          = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->connection = $entityManager->getConnection();
        $this->connection->beginTransaction();
        $this->connection->executeStatement('TRUNCATE TABLE mail_provider_config CASCADE');
        $entityManager->clear();

        $client->loginUser($user);
        $client->request('POST', '/onboarding/admin-mail?provider=google', [
            'mail_provider_config' => [
                'clientId'     => 'an-id-with-no-secret',
                'clientSecret' => '',
            ],
            'switch_to' => 'microsoft',
        ]);

        self::assertSame(422, $client->getResponse()->getStatusCode(), 'the form must be rejected, not saved');
        self::assertStringContainsString(
            'onboarding-provider-google',
            (string) $client->getResponse()->getContent(),
            'and it must stay on the provider being edited rather than switching away',
        );

        $entityManager->clear();

        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT count(*) FROM mail_provider_config'),
            'nothing half-finished may be stored',
        );
    }

    #[DataProvider('adminSteps')]
    public function testTheStepRendersOnAnUnconfiguredInstall(string $path): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user          = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->connection = $entityManager->getConnection();
        $this->connection->beginTransaction();

        foreach (['mail_provider_config', 'integration_provider_config'] as $table) {
            $this->connection->executeStatement(sprintf('TRUNCATE TABLE %s CASCADE', $table));
        }

        $entityManager->clear();

        $client->loginUser($user);
        $client->request('GET', $path);

        self::assertSame(200, $client->getResponse()->getStatusCode(), $path);
    }
}
