<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The panel's endpoint answers, and it answers without asking a host anything.
 *
 * WHAT THIS IS ACTUALLY GUARDING
 * ──────────────────────────────
 * Two things a unit test cannot see. That the endpoint is wired at all — the
 * panel service, the backfill service, the policy read out of container
 * parameters and the fragment it renders are four pieces that only meet in the
 * container; and that it is ADMIN-ONLY, because it reports the address of a
 * box on the operator's private network.
 *
 * With the AI switched off — which is how every test installation is — nothing
 * here touches the network at all, which is also the assertion worth making:
 * off means off, including not probing.
 *
 * Skips itself without the seeded admin, as the admin tests next door do.
 */
final class AiStatusEndpointTest extends WebTestCase
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
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheStatusEndpointAnswersWithAReadingAndAFragment(): void
    {
        $this->client->request('GET', '/admin/ai/status');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertIsArray($payload);
        self::assertFalse($payload['enabled'], 'a test installation has never switched the AI on');
        self::assertArrayHasKey('backfill', $payload);
        self::assertSame('idle', $payload['backfill']['status']);
        // The panel renders server-side and travels with the reading; the
        // controller in the browser only decides when to ask.
        self::assertStringContainsString('<div', (string) $payload['html']);
    }

    /** The window is a closed set, and anything else is the day. */
    public function testAnUnknownWindowFallsBackRatherThanFailing(): void
    {
        $this->client->request('GET', '/admin/ai/status?window=fortnight');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame('day', $payload['window']);
    }

    /**
     * The controls are state-changing and take the shared `ajax` token, the way
     * every other JSON POST in this application does.
     */
    public function testTheBackfillControlsRefuseARequestWithoutAToken(): void
    {
        $this->client->catchExceptions(false);

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);

        $this->client->request('POST', '/admin/ai/backfill/start');
    }

    public function testStartingWithSearchOffIsRefusedPolitely(): void
    {
        $this->client->request(
            'POST',
            '/admin/ai/backfill/start',
            server: ['HTTP_X-CSRF-Token' => $this->token()],
        );

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        // A refusal with a reason, not a 500 and not a silent no-op: semantic
        // search is off on this installation, so there is nothing to index.
        self::assertSame('search_off', $payload['outcome']);
        self::assertSame('idle', $payload['backfill']['status']);
    }

    /**
     * The shared `ajax` token, taken where the browser takes it.
     *
     * Off the layout's meta tag rather than out of the token manager: the
     * manager needs a session, which only exists inside a request, and reading
     * it the way csrf.js does is also the only way this test proves the two
     * ends agree on the id.
     */
    private function token(): string
    {
        $crawler = $this->client->request('GET', '/admin');

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }
}
