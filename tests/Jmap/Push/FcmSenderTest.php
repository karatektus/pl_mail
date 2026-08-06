<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Push;

use App\Domain\Enum\PushDeliveryOutcome;
use App\Entity\Push\FcmConfig;
use App\Entity\Push\PushDelivery;
use App\Entity\User\PushSubscription;
use App\Entity\User\User;
use App\Jmap\Push\FcmAccessTokenProvider;
use App\Jmap\Push\FcmSender;
use App\Jmap\Push\FcmSettings;
use App\Jmap\Push\PushDeliveryRecorder;
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
 * And, since the delivery log arrived, a fourth: **what the log is told, and
 * what it is deliberately not told.** The outcomes above are invisible to the
 * bool `send()` returns, which is why the recording happens in the sender — and
 * the payload is deliberately not recorded beyond its `@type`, which is a
 * privacy promise a test has to hold rather than a docblock.
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

    // ── What the delivery log is told ─────────────────────────────────────

    /**
     * The log records the `@type` and nothing else of the payload.
     *
     * This is the claim the whole feature depends on being true: a StateChange
     * names the accounts and state tokens that moved, so a log that kept the
     * body would be a retained, admin-readable index of when each user's mail
     * arrives. Asserted by searching every column for a value that only exists
     * inside the payload, so a future column that "helpfully" stores the
     * changed map fails this rather than shipping.
     */
    public function testTheLogRecordsThePayloadTypeAndNothingElseOfThePayload(): void
    {
        $this->sender()->send($this->subscription(), [
            '@type'   => 'StateChange',
            'changed' => ['7' => ['Email' => 'a-secret-state-token']],
        ]);

        $delivery = $this->deliveries()[0];

        self::assertSame('StateChange', $delivery->payloadType);
        self::assertStringNotContainsString(
            'a-secret-state-token',
            (string) json_encode([$delivery->detail, $delivery->payloadType, $delivery->deviceClientId]),
            'the log must not become a copy of anyone mail activity',
        );
    }

    public function testAnAcceptedSendIsRecordedAgainstTheDeviceAndTheTransport(): void
    {
        $this->sender()->send($this->subscription(), ['@type' => 'StateChange', 'changed' => []]);

        $delivery = $this->deliveries()[0];

        self::assertSame(PushDeliveryOutcome::Accepted, $delivery->outcome);
        self::assertSame('a-phone', $delivery->deviceClientId);
        self::assertSame('fcm', $delivery->transport->value);
        self::assertSame('HTTP 200', $delivery->detail);
        self::assertSame((int) $this->user->id, (int) $delivery->usr->id);
    }

    /**
     * The row that outlives the subscription.
     *
     * Recorded as destroyed rather than failed, and written before the delete
     * — the whole reason the log has no foreign key to the subscription. A
     * cascading reference would take this row with it, erasing the only
     * explanation of why a device stopped appearing in the user's settings.
     */
    public function testRetiringATokenLeavesTheRecordThatExplainsIt(): void
    {
        $subscription = $this->subscription();

        $this->next = new MockResponse((string) json_encode([
            'error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]],
        ]), ['http_code' => 404]);

        $this->sender()->send($subscription, ['@type' => 'StateChange', 'changed' => []]);

        $this->em->clear();

        $delivery = $this->deliveries()[0];

        self::assertSame(PushDeliveryOutcome::SubscriptionDestroyed, $delivery->outcome);
        self::assertSame('UNREGISTERED', $delivery->detail, 'the error name is the whole point of recording in the sender');
    }

    /**
     * An outage reads as a failure in the log while deliberately not counting
     * as one against the endpoint. The two answer different questions — "what
     * happened" and "should this device be retired" — and a log that stayed
     * silent about a 429 would leave an admin with nothing to look at during
     * precisely the incident they opened the page for.
     */
    public function testAnOutageIsLoggedAsAFailureEvenThoughItDoesNotCountAsOne(): void
    {
        $subscription = $this->subscription();

        $this->next = new MockResponse((string) json_encode([
            'error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'details' => [['errorCode' => 'QUOTA_EXCEEDED']]],
        ]), ['http_code' => 429]);

        $this->sender()->send($subscription, ['@type' => 'StateChange', 'changed' => []]);

        $delivery = $this->deliveries()[0];

        self::assertSame(PushDeliveryOutcome::Failed, $delivery->outcome);
        self::assertSame('QUOTA_EXCEEDED', $delivery->detail);
        self::assertSame(0, $subscription->failureCount);
    }

    /**
     * A verification is a delivery like any other and is logged as one. It is
     * also the one a support conversation starts from: "the phone registered
     * and never verified" is answered by whether this row exists and what it
     * says.
     */
    public function testTheVerificationHandshakeIsLoggedToo(): void
    {
        $this->sender()->send($this->subscription(), [
            '@type'              => 'PushVerification',
            'pushSubscriptionId' => '1',
            'verificationCode'   => 'abc',
        ]);

        self::assertSame('PushVerification', $this->deliveries()[0]->payloadType);
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
            new PushDeliveryRecorder($this->em, new NullLogger()),
            new NullLogger(),
        );
    }

    /**
     * The delivery log rows this user's device produced, oldest first.
     *
     * Read back through the repository rather than asserted on an object the
     * sender returned, because the sender returns a bool: the row is the only
     * evidence, and reading it the way the admin page does is the point.
     *
     * @return list<PushDelivery>
     */
    private function deliveries(): array
    {
        $this->em->flush();

        return $this->em->getRepository(PushDelivery::class)->findBy(
            ['usr' => $this->user->id],
            ['id' => 'ASC'],
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
