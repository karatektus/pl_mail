<?php

declare(strict_types=1);

namespace App\Tests\Service\Push;

use App\Domain\Enum\PushTransport;
use App\Domain\Interface\PushSenderInterface;
use App\Entity\User\PushSubscription;
use App\Entity\User\User;
use App\Jmap\Push\PushSenderRegistry;
use App\Repository\User\PushSubscription\PushSubscriptionRepository;
use App\Service\Push\UserPushNotifier;
use PHPUnit\Framework\TestCase;

/**
 * Nowhere to send it and nobody taking it are told apart.
 *
 * Calendar alerts log a warning when devices refused, and a user with no device
 * is how most users are: with one answer for both, every alert of every such user
 * was a warning.
 */
final class UserPushNotifierTest extends TestCase
{
    public function testAUserWithNoDeviceIsNowhereToSendIt(): void
    {
        self::assertNull($this->notifier([], new FixedPushSender(true))->notify(1, ['title' => 'x']));
    }

    public function testAnInstallWithNoTransportIsNowhereToSendIt(): void
    {
        self::assertNull($this->notifier([$this->device()], new FixedPushSender(true, configured: false))->notify(1, ['title' => 'x']));
    }

    public function testDevicesThatAllRefusedAreFalse(): void
    {
        self::assertFalse($this->notifier([$this->device()], new FixedPushSender(false))->notify(1, ['title' => 'x']));
    }

    public function testOneDeviceTakingItIsEnough(): void
    {
        self::assertTrue($this->notifier([$this->device(), $this->device()], new FixedPushSender(true))->notify(1, ['title' => 'x']));
    }

    /** @param list<PushSubscription> $devices */
    private function notifier(array $devices, PushSenderInterface $sender): UserPushNotifier
    {
        $subscriptions = $this->createStub(PushSubscriptionRepository::class);
        $subscriptions->method('findDeliverableForUser')->willReturn($devices);

        return new UserPushNotifier($subscriptions, new PushSenderRegistry([$sender]));
    }

    private function device(): PushSubscription
    {
        return PushSubscription::webPush(new User(), uniqid('device-'), 'https://push.example.org/' . uniqid());
    }
}

/** Answers every send the same way, since the real senders are final and need a push service. */
final class FixedPushSender implements PushSenderInterface
{
    public function __construct(
        private readonly bool $accepts,
        private readonly bool $configured = true,
    ) {}

    public function transport(): PushTransport
    {
        return PushTransport::WebPush;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(PushSubscription $subscription, array $payload): bool
    {
        return $this->accepts;
    }
}
