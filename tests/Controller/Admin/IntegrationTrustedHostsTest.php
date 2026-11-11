<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\TrustedHost;
use App\Entity\User\User;
use App\Repository\Integration\TrustedHost\TrustedHostRepository;
use App\Repository\User\User\UserRepository;
use App\Service\Integration\IntegrationUrlValidator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Admin → Integrations → Servers on a private network (#564).
 *
 * The servers plMail may reach although they resolve to a private address
 * used to be INTEGRATIONS_ALLOWED_HOSTS, which a Docker Compose install could
 * not set: the reporter's Baïkal stayed refused whatever they put in `.env`.
 * What is pinned here is that the admin page is now the whole of the answer —
 * a host allowed there is reachable at once, through the same validator every
 * driver asks, and stops being reachable the moment it is removed; plain http
 * is a separate tick per host; and the page is as closed as the rest of the
 * admin area.
 *
 * Skips itself without the seeded users, exactly as PageRendersTest does.
 */
final class IntegrationTrustedHostsTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string USER_EMAIL  = 'e2e@plmail.test';

    /** An address in RFC 1918 space as a literal, so no DNS is needed. */
    private const string LAN_ADDRESS = 'https://10.20.30.40:8443/dav.php';

    private KernelBrowser $client;
    private Connection $connection;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Several requests per test, all inside the transaction below.
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

    /**
     * The whole fix, end to end: refused, allowed on the page, reachable,
     * removed on the page, refused again — through the validator every
     * driver and CalDavClient call, not by reading the row.
     */
    public function testAHostAllowedOnThePageIsReachableAndStopsBeingSoWhenRemoved(): void
    {
        $validator = static::getContainer()->get(IntegrationUrlValidator::class);

        self::assertFalse($this->reachable($validator, self::LAN_ADDRESS), 'refused before anything is allowed');

        $this->client->loginUser($this->admin);
        // The whole address the user's error was about, pasted as it is.
        $this->allow(self::LAN_ADDRESS);

        self::assertResponseIsSuccessful();
        self::assertSame(['10.20.30.40'], $this->hosts());
        self::assertTrue($this->reachable($validator, self::LAN_ADDRESS));

        $crawler = $this->client->request('GET', '/admin/integrations');
        self::assertStringContainsString('10.20.30.40', $crawler->filter('#admin-trusted-hosts')->text());

        $row = $crawler->filter(sprintf('#trusted-host-%d', $this->row('10.20.30.40')->id));
        $this->client->submit($row->filter('form')->last()->form());

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->hosts());
        self::assertFalse($this->reachable($validator, self::LAN_ADDRESS));
    }

    public function testPlainHttpIsItsOwnTickAndCanBeTurnedOffAgain(): void
    {
        $validator = static::getContainer()->get(IntegrationUrlValidator::class);
        $http      = 'http://10.20.30.40:8080/dav.php';

        $this->client->loginUser($this->admin);
        $this->allow('10.20.30.40');

        self::assertTrue($this->reachable($validator, self::LAN_ADDRESS));
        self::assertFalse($this->reachable($validator, $http), 'allowed on the network is not allowed in the clear');

        // The row's first form toggles plain http.
        $crawler = $this->client->request('GET', '/admin/integrations');
        $this->client->submit($crawler->filter(sprintf('#trusted-host-%d form', $this->row('10.20.30.40')->id))->first()->form());

        self::assertTrue($this->row('10.20.30.40')->allowHttp);
        self::assertTrue($this->reachable($validator, $http));

        $crawler = $this->client->request('GET', '/admin/integrations');
        $this->client->submit($crawler->filter(sprintf('#trusted-host-%d form', $this->row('10.20.30.40')->id))->first()->form());

        self::assertFalse($this->row('10.20.30.40')->allowHttp);
        self::assertFalse($this->reachable($validator, $http));
    }

    /**
     * Allowing a host already listed corrects its tick rather than failing
     * on the unique constraint or adding a second row.
     */
    public function testAllowingAListedHostAgainUpdatesItsHttpTick(): void
    {
        $this->client->loginUser($this->admin);

        $this->allow('NAS.lan');
        $this->allow('nas.lan', allowHttp: true);

        self::assertSame(['nas.lan'], $this->hosts());
        self::assertTrue($this->row('nas.lan')->allowHttp);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAHost(): iterable
    {
        yield 'empty'              => [''];
        // A wildcard would let every user reach every machine on the LAN.
        yield 'wildcard'           => ['*.lan'];
        yield 'spaces'             => ['my nas'];
        yield 'an impossible quad' => ['300.1.1.1'];
        yield 'a scheme alone'     => ['https://'];
    }

    #[DataProvider('notAHost')]
    public function testTextThatNamesNoHostIsRefusedWithTheFormShownAgain(string $typed): void
    {
        $this->client->loginUser($this->admin);
        $this->allow($typed);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->hosts());
    }

    public function testANonAdminCanNeitherSeeNorChangeTheList(): void
    {
        $listed = new TrustedHost('10.20.30.40');
        $this->em()->persist($listed);
        $this->em()->flush();

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::USER_EMAIL]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user` first');
        }

        $this->client->loginUser($user);

        $this->client->request('GET', '/admin/integrations/trusted-hosts/new');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/integrations/trusted-hosts/new', [
            'trusted_host' => ['host' => '10.0.0.9', '_token' => 'csrf-token'],
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/admin/integrations/trusted-hosts/%d/remove', $listed->id), [
            '_token' => 'csrf-token',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(['10.20.30.40'], $this->hosts());
    }

    /**
     * Removing is a POST that has to carry the row's own token; a request
     * that only knows the id removes nothing.
     */
    public function testARemovalWithoutTheRowsTokenRemovesNothing(): void
    {
        $listed = new TrustedHost('10.20.30.40');
        $this->em()->persist($listed);
        $this->em()->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('POST', sprintf('/admin/integrations/trusted-hosts/%d/remove', $listed->id), [
            '_token' => 'not-the-token',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['10.20.30.40'], $this->hosts());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function allow(string $host, bool $allowHttp = false): void
    {
        $form = $this->client->request('GET', '/admin/integrations/trusted-hosts/new')->filter('form')->form();

        $form['trusted_host[host]']->setValue($host);

        if (true === $allowHttp) {
            $tick = $form['trusted_host[allowHttp]'];
            self::assertInstanceOf(ChoiceFormField::class, $tick);
            $tick->tick();
        }

        $this->client->submit($form);
    }

    private function reachable(IntegrationUrlValidator $validator, string $url): bool
    {
        try {
            $validator->assertAllowed($url);
        } catch (IntegrationException) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     *
     * @phpstan-impure
     */
    private function hosts(): array
    {
        $this->em()->clear();

        return array_map(
            static fn (TrustedHost $host): string => $host->host,
            static::getContainer()->get(TrustedHostRepository::class)->findAllOrdered(),
        );
    }

    /**
     * @phpstan-impure
     */
    private function row(string $host): TrustedHost
    {
        $this->em()->clear();

        $row = static::getContainer()->get(TrustedHostRepository::class)->findOneByHost($host);
        self::assertNotNull($row, sprintf('%s is not listed', $host));

        return $row;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
