<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Push;

use App\Domain\Enum\PushDeliveryOutcome;
use App\Entity\Push\PushDelivery;
use App\Entity\User\PushSubscription;
use App\Entity\User\User;
use App\Jmap\Push\PushDeliveryRecorder;
use App\Jmap\Push\WebPushSender;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The two ways Web Push declines to send anything, and the fact that both are
 * still written down.
 *
 * A skip is the outcome most worth logging and the one a naive implementation
 * would never record, because nothing happened: no request, no response, no
 * error. It is also the single most common reason a user's notifications do not
 * arrive on a self-hosted install — nobody generated VAPID keys — and it is
 * invisible everywhere else, since the dispatcher deliberately does not log per
 * subscription (an install with a transport switched off would otherwise write
 * one warning per device per state change).
 *
 * Skipped rather than failed, and that distinction is asserted: an unconfigured
 * transport is a deployment that has not been finished, and colouring it as a
 * failure would send an admin looking for a broken device.
 *
 * The delivering paths are FcmSenderTest's subject rather than this file's. The
 * library builds its own HTTP client inside send(), so exercising a 201 or a
 * 410 here would mean POSTing to a real push service.
 */
final class WebPushSenderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user = new User();
        $this->user->email     = 'webpush-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Web';
        $this->user->nameLast  = 'Push';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';

        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnInstallWithNoVapidKeysRecordsASkipRatherThanNothing(): void
    {
        $subscription = $this->subscription('https://push.example.test/endpoint');

        self::assertFalse($this->sender(configured: false)->send($subscription, ['@type' => 'StateChange']));

        $delivery = $this->delivery();

        self::assertSame(PushDeliveryOutcome::Skipped, $delivery->outcome, 'an unfinished deployment is not a broken device');
        self::assertSame('no-vapid-keys', $delivery->detail);
        self::assertSame('a-browser', $delivery->deviceClientId);
        self::assertSame('webpush', $delivery->transport->value);
        self::assertSame('StateChange', $delivery->payloadType);
    }

    /**
     * A row the registry should never route here — an FCM subscription carries
     * a token and no URL. It is recorded rather than merely logged for the same
     * reason the guard exists at all: if routing ever breaks, the evidence is
     * on the device's own history instead of in a container log nobody reads.
     */
    public function testASubscriptionWithNoEndpointRecordsASkipNamingThat(): void
    {
        $subscription = $this->subscription(null);

        self::assertFalse($this->sender()->send($subscription, ['@type' => 'StateChange']));

        self::assertSame('no-endpoint-url', $this->delivery()->detail);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function sender(bool $configured = true): WebPushSender
    {
        return new WebPushSender(
            $this->em,
            new PushDeliveryRecorder($this->em, new NullLogger()),
            new NullLogger(),
            'mailto:admin@example.test',
            true === $configured ? 'a-public-key' : '',
            true === $configured ? 'a-private-key' : '',
        );
    }

    private function subscription(?string $url): PushSubscription
    {
        $subscription = PushSubscription::webPush($this->user, 'a-browser', $url ?? 'https://push.example.test/e');
        $subscription->url = $url;

        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    private function delivery(): PushDelivery
    {
        $deliveries = $this->em->getRepository(PushDelivery::class)->findBy(['usr' => $this->user->id]);

        self::assertCount(1, $deliveries, 'exactly one attempt, exactly one row');

        return $deliveries[0];
    }
}
