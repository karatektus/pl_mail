<?php

declare(strict_types=1);

namespace App\Tests\Controller\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\Integration;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Entity\User\User;
use App\Repository\User\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Connecting Nextcloud by approving in the browser (#235), end to end through
 * the settings routes, against a Nextcloud that is a MockHttpClient answering
 * the Login Flow v2 calls and the probe that follows.
 *
 * The address is pinned on the provider config, which is what keeps the test
 * off DNS: a pinned address is the admin's and is not re-validated.
 */
final class BrowserLoginTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string CLOUD       = 'https://cloud.example.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    /** Whether the fake Nextcloud has been approved in yet. */
    private bool $approved = false;

    /** @var list<string> */
    private array $calls = [];

    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $user     = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->user = $user;
        $this->em->getConnection()->beginTransaction();
        $this->em->getConnection()->executeStatement("DELETE FROM integration_provider_config WHERE provider = 'nextcloud'");
        $this->em->getConnection()->executeStatement('DELETE FROM integration WHERE usr_id = :u', ['u' => $user->id]);

        $config            = new IntegrationProviderConfig(Provider::Nextcloud);
        $config->isEnabled = true;
        $config->baseUrl   = self::CLOUD;
        $this->em->persist($config);
        $this->em->flush();

        static::getContainer()->set('app.http_client.integration', new MockHttpClient($this->nextcloud(...)));

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testTheConnectFormOffersTheButton(): void
    {
        $crawler = $this->client->request('GET', '/settings/integrations/connect/nextcloud');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="settings--integration-browser-login"]'));
    }

    /** Immich has no flow, so no button that would 404. */
    public function testAProviderWithoutAFlowHasNoButton(): void
    {
        $config            = new IntegrationProviderConfig(Provider::Immich);
        $config->isEnabled = true;
        $config->baseUrl   = 'https://photos.example.test';
        $this->em->persist($config);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/settings/integrations/connect/immich');

        self::assertCount(0, $crawler->filter('[data-controller="settings--integration-browser-login"]'));
        $this->post('/settings/integrations/browser-login/immich/start', ['name' => 'Immich']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testApprovingCreatesAConnectionThatWasProbed(): void
    {
        $start = $this->post('/settings/integrations/browser-login/nextcloud/start', ['name' => 'Home cloud']);

        self::assertResponseIsSuccessful();
        self::assertSame(self::CLOUD.'/login/v2/flow/abc', $start['loginUrl']);
        self::assertArrayNotHasKey('token', $start, 'the poll token never reaches the browser');

        self::assertSame(['status' => 'pending'], $this->post($start['pollUrl']));

        $this->approved = true;
        $done = $this->post($start['pollUrl']);

        self::assertSame('connected', $done['status']);
        self::assertTrue($done['ok']);
        self::assertStringContainsString('settings-integrations-frame', $done['stream']);

        $integration = $this->only();
        self::assertSame('Home cloud', $integration->name);
        self::assertSame('alice', $integration->username);
        self::assertSame('obtained-app-password', $integration->secret);
        self::assertNull($integration->baseUrl, 'a pinned address is not copied onto the connection');
        self::assertTrue($integration->isHealthy());
        self::assertContains('PROPFIND '.self::CLOUD.'/remote.php/dav/files/alice/', $this->calls);

        // Collected once: the state is gone.
        self::assertSame('failed', $this->post($start['pollUrl'])['status']);
    }

    /** How a revoked app password is replaced. */
    public function testApprovingFromTheEditFormReplacesThatConnectionsSecret(): void
    {
        $existing           = new Integration($this->user, Provider::Nextcloud, 'Home');
        $existing->username = 'old-name';
        $existing->secret   = 'revoked';
        $this->em->persist($existing);
        $this->em->flush();

        $start = $this->post('/settings/integrations/browser-login/nextcloud/start', [
            'name'        => 'Home',
            'integration' => (string) $existing->id,
        ]);
        $this->approved = true;
        $this->post($start['pollUrl']);

        $integration = $this->only();
        self::assertSame($existing->id, $integration->id);
        self::assertSame('alice', $integration->username);
        self::assertSame('obtained-app-password', $integration->secret);
    }

    /** Checked before the person goes through the approval, not after. */
    public function testANameAlreadyInUseIsRefusedAtTheStart(): void
    {
        $existing           = new Integration($this->user, Provider::Nextcloud, 'Nextcloud');
        $existing->username = 'bob';
        $existing->secret   = 'x';
        $this->em->persist($existing);
        $this->em->flush();

        $answer = $this->post('/settings/integrations/browser-login/nextcloud/start', ['name' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('already have a connection with this name', $answer['message']);
        self::assertNotContains('POST '.self::CLOUD.'/index.php/login/v2', $this->calls);
    }

    public function testAnUnknownApprovalReadsAsExpired(): void
    {
        $answer = $this->post('/settings/integrations/browser-login/poll/AAAAAAAAAAAAAAAAAAAAAAAA');

        self::assertSame(['status' => 'failed', 'message' => 'This approval has expired. Start again.'], $answer);
    }

    public function testBothCallsNeedTheToken(): void
    {
        $this->client->request('POST', '/settings/integrations/browser-login/nextcloud/start', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{"name":"x"}');

        self::assertResponseStatusCodeSame(403);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string,string> $payload
     *
     * @return array<string,mixed>
     */
    private function post(string $url, array $payload = []): array
    {
        $this->client->request('POST', $url, server: [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->token(),
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** Read off the button, the way the browser gets it. */
    private function token(): string
    {
        if (null === $this->token) {
            $crawler = $this->client->request('GET', '/settings/integrations/connect/nextcloud');

            $this->token = (string) $crawler
                ->filter('[data-controller="settings--integration-browser-login"]')
                ->attr('data-settings--integration-browser-login-csrf-value');
        }

        return $this->token;
    }

    private function only(): Integration
    {
        $this->em->clear();
        $rows = $this->em->getRepository(Integration::class)->findBy(['usr' => $this->user->id]);

        self::assertCount(1, $rows);

        return $rows[0];
    }

    /**
     * @param array<string,mixed> $options
     */
    private function nextcloud(string $method, string $url, array $options): ResponseInterface
    {
        $this->calls[] = $method.' '.$url;

        return match (true) {
            'POST' === $method && self::CLOUD.'/index.php/login/v2' === $url => new JsonMockResponse([
                'poll'  => ['token' => 'poll-token', 'endpoint' => self::CLOUD.'/login/v2/poll'],
                'login' => self::CLOUD.'/login/v2/flow/abc',
            ]),
            'POST' === $method && self::CLOUD.'/login/v2/poll' === $url => $this->approved
                ? new JsonMockResponse(['server' => self::CLOUD, 'loginName' => 'alice', 'appPassword' => 'obtained-app-password'])
                : new MockResponse('', ['http_code' => 404]),
            'PROPFIND' === $method => new MockResponse(
                '<d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/files/alice/</d:href></d:response></d:multistatus>',
                ['http_code' => 207],
            ),
            default => new MockResponse('', ['http_code' => 500]),
        };
    }
}
