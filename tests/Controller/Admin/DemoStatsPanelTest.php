<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Demo\DemoVisit;
use App\Entity\User\User;
use App\Repository\Demo\DemoVisitRepository;
use App\Repository\User\UserRepository;
use App\Service\Demo\DemoMode;
use App\Service\Demo\DemoUserEraser;
use App\Service\Demo\DemoVisitorFingerprint;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Admin → Demo visitors: counted on a demo, absent everywhere else, and never
 * holding an address.
 *
 * Demo mode is flipped the way DemoFlowTest flips it — through $_SERVER before
 * the kernel boots — which is what lets the "switched off" half be tested.
 */
final class DemoStatsPanelTest extends WebTestCase
{
    /** TEST-NET-3: an address nobody has, so nothing here can be anyone's. */
    private const string VISITOR_IP = '203.0.113.57';

    protected function setUp(): void
    {
        unset($_SERVER['APP_DEMO_MODE'], $_ENV['APP_DEMO_MODE']);
    }

    protected function tearDown(): void
    {
        $container = static::getContainer();
        $entities  = $container->get(EntityManagerInterface::class);
        $mode      = $container->get(DemoMode::class);

        foreach ($container->get(UserRepository::class)->findAll() as $user) {
            if (true === $mode->ownsAddress($user->email)) {
                $container->get(DemoUserEraser::class)->erase($user);
            }
        }

        $entities->createQuery('DELETE FROM '.DemoVisit::class)->execute();

        unset($_SERVER['APP_DEMO_MODE'], $_ENV['APP_DEMO_MODE']);

        parent::tearDown();
    }

    public function testThePanelDoesNotExistOnANormalInstall(): void
    {
        $client = $this->client(demo: false);
        $this->signIn($client, 'e2e-admin@plmail.test');

        $client->request('GET', '/admin/demo-stats');

        self::assertResponseStatusCodeSame(404);
    }

    public function testANormalInstallHasNoSuchSection(): void
    {
        $client = $this->client(demo: false);
        $this->signIn($client, 'e2e-admin@plmail.test');

        $crawler = $client->request('GET', '/admin?section=demo');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('turbo-frame#admin-demo'), 'the section falls back to the default');
        self::assertCount(0, $crawler->filter('a[href$="section=demo"]'), 'and the nav does not offer it');
    }

    public function testOnADemoTheSectionIsInTheNavAndLoadsThePanel(): void
    {
        $client = $this->client(demo: true);
        $this->signIn($client, 'e2e-admin@plmail.test');

        $crawler = $client->request('GET', '/admin?section=demo');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#admin-demo[src="/admin/demo-stats"]'));
        self::assertGreaterThan(0, $crawler->filter('a[href$="section=demo"]')->count());
    }

    public function testOnlyAnAdministratorReachesIt(): void
    {
        $client = $this->client(demo: true);
        $this->signIn($client, 'e2e@plmail.test');

        $client->request('GET', '/admin/demo-stats');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The whole path: a visitor follows /demo, a row is written that holds a
     * hash and no address, and the panel counts it.
     */
    public function testStartingADemoIsCountedWithoutTheAddress(): void
    {
        $client = $this->client(demo: true);

        $client->request('GET', '/demo', server: ['REMOTE_ADDR' => self::VISITOR_IP]);

        self::assertResponseRedirects();

        $container = static::getContainer();
        $visits    = $container->get(DemoVisitRepository::class)->findAll();

        self::assertCount(1, $visits);
        self::assertSame($container->get(DemoVisitorFingerprint::class)->of(self::VISITOR_IP), $visits[0]->visitorHash);
        self::assertNotNull($visits[0]->visitorHash);
        self::assertStringNotContainsString('203.0.113', (string) $visits[0]->visitorHash);

        $this->signIn($client, 'e2e-admin@plmail.test');
        $crawler = $client->request('GET', '/admin/demo-stats');

        self::assertResponseIsSuccessful();
        self::assertSame('1', trim($crawler->filter('tr[data-window="hour"] [data-count="sessions"]')->text()));
        self::assertSame('1', trim($crawler->filter('tr[data-window="hour"] [data-count="visitors"]')->text()));
        self::assertSame('1', trim($crawler->filter('tr[data-window="total"] [data-count="sessions"]')->text()));
    }

    /**
     * Two sessions from one network are two sessions and one visitor, and a
     * visit older than a window is outside it.
     */
    public function testWindowsCountSessionsAndDifferentVisitorsSeparately(): void
    {
        $client = $this->client(demo: true);
        $this->signIn($client, 'e2e-admin@plmail.test');

        $container   = static::getContainer();
        $entities    = $container->get(EntityManagerInterface::class);
        $fingerprint = $container->get(DemoVisitorFingerprint::class);

        $entities->persist(new DemoVisit($fingerprint->of('203.0.113.1')));
        $entities->persist(new DemoVisit($fingerprint->of('203.0.113.2')));
        $entities->persist(new DemoVisit($fingerprint->of('198.51.100.9')));
        $entities->persist($this->visitAt('-3 days', $fingerprint->of('192.0.2.4')));
        $entities->flush();

        $crawler = $client->request('GET', '/admin/demo-stats');

        self::assertResponseIsSuccessful();
        self::assertSame('3', trim($crawler->filter('tr[data-window="day"] [data-count="sessions"]')->text()));
        self::assertSame('2', trim($crawler->filter('tr[data-window="day"] [data-count="visitors"]')->text()));
        self::assertSame('4', trim($crawler->filter('tr[data-window="week"] [data-count="sessions"]')->text()));
        self::assertSame('3', trim($crawler->filter('tr[data-window="week"] [data-count="visitors"]')->text()));
    }

    /**
     * Past the retention period a visit is still a visit, and no longer
     * anybody's.
     */
    public function testAnOldVisitLosesItsHashAndKeepsItsPlaceInTheTotal(): void
    {
        $this->client(demo: true);

        $container  = static::getContainer();
        $entities   = $container->get(EntityManagerInterface::class);
        $repository = $container->get(DemoVisitRepository::class);

        $entities->persist($this->visitAt('-40 days', 'old'));
        $entities->persist($this->visitAt('-2 days', 'recent'));
        $entities->flush();

        $cutoff = new DateTimeImmutable(sprintf('-%d days', DemoVisitRepository::HASH_RETENTION_DAYS));

        self::assertSame(1, $repository->forgetVisitorsBefore($cutoff));

        $entities->clear();

        $hashes = array_map(static fn (DemoVisit $visit): ?string => $visit->visitorHash, $repository->findBy([], ['createdAt' => 'ASC']));

        self::assertSame([null, 'recent'], $hashes);
        self::assertSame(2, $repository->countAll());
    }

    private function visitAt(string $when, ?string $hash): DemoVisit
    {
        $visit = new DemoVisit($hash);
        $visit->restoreCreatedAt(new DateTimeImmutable($when));

        return $visit;
    }

    private function client(bool $demo): KernelBrowser
    {
        if (true === $demo) {
            $_SERVER['APP_DEMO_MODE'] = '1';
            $_ENV['APP_DEMO_MODE']    = '1';
        }

        $client = static::createClient();

        // Per address, and it outlives the test — see DemoFlowTest::demoClient().
        static::getContainer()
            ->get(RateLimiterFactoryInterface::class.' $demoProvisionLimiter')
            ->create(self::VISITOR_IP)
            ->reset();

        return $client;
    }

    private function signIn(KernelBrowser $client, string $email): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user` first');
        }

        $client->loginUser($user);
    }
}
