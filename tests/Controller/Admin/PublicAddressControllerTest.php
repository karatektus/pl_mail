<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User\User;
use App\Infrastructure\Setup\GeneratedSecretsFile;
use App\Repository\User\UserRepository;
use App\Service\Monitoring\WorkerRestartSignal;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The public address can be changed after setup, and a change is written to the
 * file rather than only acknowledged.
 *
 * Setup asked for it once and nothing could answer it again, so an install
 * restored from another machine's backup kept sending browsers to that
 * machine's address for live updates. The claim worth pinning is the write: a
 * form that re-renders with the new value in its field and a green notice looks
 * finished whether or not anything reached the file the next start reads.
 */
final class PublicAddressControllerTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private Connection $connection;
    private GeneratedSecretsFile $config;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $container        = static::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->config     = $container->get(GeneratedSecretsFile::class);

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $admin instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->client->loginUser($admin);

        // Outside the transaction, for the reason InstallEmptyInstallTest
        // gives: saving nudges the workers through a cache pool that creates
        // its table on first write.
        $container->get(WorkerRestartSignal::class)->request();

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        // The file is shared by the whole suite, and an address left in it
        // would be the public URL of every test that runs afterwards.
        $this->config->remove(['APP_PUBLIC_URL']);

        parent::tearDown();
    }

    public function testASavedAddressReachesTheFile(): void
    {
        $crawler = $this->client->request('GET', '/admin/address');

        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="public_url"]')->form([
            'public_url[publicUrl]' => 'https://mail.example.test/',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame('https://mail.example.test', $this->config->read()['APP_PUBLIC_URL'] ?? null);
        self::assertSelectorExists('turbo-frame#admin-address [role="status"]');

        // Offered straight away, not only once the two addresses are seen to
        // differ: the hub address browsers are handed is fixed when the kernel
        // boots, so a save is never complete without it.
        self::assertSelectorExists('turbo-frame#admin-address form[action="/admin/system/restart-app"]');
    }

    public function testSomethingThatIsNotAnAddressIsRefusedRatherThanStored(): void
    {
        $crawler = $this->client->request('GET', '/admin/address');

        $this->client->submit($crawler->filter('form[name="public_url"]')->form([
            'public_url[publicUrl]' => 'not an address',
        ]));

        self::assertArrayNotHasKey('APP_PUBLIC_URL', $this->config->read());
    }

    public function testTheSectionIsReachableFromTheAdminNavigation(): void
    {
        $crawler = $this->client->request('GET', '/admin?section=address');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#admin-address[src="/admin/address"]'));
    }
}
