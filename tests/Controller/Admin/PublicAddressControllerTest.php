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
        $this->config->remove(['APP_PUBLIC_URL', 'MAIL_PROBE_INTERVAL_SECONDS']);
        unset($_SERVER['MAIL_PROBE_INTERVAL_SECONDS'], $_ENV['MAIL_PROBE_INTERVAL_SECONDS']);

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

    public function testASavedProbeIntervalReachesTheFile(): void
    {
        $crawler = $this->client->request('GET', '/admin/address');

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('MAIL_PROBE_INTERVAL_SECONDS', $this->config->read());

        $this->client->submit($crawler->filter('form[name="probe_interval"]')->form([
            'probe_interval[seconds]' => '45',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame('45', $this->config->read()['MAIL_PROBE_INTERVAL_SECONDS'] ?? null);
        self::assertSelectorExists('turbo-frame#admin-address [data-probe-interval-saved]');
    }

    public function testAProbeIntervalOutsideTheBoundsIsRefusedRatherThanStored(): void
    {
        $crawler = $this->client->request('GET', '/admin/address');

        foreach (['5', '901', 'often'] as $bad) {
            $this->client->submit($crawler->filter('form[name="probe_interval"]')->form([
                'probe_interval[seconds]' => $bad,
            ]));

            // The form's own refusal (422, the form on the page again), not an
            // error page: if it let 901 through, save() would throw and
            // nothing would be written either, and only the status would say
            // which of the two had done the refusing.
            self::assertResponseStatusCodeSame(422, $bad);
            self::assertSelectorExists('form[name="probe_interval"]');
            self::assertArrayNotHasKey('MAIL_PROBE_INTERVAL_SECONDS', $this->config->read(), $bad);
        }

        // The presence companion: the same form does save an edge value, so the
        // refusals above are the bounds and not a form that saves nothing.
        $crawler = $this->client->request('GET', '/admin/address');
        $this->client->submit($crawler->filter('form[name="probe_interval"]')->form([
            'probe_interval[seconds]' => '15',
        ]));

        self::assertSame('15', $this->config->read()['MAIL_PROBE_INTERVAL_SECONDS'] ?? null);
    }

    public function testAnIntervalSetInTheEnvironmentIsShownAndCannotBeEditedHere(): void
    {
        $_SERVER['MAIL_PROBE_INTERVAL_SECONDS'] = '20';

        $this->client->request('GET', '/admin/address');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#admin-address [data-probe-interval-pinned]');
        self::assertSelectorNotExists('form[name="probe_interval"]');
        // The other form on the page is untouched by it.
        self::assertSelectorExists('form[name="public_url"]');
    }

    public function testASavedIntervalExportedBackIntoTheEnvironmentStillOffersTheForm(): void
    {
        // What the entrypoint does at the next start with a value this page
        // saved: it comes back through $_SERVER. The page must still let an
        // administrator change it.
        $this->config->set('MAIL_PROBE_INTERVAL_SECONDS', '15');
        $_SERVER['MAIL_PROBE_INTERVAL_SECONDS'] = '15';

        $this->client->request('GET', '/admin/address');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('turbo-frame#admin-address [data-probe-interval-pinned]');
        self::assertSelectorExists('form[name="probe_interval"]');
    }

    public function testAnIntervalSetInTheEnvironmentIsNotOverwrittenByAPost(): void
    {
        // The page offers no field while the environment pins the value, but a
        // POST does not need the field: a stale tab, or a script. Taken from the
        // page while nothing is pinned, so the token is a genuine one and the
        // refusal is the pin's and not a bad token's.
        $crawler = $this->client->request('GET', '/admin/address');
        $token   = $crawler->filter('form[name="probe_interval"]')->form()->get('probe_interval[_token]')->getValue();

        $_SERVER['MAIL_PROBE_INTERVAL_SECONDS'] = '20';

        $this->client->request('POST', '/admin/address', [
            'probe_interval' => ['seconds' => '45', '_token' => $token],
        ]);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('MAIL_PROBE_INTERVAL_SECONDS', $this->config->read());
        self::assertSelectorExists('turbo-frame#admin-address [data-probe-interval-pinned]');

        // The presence companion: the same request, unpinned, is what saves.
        unset($_SERVER['MAIL_PROBE_INTERVAL_SECONDS']);

        $this->client->request('POST', '/admin/address', [
            'probe_interval' => ['seconds' => '45', '_token' => $token],
        ]);

        self::assertSame('45', $this->config->read()['MAIL_PROBE_INTERVAL_SECONDS'] ?? null);
    }

    public function testTheSectionIsReachableFromTheAdminNavigation(): void
    {
        $crawler = $this->client->request('GET', '/admin?section=address');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#admin-address[src="/admin/address"]'));
    }
}
