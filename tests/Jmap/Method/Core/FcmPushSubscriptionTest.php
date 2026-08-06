<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Core;

use App\Entity\Push\FcmConfig;
use App\Entity\User\PushSubscription;
use App\Jmap\Method\Core\PushSubscriptionGetMethod;
use App\Jmap\Method\Core\PushSubscriptionSetMethod;
use App\Jmap\Push\FcmAccessTokenProvider;
use App\Jmap\Push\FcmSender;
use App\Jmap\Push\FcmSettings;
use App\Jmap\Push\PushDeliveryRecorder;
use App\Jmap\Push\PushSenderRegistry;
use App\Jmap\Push\WebPushSender;
use App\Repository\Push\FcmConfigRepository;
use App\Repository\User\PushSubscriptionRepository;
use App\Tests\Jmap\JmapTestCase;
use App\Tests\Jmap\Push\FirebaseFixture;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Registering a device for Firebase, and the refusals that keep the two
 * transports from becoming one confused one.
 *
 * PushSubscriptionSetMethodTest covers the Web Push half and the handshake it
 * exists to protect; this covers what `fcmToken` adds, and it is mostly about
 * saying no:
 *
 *   **A create carrying both a token and a URL is refused rather than
 *   resolved.** Picking one would make the device that actually receives the
 *   mail depend on the order of two ifs, and the client that sent both would
 *   never learn it had a bug.
 *
 *   **A create carrying a token on an install with no Firebase project is
 *   refused rather than stored.** Stored, it would be a device that completed
 *   registration, waits for a verification that cannot be sent, and has no way
 *   to find out. The Session says the same thing earlier; this is the backstop.
 *
 * And one thing it says yes to that Web Push does not: rotating `fcmToken` by
 * update. Android reissues tokens on its own schedule, so refusing would mean a
 * device going silent for doing something normal — but it re-arms the
 * handshake, because the new token has proved nothing.
 *
 * Built with a hand-assembled registry rather than the container's, so the send
 * that follows a create goes to a MockHttpClient. Every collaborator is final;
 * the alternative was a test that POSTs to Google.
 */
final class FcmPushSubscriptionTest extends JmapTestCase
{
    private PushSubscriptionRepository $subscriptions;
    private PushSubscriptionGetMethod $get;

    /** Toggled by configureFcm(); the registry reads it through FcmSettings. */
    private ?FcmConfig $config = null;

    protected function setUp(): void
    {
        parent::setUp();

        $container           = self::getContainer();
        $this->subscriptions = $container->get(PushSubscriptionRepository::class);
        $this->get           = $container->get(PushSubscriptionGetMethod::class);
    }

    public function testACreateWithAnFcmTokenRegistersAnUnverifiedFcmSubscription(): void
    {
        $this->configureFcm();

        $result = $this->handle(['create' => ['s1' => [
            'deviceClientId' => 'a-phone',
            'fcmToken'       => 'a-device-token',
        ]]]);

        $created = (array) $result['created'];

        self::assertArrayHasKey('s1', $created, json_encode($result['notCreated']));

        $subscription = $this->find($created['s1']['id']);

        self::assertSame('fcm', $subscription->transport->value);
        self::assertSame('a-device-token', $subscription->fcmToken);
        self::assertNull($subscription->url, 'an FCM subscription has no endpoint to POST to');
        self::assertFalse($subscription->verified, 'FCM is not exempt from the handshake');
    }

    public function testACreateCarryingBothATokenAndAUrlIsRefusedNamingTheConflict(): void
    {
        $this->configureFcm();

        $result = $this->handle(['create' => ['s1' => [
            'deviceClientId' => 'a-phone',
            'fcmToken'       => 'a-device-token',
            'url'            => 'https://push.example.test/endpoint',
            'keys'           => ['p256dh' => 'a-key', 'auth' => 'a-secret'],
        ]]]);

        $error = ((array) $result['notCreated'])['s1'];

        self::assertSame('invalidProperties', $error['type']);
        self::assertStringContainsString('fcmToken', $error['description']);
        self::assertStringContainsString('url', $error['description']);
        self::assertCount(0, $this->subscriptions->findForUser($this->user));
    }

