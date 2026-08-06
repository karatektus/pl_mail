<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Push;

use App\Entity\Push\FcmConfig;
use App\Entity\User\PushSubscription;
use App\Entity\User\User;
use App\Jmap\Push\FcmAccessTokenProvider;
use App\Jmap\Push\FcmSender;
use App\Jmap\Push\FcmSettings;
use App\Repository\Push\FcmConfigRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What FCM is actually sent, and which of its refusals retire a device.
 *
 * Three claims, and each one is invisible from anywhere else.
 *
 * **Data only, and the same JSON Web Push carries.** A `notification` block
 * would be drawn by the system tray before the app saw it, putting a
 * server-authored string on a lock screen for a protocol that pushes no mail
 * content — and the client would lose the decision of whether to show anything
 * at all. Nothing downstream can detect that mistake; it looks like a working
 * notification.
 *
 * **The collapse key is per payload type.** Collapsing is wanted — a phone that
 * was off should be woken by the newest state, not nine stale ones — but one
 * shared key lets an ordinary StateChange discard an undelivered
 * PushVerification, and the subscription then waits forever for a code FCM threw
 * away. The failure is a device that registers and never verifies, months apart
 * from the change that caused it.
 *
 * **UNREGISTERED retires the token; a quota rejection does not.** Getting this
 * backwards costs either a dead endpoint POSTed to forever or, worse, ten
 * perfectly good phones unsubscribed during one Firebase outage.
 *
 * Against a real container and database with a MockHttpClient, for the reason
 * GoogleCalendarPushManagerTest gives: the collaborators are final, the
 * behaviour worth pinning is what a later query finds on the row, and the
 * captured request is the only place the payload exists.
 */
final class FcmSenderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    /** @var list<array{url:string,body:array<string,mixed>,headers:array<int,string>}> */
    private array $requests = [];

    private MockResponse $next;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->next = new MockResponse((string) json_encode(['name' => 'projects/plmail-test/messages/1']));
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAStateChangeGoesOutAsADataOnlyMessageCarryingTheSameJsonWebPushSends(): void
    {
        $subscription = $this->subscription();
        $payload      = ['@type' => 'StateChange', 'changed' => ['7' => ['Email' => '9']]];

        self::assertTrue($this->sender()->send($subscription, $payload));

        $message = $this->requests[0]['body']['message'];

        self::assertSame('https://fcm.googleapis.com/v1/projects/plmail-test/messages:send', $this->requests[0]['url']);
        self::assertSame('a-device-token', $message['token']);
        self::assertArrayNotHasKey('notification', $message, 'a notification payload takes the decision away from the client');
        self::assertSame(
            $payload,
            json_decode($message['data']['payload'], true, 512, JSON_THROW_ON_ERROR),
            'the body must be byte-for-byte the object Web Push delivers',
        );
        self::assertSame('86400s', $message['android']['ttl']);
        self::assertSame('HIGH', $message['android']['priority']);
        self::assertSame('plmail-state-change', $message['android']['collapse_key']);
    }

    /** See the class docblock: one key would let ordinary traffic eat the handshake. */
    public function testAVerificationCollapsesUnderItsOwnKeyRatherThanTheStateChangeOne(): void
    {
        $this->sender()->send($this->subscription(), [
            '@type'              => 'PushVerification',
            'pushSubscriptionId' => '1',
            'verificationCode'   => 'abc',
        ]);

        self::assertSame('plmail-push-verification', $this->requests[0]['body']['message']['android']['collapse_key']);
    }

    public function testAnUnregisteredTokenRemovesTheSubscription(): void
    {
        $subscription = $this->subscription();

        $this->next = new MockResponse((string) json_encode([
            'error' => [
                'code'    => 404,
                'status'  => 'NOT_FOUND',
                'message' => 'Requested entity was not found.',
                'details' => [[
                    '@type'     => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                    'errorCode' => 'UNREGISTERED',
                ]],
            ],
        ]), ['http_code' => 404]);

        self::assertFalse($this->sender()->send($subscription, ['@type' => 'StateChange', 'changed' => []]));

        $this->em->clear();

        self::assertCount(0, $this->em->getRepository(PushSubscription::class)->findBy(['usr' => $this->user->id]));
    }

    /**
     * The one that matters in the other direction. Firebase having a bad hour
     * must not unsubscribe a phone that is working perfectly.
     */
    public function testAQuotaRejectionKeepsTheSubscriptionAndDoesNotEvenCountAsAFailure(): void
    {
        $subscription = $this->subscription();

        $this->next = new MockResponse((string) json_encode([
            'error' => [
                'code'    => 429,
                'status'  => 'RESOURCE_EXHAUSTED',
                'details' => [['errorCode' => 'QUOTA_EXCEEDED']],
            ],
        ]), ['http_code' => 429]);

        self::assertFalse($this->sender()->send($subscription, ['@type' => 'StateChange', 'changed' => []]));

        self::assertSame(0, $subscription->failureCount, 'an outage is not a broken endpoint');
        self::assertNotNull($subscription->id);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function sender(): FcmSender
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            // The grant, which every send opens with; answered generically so
            // the tests are about the message rather than about OAuth.
            if (true === str_contains($url, 'oauth2.googleapis.com')) {
                return new MockResponse((string) json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600]));
            }

            $this->requests[] = [
                'url'     => $url,
                'body'    => json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR),
                'headers' => $options['headers'] ?? [],
            ];

            return $this->next;
        });

        $settings = new FcmSettings(self::getContainer()->get(FcmConfigRepository::class), new NullLogger());

        return new FcmSender(
            $settings,
            new FcmAccessTokenProvider($client, new NullLogger()),
            $client,
            $this->em,
            new NullLogger(),
        );
    }

    private function subscription(): PushSubscription
    {
        $subscription = PushSubscription::fcm($this->user, 'a-phone', 'a-device-token');
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user->email     = 'fcm-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Fcm';
        $this->user->nameLast  = 'Fixture';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';
        $this->em->persist($this->user);

        $config = new FcmConfig();
        $config->useCredentials(FirebaseFixture::serviceAccountJson(), FirebaseFixture::googleServicesJson());
        $config->isEnabled = true;
        $this->em->persist($config);

        $this->em->flush();
    }
}
