<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\Integration;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\User\UserRepository;
use App\Service\Calendar\Sync\CalDav\CalDavClient;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Admin → Integrations lists the calendar services, and their config can be
 * changed after setup (#212).
 *
 * What is pinned here is that an edit takes effect where it is read — the
 * address CalDavClient resolves for a connection, not just the row — that
 * clearing it hands each user their own address back, that the switch the
 * calendar side never reads is not offered and not overwritten, and that the
 * form is as closed as the setup wizard's: admin only, same-origin only, http
 * or https only, nothing but its own fields.
 *
 * Skips itself without the seeded users, exactly as PageRendersTest does.
 */
final class IntegrationCalendarConfigTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string USER_EMAIL  = 'e2e@plmail.test';

    /** A public address as an IP literal, so resolving it needs no DNS. */
    private const string OWN_ADDRESS = 'https://1.1.1.1/dav';

    private KernelBrowser $client;
    private Connection $connection;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Several requests per test, all inside the transaction below; a
        // rebooted kernel would be a new connection outside it.
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->connection = $container->get(Connection::class);

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $admin instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->admin = $admin;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheCalendarGroupListsCalDavAndTheFeedButNotNextcloud(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/integrations');

        self::assertResponseIsSuccessful();

        $group = $crawler->filter('#admin-calendar-providers');
        self::assertCount(1, $group->filter('#integration-provider-caldav'));
        self::assertCount(1, $group->filter('#integration-provider-ics'));
        self::assertCount(0, $group->filter('#integration-provider-nextcloud'), 'Nextcloud is a file service whose calendars come with it');

        // Once each on the whole page: Nextcloud stays with the file services,
        // and the calendar services are not listed among them.
        self::assertCount(1, $crawler->filter('#integration-provider-nextcloud'));
        self::assertCount(1, $crawler->filter('#integration-provider-caldav'));
        self::assertCount(1, $crawler->filter('#integration-provider-ics'));

        self::assertStringContainsString('Offered to everyone', $group->filter('#integration-provider-caldav summary')->text());
    }

    /**
     * The replacement proven where it is read: CalDavClient resolves the
     * address for every request, so this is what the next discovery uses.
     */
    public function testAnEditedAddressIsTheOneCalDavUsesAndClearingItHandsBackTheUsersOwn(): void
    {
        $this->client->loginUser($this->admin);

        $connection          = new Integration($this->admin, Provider::CalDav, 'Own server');
        $connection->baseUrl = self::OWN_ADDRESS;
        $caldav              = static::getContainer()->get(CalDavClient::class);

        $this->submitCalDav(['baseUrl' => 'https://dav.example.com/']);

        self::assertSame('https://dav.example.com', $this->config(Provider::CalDav)?->baseUrl);
        self::assertSame('https://dav.example.com', $caldav->baseUrl($connection));

        $this->submitCalDav(['baseUrl' => '']);

        self::assertNull($this->config(Provider::CalDav)?->baseUrl);
        self::assertSame(self::OWN_ADDRESS, $caldav->baseUrl($connection));
    }

    /**
     * Settings → Calendars never reads isEnabled for CalDAV, so the form does
     * not offer it, and a save leaves what the setup wizard stored alone.
     */
    public function testTheCalendarFormHasNoOfferSwitchAndKeepsTheStoredValue(): void
    {
        $stored            = new IntegrationProviderConfig(Provider::CalDav);
        $stored->isEnabled = true;
        $this->em()->persist($stored);
        $this->em()->flush();

        $this->client->loginUser($this->admin);
        $form = $this->calDavForm();

        self::assertFalse($form->has('integration_provider_config[isEnabled]'));

        $form['integration_provider_config[baseUrl]']->setValue('https://dav.example.com');
        $this->client->submit($form);

        self::assertTrue($this->config(Provider::CalDav)?->isEnabled);
        self::assertSame('https://dav.example.com', $this->config(Provider::CalDav)?->baseUrl);

        // The file services keep theirs.
        $nextcloud = $this->client->request('GET', '/admin/integrations/nextcloud/edit')->filter('form')->form();
        self::assertTrue($nextcloud->has('integration_provider_config[isEnabled]'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedAddresses(): iterable
    {
        yield 'ftp' => ['ftp://dav.example.com'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'longer than the column' => ['https://dav.example.com/' . str_repeat('a', 600)];
    }

    #[DataProvider('refusedAddresses')]
    public function testAnAddressPlMailCouldNotFetchIsRefused(string $address): void
    {
        $this->client->loginUser($this->admin);
        $this->submitCalDav(['baseUrl' => $address]);

        self::assertLessThan(500, $this->client->getResponse()->getStatusCode(), 'refused by the form, not by the database');
        self::assertNull($this->config(Provider::CalDav)?->baseUrl);
    }

    /**
     * Fields the CalDAV form does not have are not written, whatever is
     * posted: no client credentials on an app-password service, and no switch.
     */
    public function testFieldsTheFormDoesNotHaveAreRefused(): void
    {
        $this->client->loginUser($this->admin);

        $form   = $this->calDavForm();
        $values = $form->getPhpValues();

        $values['integration_provider_config']['baseUrl']   = 'https://dav.example.com';
        $values['integration_provider_config']['clientId']  = 'smuggled';
        $values['integration_provider_config']['isEnabled'] = '1';

        $this->client->request($form->getMethod(), $form->getUri(), $values);

        self::assertNull($this->config(Provider::CalDav), 'an extra field makes the whole submission invalid');

        // The same request without them is saved, so the refusal above was
        // about the fields and not about how the request was made.
        unset($values['integration_provider_config']['clientId'], $values['integration_provider_config']['isEnabled']);
        $this->client->request($form->getMethod(), $form->getUri(), $values);

        self::assertSame('https://dav.example.com', $this->config(Provider::CalDav)?->baseUrl);
        self::assertNull($this->config(Provider::CalDav)?->clientId);
    }

    public function testANonAdminCanNeitherSeeNorChangeTheCalendarConfig(): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::USER_EMAIL]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user` first');
        }

        $this->client->loginUser($user);

        $this->client->request('GET', '/admin/integrations');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/integrations/caldav/edit');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/integrations/caldav/edit', [
            'integration_provider_config' => ['baseUrl' => 'https://dav.example.com', '_token' => 'csrf-token'],
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->config(Provider::CalDav));
    }

    public function testACrossSitePostDoesNotWrite(): void
    {
        $this->client->loginUser($this->admin);

        $form   = $this->calDavForm();
        $values = $form->getPhpValues();
        $values['integration_provider_config']['baseUrl'] = 'https://dav.example.com';

        $this->client->request($form->getMethod(), $form->getUri(), $values, [], [
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
            'HTTP_ORIGIN'         => 'https://attacker.example',
            'HTTP_REFERER'        => 'https://attacker.example/',
        ]);

        self::assertNull($this->config(Provider::CalDav));

        // Control: the same values from the page itself are saved.
        $this->client->request($form->getMethod(), $form->getUri(), $values, [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertSame('https://dav.example.com', $this->config(Provider::CalDav)?->baseUrl);
    }

    /**
     * The list escapes a stored address like anything else. Written straight
     * to the row, as a row from before the form checked addresses could be.
     */
    public function testAStoredAddressIsEscapedOnTheList(): void
    {
        $stored          = new IntegrationProviderConfig(Provider::CalDav);
        $stored->baseUrl = 'https://dav.example.com/?q="><script>alert(1)</script>';
        $this->em()->persist($stored);
        $this->em()->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/integrations');

        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    /**
     * The rows moved into a partial for the calendar group; the file services'
     * secrets must still never reach the page.
     */
    public function testAStoredClientSecretIsNeverRendered(): void
    {
        $stored               = new IntegrationProviderConfig(Provider::GoogleDrive);
        $stored->clientId     = 'drive-client';
        $stored->clientSecret = 'drive-secret-value';
        $this->em()->persist($stored);
        $this->em()->flush();

        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/admin/integrations');
        self::assertStringNotContainsString('drive-secret-value', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/admin/integrations/googleDrive/edit');
        self::assertStringNotContainsString('drive-secret-value', (string) $this->client->getResponse()->getContent());
    }

    /**
     * A pin reaches every CalDAV connection already made, so the form says how
     * many there are before it is changed. Counted through the same query as
     * the row, because the seed may have connections of its own.
     */
    public function testTheCalDavFormSaysHowManyConnectionsAChangeReaches(): void
    {
        $connection          = new Integration($this->admin, Provider::CalDav, 'Own server');
        $connection->baseUrl = self::OWN_ADDRESS;
        $this->em()->persist($connection);
        $this->em()->flush();

        $count = static::getContainer()->get(IntegrationRepository::class)->countUsersByProvider()[Provider::CalDav->value];

        $this->client->loginUser($this->admin);
        $warning = $this->client->request('GET', '/admin/integrations/caldav/edit')->filter('[data-caldav-connected-warning]');

        self::assertCount(1, $warning);
        self::assertStringContainsString(sprintf('Connections already made: %d.', $count), $warning->text());

        // The file services' pins are not this warning's business.
        $nextcloud = $this->client->request('GET', '/admin/integrations/nextcloud/edit');
        self::assertCount(0, $nextcloud->filter('[data-caldav-connected-warning]'));
    }

    /**
     * A feed has no server to pin, and an address stored for it only exempts
     * its host from the private-network check. Without one there is nothing to
     * configure: no address line, no Configure button, no field.
     */
    public function testTheFeedOffersNothingToConfigureWithoutAStoredAddress(): void
    {
        $this->client->loginUser($this->admin);
        $row = $this->client->request('GET', '/admin/integrations')->filter('#integration-provider-ics');

        self::assertCount(0, $row->filter('dl'));
        self::assertCount(0, $row->filter('[data-ui--modal-src-value]'));

        $form = $this->client->request('GET', '/admin/integrations/ics/edit')->filter('form')->form();
        self::assertFalse($form->has('integration_provider_config[baseUrl]'));
    }

    /** One that is stored is shown for what it does, and can be cleared. */
    public function testAStoredFeedAddressIsShownAndCanBeCleared(): void
    {
        $stored          = new IntegrationProviderConfig(Provider::Ics);
        $stored->baseUrl = 'http://calendar.internal';
        $this->em()->persist($stored);
        $this->em()->flush();

        $this->client->loginUser($this->admin);
        $row = $this->client->request('GET', '/admin/integrations')->filter('#integration-provider-ics');

        self::assertStringContainsString('http://calendar.internal', $row->filter('dl')->text());
        self::assertStringContainsString('past the private-network check', $row->filter('dl')->text());
        self::assertCount(1, $row->filter('[data-ui--modal-src-value]'));

        $form = $this->client->request('GET', '/admin/integrations/ics/edit')->filter('form')->form();
        $form['integration_provider_config[baseUrl]']->setValue('');
        $this->client->submit($form);

        self::assertNull($this->config(Provider::Ics)?->baseUrl);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function calDavForm(): \Symfony\Component\DomCrawler\Form
    {
        return $this->client->request('GET', '/admin/integrations/caldav/edit')->filter('form')->form();
    }

    /**
     * @param array<string, string> $values
     */
    private function submitCalDav(array $values): Crawler
    {
        $form = $this->calDavForm();

        foreach ($values as $name => $value) {
            $form[sprintf('integration_provider_config[%s]', $name)]->setValue($value);
        }

        return $this->client->submit($form);
    }

    /**
     * Read fresh from the database every time, so PHPStan must not narrow
     * one call's answer onto the next.
     *
     * @phpstan-impure
     */
    private function config(Provider $provider): ?IntegrationProviderConfig
    {
        $this->em()->clear();

        return static::getContainer()->get(IntegrationProviderConfigRepository::class)->findOneByProvider($provider);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
