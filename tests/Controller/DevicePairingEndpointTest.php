<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User\User;
use App\Repository\User\ApiTokenRepository;
use App\Service\User\DevicePairingService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The two halves of pairing sit at different paths and different protection
 * levels, and getting that split wrong in either direction breaks something:
 *
 *   /settings/pair  needs a session. If it did not, anyone could cause a
 *                   pairing code to exist for an account they do not own.
 *   /device/pair    must not. A device that could authenticate would not need
 *                   to pair, so a firewall here makes the feature impossible.
 *
 * Both directions are asserted, because security.yaml's access_control is
 * ordered and first-match-wins — a rule added above these later would silently
 * close the public one, and the symptom would be "the app can't pair" long
 * after the change that caused it.
 */
final class DevicePairingEndpointTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private ApiTokenRepository $tokens;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testIssuingACodeRequiresASession(): void
    {
        $client = $this->boot();

        $client->request('POST', '/settings/pair');

        // A redirect to the login form, not a 200 with a code in it.
        self::assertResponseStatusCodeSame(302);
    }

    /**
     * A session is necessary but not sufficient: without this, any page the
     * user visited could mint them a pairing code, and a pairing code is one
     * exchange away from a permanent app password.
     */
    public function testIssuingACodeAlsoNeedsACsrfToken(): void
    {
        $client = $this->boot();
        $client->loginUser($this->seedUser());

        $client->request('POST', '/settings/pair', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testASignedInUserGetsAQrAndADeepLink(): void
    {
        $client = $this->boot();
        $client->loginUser($this->seedUser());
        $token = $this->token($client);

        $client->request(
            'POST',
            '/settings/pair',
            ['_token' => $token],
            server: ['HTTP_ACCEPT' => TurboBundle::STREAM_MEDIA_TYPE],
        );

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('plmail://pair?', $html);
        self::assertStringContainsString('data:image/', $html);

        // The whole design in one assertion: what is rendered onto a screen
        // carries no credential.
        self::assertStringNotContainsString('plmail_', $html);
    }

    /**
     * Without a Turbo Stream request the page just reloads. Asserted so the
     * form keeps working with JavaScript off rather than dumping JSON into the
     * browser window.
     */
    public function testAPlainPostRedirectsBackToSettings(): void
    {
        $client = $this->boot();
        $client->loginUser($this->seedUser());

        $client->request('POST', '/settings/pair', ['_token' => $this->token($client)]);

        self::assertResponseRedirects('/settings?section=app-passwords');
    }

    /** The endpoint the app calls, holding no credential at all. */
    public function testRedeemingNeedsNoSession(): void
    {
        $client = $this->boot();
        $user   = $this->seedUser();

        ['code' => $code] = static::getContainer()
            ->get(DevicePairingService::class)
            ->issue($user);

        $client->request(
            'POST',
            '/device/pair',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['code' => $code, 'deviceName' => 'Pixel 9']),
        );

        self::assertResponseIsSuccessful();

        $body = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame($user->email, $body['username']);
        self::assertStringStartsWith('plmail_', $body['secret']);
    }

    /**
     * Unknown, expired and already-used deliberately give one answer. Telling
     * them apart would confirm which codes had once been real.
     */
    public function testAnInvalidCodeIsRefusedWithoutSayingWhy(): void
    {
        $client = $this->boot();

        $client->request(
            'POST',
            '/device/pair',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['code' => 'never-existed', 'deviceName' => 'Pixel 9']),
        );

        self::assertResponseStatusCodeSame(404);

        $body = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame('notFound', $body['type']);
        self::assertStringNotContainsStringIgnoringCase('expired', $body['detail'] ?? '');
        self::assertStringNotContainsStringIgnoringCase('used', $body['detail'] ?? '');
    }

    public function testAReplayedCodeIsRefused(): void
    {
        $client = $this->boot();
        $user   = $this->seedUser();

        ['code' => $code] = static::getContainer()
            ->get(DevicePairingService::class)
            ->issue($user);

        $redeem = fn (): mixed => $client->request(
            'POST',
            '/device/pair',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['code' => $code, 'deviceName' => 'Pixel 9']),
        );

        $redeem();
        self::assertResponseIsSuccessful();

        $redeem();
        self::assertResponseStatusCodeSame(404);

        self::assertCount(1, $this->tokens->findForUser($user));
    }

    /** @return iterable<string, array{array<string,mixed>|string}> */
    public static function malformedBodies(): iterable
    {
        yield 'not an object'   => ['"just a string"'];
        yield 'no code'         => ['{"deviceName":"Pixel 9"}'];
        yield 'empty code'      => ['{"code":""}'];
        yield 'code not string' => ['{"code":42}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedBodies')]
    public function testAMalformedBodyIsRefusedWithoutAStackTrace(string $content): void
    {
        $client = $this->boot();

        $client->request(
            'POST',
            '/device/pair',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $content,
        );

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    /**
     * The name is what the user revokes by, so a device that sends none still
     * gets something legible rather than an empty row.
     */
    public function testAMissingDeviceNameFallsBackToSomethingLegible(): void
    {
        $client = $this->boot();
        $user   = $this->seedUser();

        ['code' => $code] = static::getContainer()
            ->get(DevicePairingService::class)
            ->issue($user);

        $client->request(
            'POST',
            '/device/pair',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['code' => $code]),
        );

        self::assertResponseIsSuccessful();
        self::assertSame('Paired device', $this->tokens->findForUser($user)[0]->name);
    }

    /** A hostile name cannot grow the column without bound. */
    public function testAnOverlongDeviceNameIsTruncated(): void
    {
        $client = $this->boot();
        $user   = $this->seedUser();

        ['code' => $code] = static::getContainer()
            ->get(DevicePairingService::class)
            ->issue($user);

        $client->request(
            'POST',
            '/device/pair',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['code' => $code, 'deviceName' => str_repeat('A', 500)]),
        );

        self::assertResponseIsSuccessful();
        self::assertSame(100, mb_strlen($this->tokens->findForUser($user)[0]->name));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->tokens     = $container->get(ApiTokenRepository::class);

        $this->connection->beginTransaction();

        return $client;
    }

    /**
     * Read off the settings page rather than minted.
     *
     * The page is where a real caller gets it, so this also asserts the button
     * is actually rendered — a token generated here would keep passing after
     * the UI that offers pairing had disappeared.
     */
    private function token(KernelBrowser $client): string
    {
        // The pairing form lives in the app-passwords section, which is where
        // it belongs: pairing is how you get an app password without typing one.
        $crawler = $client->request('GET', '/settings?section=app-passwords');

        $field = $crawler->filter('#settings-device-pairing input[name="_token"]');

        self::assertGreaterThan(0, $field->count(), 'the settings page offers no pairing form');

        return (string) $field->attr('value');
    }

    private function seedUser(): User
    {
        $user = new User();
        $user->email = 'pair-endpoint-'.uniqid('', true).'@example.test';
        $user->nameFirst = 'Pair';
        $user->nameLast = 'Endpoint';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $user->createdAt = new \DateTimeImmutable();
        $user->updatedAt = new \DateTimeImmutable();

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