    public function testACreateWithAnFcmTokenIsRefusedWhenFirebaseIsNotConfigured(): void
    {
        $result = $this->handle(['create' => ['s1' => [
            'deviceClientId' => 'a-phone',
            'fcmToken'       => 'a-device-token',
        ]]]);

        $error = ((array) $result['notCreated'])['s1'];

        self::assertSame('forbidden', $error['type']);
        self::assertStringContainsString('urn:plmail:params:jmap:push', $error['description']);
        self::assertCount(0, $this->subscriptions->findForUser($this->user), 'a subscription nothing can deliver to must not be stored');
    }

    /** Rotation is routine on Android — but the new token has proved nothing yet. */
    public function testRotatingTheTokenByUpdateReArmsTheHandshake(): void
    {
        $this->configureFcm();

        $subscription = $this->created();
        $subscription->verify((string) $subscription->verificationCode);
        $this->em->flush();

        $result = $this->handle(['update' => [(string) $subscription->id => ['fcmToken' => 'a-newer-token']]]);

        self::assertSame([(string) $subscription->id => null], (array) $result['updated']);
        self::assertSame('a-newer-token', $subscription->fcmToken);
        self::assertFalse($subscription->verified, 'a token nobody proved they can read must not inherit delivery');
        self::assertNotNull($subscription->verificationCode);
    }

    public function testFcmTokenCannotBeSetOnAWebPushSubscription(): void
    {
        $this->configureFcm();

        $subscription = PushSubscription::webPush($this->user, 'a-browser', 'https://push.example.test/endpoint');
        $this->em->persist($subscription);
        $this->em->flush();

        $result = $this->handle(['update' => [(string) $subscription->id => ['fcmToken' => 'a-device-token']]]);

        self::assertSame('invalidPatch', ((array) $result['notUpdated'])[(string) $subscription->id]['type']);
        self::assertNull($subscription->fcmToken);
    }

    /**
     * The token is write-only for the same reason the RFC 8291 keys are: it is
     * the whole address of one person's phone. `transport` is what a client
     * gets instead, because a device that moved between transports has one row.
     */
    public function testGetReportsTheTransportAndNeverTheToken(): void
    {
        $this->configureFcm();
        $this->created();

        $entry = $this->get->handle([], $this->context())['list'][0];

        self::assertSame('fcm', $entry['transport']);
        self::assertNull($entry['url']);
        self::assertArrayNotHasKey('fcmToken', $entry);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function handle(array $arguments): array
    {
        $container = self::getContainer();
        $client    = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse(
            (string) json_encode(str_contains($url, 'oauth2') ? ['access_token' => 'ya29.test', 'expires_in' => 3600] : ['name' => 'projects/x/messages/1']),
        ));

        $settings = new FcmSettings($container->get(FcmConfigRepository::class), new NullLogger());

        $registry = new PushSenderRegistry([
            $container->get(WebPushSender::class),
            new FcmSender(
                $settings,
                new FcmAccessTokenProvider($client, new NullLogger()),
                $client,
                $container->get(EntityManagerInterface::class),
                $container->get(PushDeliveryRecorder::class),
                new NullLogger(),
            ),
        ]);

        $method = new PushSubscriptionSetMethod($this->subscriptions, $registry, $this->em);

        return $method->handle($arguments, $this->context());
    }

    private function created(): PushSubscription
    {
        $result = $this->handle(['create' => ['s1' => [
            'deviceClientId' => 'a-phone',
            'fcmToken'       => 'a-device-token',
        ]]]);

        return $this->find(((array) $result['created'])['s1']['id']);
    }

    private function find(string $id): PushSubscription
    {
        $subscription = $this->subscriptions->findOneOwnedBy((int) $id, $this->user);

        self::assertNotNull($subscription);

        return $subscription;
    }

    private function configureFcm(): void
    {
        $this->config = new FcmConfig();
        $this->config->useCredentials(FirebaseFixture::serviceAccountJson(), FirebaseFixture::googleServicesJson());
        $this->config->isEnabled = true;

        $this->em->persist($this->config);
        $this->em->flush();
    }
}
