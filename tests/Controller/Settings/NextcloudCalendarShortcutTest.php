<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\User\UserRepository;
use App\Service\Calendar\Subscription\NextcloudPreset;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * "Connect my Nextcloud" is the CalDAV connect form with the address decided,
 * and nothing else.
 *
 * Three claims, and the third is the one this exists for:
 *
 *  - Without NEXTCLOUD_URL there is no shortcut, not even by typing its URL.
 *  - With it, the shortcut connects to THAT server, through the same
 *    RFC 6764 discovery a typed address gets — `.well-known/caldav`, the
 *    principal, the home, the list — and makes an ordinary CalDAV connection.
 *  - The manual form behaves identically whether NEXTCLOUD_URL is set or not,
 *    including for a different Nextcloud. A shortcut that changed what the
 *    manual path does would be a second feature disguised as the first.
 *
 * Driven through the real controller, form and container rather than against
 * CalDavConnector, because what is being pinned is that both buttons reach
 * the same services: a test of the services alone would pass against a
 * controller that had grown a Nextcloud branch of its own. The only thing
 * replaced is the outbound HTTP client, with a scripted Nextcloud on hosts
 * under `.test` (RFC 2606), which never resolve — so the SSRF guard, which
 * still runs on every URL, has nothing private to refuse and nothing real is
 * ever contacted.
 */
final class NextcloudCalendarShortcutTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string INSTANCE = 'https://cloud.nextcloud.test';
    private const string OTHER = 'https://other.nextcloud.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    /** @var list<array{method: string, url: string, authorised: bool}> */
    private array $requests = [];

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── (a) No NEXTCLOUD_URL, no shortcut ─────────────────────────────────────

    public function testTheShortcutIsNotOfferedWithoutNextcloudUrl(): void
    {
        $this->boot(null);

        $crawler = $this->client->request('GET', '/settings?section=calendars');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-connect-nextcloud]'), 'the shortcut was offered with no NEXTCLOUD_URL');
        self::assertCount(
            1,
            $crawler->filter('[data-ui--modal-src-value="/settings/calendars/caldav/connect"]'),
            'the manual CalDAV button is gone',
        );
    }

    public function testTheShortcutCannotBeReachedByHandWithoutNextcloudUrl(): void
    {
        $this->boot(null);

        $this->client->request('GET', '/settings/calendars/caldav/connect?preset=nextcloud');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheShortcutIsOfferedBesideTheManualButtonWhenConfigured(): void
    {
        $this->boot(self::INSTANCE);

        $crawler = $this->client->request('GET', '/settings?section=calendars');

        self::assertResponseIsSuccessful();
        self::assertSame(
            '/settings/calendars/caldav/connect?preset=nextcloud',
            $crawler->filter('[data-connect-nextcloud]')->attr('data-ui--modal-src-value'),
        );
        self::assertCount(1, $crawler->filter('[data-ui--modal-src-value="/settings/calendars/caldav/connect"]'));
    }

    // ── (b) The shortcut discovers the configured server ──────────────────────

    public function testTheShortcutConnectsTheConfiguredServerThroughCalDavDiscovery(): void
    {
        $this->boot(self::INSTANCE);

        $crawler = $this->client->request('GET', '/settings/calendars/caldav/connect?preset=nextcloud');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[name="cal_dav_connect[baseUrl]"]'), 'the address must not be a field');
        self::assertStringContainsString(self::INSTANCE, $crawler->filter('[data-nextcloud-address]')->text());

        $form = $crawler->filter('form[name="cal_dav_connect"]')->form([
            'cal_dav_connect[username]' => 'alice',
            'cal_dav_connect[secret]'   => 'app-password-1',
        ]);

        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSame($this->calendarsOf(self::INSTANCE), $this->listedCalendars($crawler));

        $integration = $this->onlyNewIntegration();
        self::assertSame(Provider::CalDav, $integration->provider, 'a Nextcloud connection is a CalDAV one');
        self::assertSame(self::INSTANCE, $integration->baseUrl);
        self::assertSame(NextcloudPreset::NAME, $integration->name);
        self::assertSame('alice', $integration->username);
        self::assertSame('app-password-1', $integration->secret);
        self::assertTrue($integration->isHealthy());

        // The RFC 6764 bootstrap, on the administrator's server and nowhere
        // else, with the person's credential on every request.
        self::assertSame(['PROPFIND', self::INSTANCE . '/.well-known/caldav'], [$this->requests[0]['method'], $this->requests[0]['url']]);
        self::assertSame([parse_url(self::INSTANCE, PHP_URL_HOST)], $this->hostsContacted());
        self::assertNotContains(false, array_column($this->requests, 'authorised'));
    }

    /**
     * The address is not a field on the shortcut, and posting one anyway is
     * refused rather than quietly used — the whole point of fixing it is that
     * the browser cannot choose where the app password goes.
     */
    public function testTheShortcutRefusesAnAddressPostedAnyway(): void
    {
        $this->boot(self::INSTANCE);

        $crawler = $this->client->request('GET', '/settings/calendars/caldav/connect?preset=nextcloud');
        $values  = $crawler->filter('form[name="cal_dav_connect"]')->form()->getPhpValues();

        $values['cal_dav_connect']['username'] = 'alice';
        $values['cal_dav_connect']['secret']   = 'app-password-1';
        $values['cal_dav_connect']['baseUrl']  = 'https://elsewhere.test';

        $this->client->request('POST', '/settings/calendars/caldav/connect?preset=nextcloud', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->requests, 'nothing may be contacted');
        self::assertSame([], $this->newIntegrations());
    }

    // ── (c) The manual form, with and without NEXTCLOUD_URL ───────────────────

    /** @return iterable<string, array{?string}> */
    public static function instanceUrls(): iterable
    {
        yield 'without NEXTCLOUD_URL' => [null];
        yield 'with NEXTCLOUD_URL'    => [self::INSTANCE];
    }

    /**
     * Somebody's own Nextcloud, typed as its origin with no path, as before
     * this shortcut existed — and, when the install has a Nextcloud of its own,
     * still reaching the one that was typed rather than the install's.
     */
    #[DataProvider('instanceUrls')]
    public function testTheManualFormConnectsWhateverServerWasTyped(?string $instance): void
    {
        $this->boot($instance);

        $crawler = $this->client->request('GET', '/settings/calendars/caldav/connect');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[name="cal_dav_connect[baseUrl]"]'), 'the manual form lost its address field');
        self::assertCount(0, $crawler->filter('[data-nextcloud-address]'));

        $form = $crawler->filter('form[name="cal_dav_connect"]')->form([
            'cal_dav_connect[baseUrl]'  => self::OTHER,
            'cal_dav_connect[name]'     => 'My own',
            'cal_dav_connect[username]' => 'alice',
            'cal_dav_connect[secret]'   => 'app-password-2',
        ]);

        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSame($this->calendarsOf(self::OTHER), $this->listedCalendars($crawler));

        $integration = $this->onlyNewIntegration();
        self::assertSame(Provider::CalDav, $integration->provider);
        self::assertSame(self::OTHER, $integration->baseUrl);
        self::assertSame('My own', $integration->name);
        self::assertSame('app-password-2', $integration->secret);

        self::assertSame(['PROPFIND', self::OTHER . '/.well-known/caldav'], [$this->requests[0]['method'], $this->requests[0]['url']]);
        self::assertSame([parse_url(self::OTHER, PHP_URL_HOST)], $this->hostsContacted());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * Sign in, and put NEXTCLOUD_URL and the scripted server in place before
     * the first request builds anything that holds either.
     */
    private function boot(?string $instance): void
    {
        $this->client = static::createClient();
        $container    = static::getContainer();

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $container->set(NextcloudPreset::class, new NextcloudPreset($instance));
        $container->set('app.http_client.integration', new MockHttpClient($this->nextcloud(...)));

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->client->loginUser($user);
        $this->client->disableReboot();
        $this->connection->beginTransaction();

        $this->user = $this->em->find(User::class, $user->id);
    }

    /**
     * A Nextcloud as plMail meets it: `.well-known/caldav` redirects to
     * `/remote.php/dav/`, which names the principal, which names the calendar
     * home, which holds two event calendars and one task list. Answers on any
     * host, so the same script stands in for the install's server and for
     * somebody else's; which one was asked is what the tests check.
     *
     * @param array<string, mixed> $options
     */
    private function nextcloud(string $method, string $url, array $options): MockResponse
    {
        $headers = $options['normalized_headers'] ?? [];

        $this->requests[] = [
            'method'     => $method,
            'url'        => $url,
            'authorised' => is_array($headers) && isset($headers['authorization']),
        ];

        $parts  = parse_url($url);
        $origin = $parts['scheme'] . '://' . $parts['host'];

        return match ($parts['path'] ?? '/') {
            '/.well-known/caldav' => new MockResponse('', [
                'http_code'        => 301,
                'response_headers' => ['location' => $origin . '/remote.php/dav/'],
            ]),
            '/remote.php/dav/' => $this->multistatus(<<<'XML'
                <d:response>
                  <d:href>/remote.php/dav/</d:href>
                  <d:propstat>
                    <d:prop>
                      <d:resourcetype><d:collection/></d:resourcetype>
                      <d:current-user-principal><d:href>/remote.php/dav/principals/users/alice/</d:href></d:current-user-principal>
                    </d:prop>
                    <d:status>HTTP/1.1 200 OK</d:status>
                  </d:propstat>
                </d:response>
                XML),
            '/remote.php/dav/principals/users/alice/' => $this->multistatus(<<<'XML'
                <d:response>
                  <d:href>/remote.php/dav/principals/users/alice/</d:href>
                  <d:propstat>
                    <d:prop>
                      <d:resourcetype><d:principal/></d:resourcetype>
                      <cal:calendar-home-set><d:href>/remote.php/dav/calendars/alice/</d:href></cal:calendar-home-set>
                    </d:prop>
                    <d:status>HTTP/1.1 200 OK</d:status>
                  </d:propstat>
                </d:response>
                XML),
            '/remote.php/dav/calendars/alice/' => $this->multistatus(
                $this->collection('/remote.php/dav/calendars/alice/', '<d:collection/>', '')
                . $this->collection('/remote.php/dav/calendars/alice/personal/', '<d:collection/><cal:calendar/>', 'Personal', 'VEVENT')
                . $this->collection('/remote.php/dav/calendars/alice/work/', '<d:collection/><cal:calendar/>', 'Work', 'VEVENT')
                . $this->collection('/remote.php/dav/calendars/alice/tasks/', '<d:collection/><cal:calendar/>', 'Tasks', 'VTODO'),
            ),
            default => new MockResponse('', ['http_code' => 404]),
        };
    }

    private function collection(string $href, string $type, string $name, ?string $component = null): string
    {
        $components = null === $component
            ? ''
            : sprintf('<cal:supported-calendar-component-set><cal:comp name="%s"/></cal:supported-calendar-component-set>', $component);

        return sprintf(<<<'XML'
            <d:response>
              <d:href>%s</d:href>
              <d:propstat>
                <d:prop>
                  <d:resourcetype>%s</d:resourcetype>
                  <d:displayname>%s</d:displayname>
                  %s
                </d:prop>
                <d:status>HTTP/1.1 200 OK</d:status>
              </d:propstat>
            </d:response>
            XML, $href, $type, $name, $components);
    }

    private function multistatus(string $responses): MockResponse
    {
        return new MockResponse(
            '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">' . $responses . '</d:multistatus>',
            ['http_code' => 207, 'response_headers' => ['content-type' => 'application/xml; charset=utf-8']],
        );
    }

    /**
     * The calendars the subscribe list offers, by remote id — the task list
     * is not among them, because the driver lists event calendars only.
     *
     * @return list<string>
     */
    private function listedCalendars(Crawler $crawler): array
    {
        $ids = $crawler->filter('input[name="remoteIds[]"]')->each(
            static fn (Crawler $input): string => (string) $input->attr('value'),
        );

        sort($ids);

        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    private function calendarsOf(string $origin): array
    {
        return [
            $origin . '/remote.php/dav/calendars/alice/personal/',
            $origin . '/remote.php/dav/calendars/alice/work/',
        ];
    }

    /** @return list<string> */
    private function hostsContacted(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $request): string => (string) parse_url($request['url'], PHP_URL_HOST),
            $this->requests,
        )));
    }

    /** @return list<Integration> */
    private function newIntegrations(): array
    {
        $this->em->clear();

        return array_values(array_filter(
            static::getContainer()->get(IntegrationRepository::class)->findBy(['usr' => $this->user->id]),
            static fn (Integration $integration): bool => null !== $integration->baseUrl
                && str_ends_with((string) parse_url($integration->baseUrl, PHP_URL_HOST), '.nextcloud.test'),
        ));
    }

    private function onlyNewIntegration(): Integration
    {
        $integrations = $this->newIntegrations();

        self::assertCount(1, $integrations, 'exactly one connection should have been made');

        return $integrations[0];
    }
}
