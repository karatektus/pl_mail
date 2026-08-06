<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Push;

use App\Domain\Enum\PushTransport;
use App\Domain\Interface\PushSenderInterface;
use App\Entity\User\PushSubscription;
use App\Entity\User\User;
use App\Jmap\Protocol\StateChangeBuilder;
use App\Jmap\Push\PushDispatcher;
use App\Jmap\Push\PushSenderRegistry;
use App\Repository\User\PushSubscriptionRepository;
use App\Repository\User\UserRepository;
use App\Tests\Jmap\JmapTestCase;
use Psr\Log\NullLogger;

/**
 * A user with a phone on Firebase and a browser on Web Push is told twice, and
 * each device by the sender that can reach it.
 *
 * The claim exists because the dispatcher used to hold one sender and open with
 * `if (false === $this->sender->isConfigured()) return;`. Kept as it was, that
 * line would make an install with no VAPID keys deliver nothing over Firebase
 * either — and an install with no Firebase project would be unaffected, so the
 * bug would only appear on the deployments that adopted FCM *instead of* Web
 * Push, which is exactly the Android-only case this feature is for.
 *
 * The second half is the one that goes wrong quietly: a transport that is
 * switched off must skip its own rows and leave the others alone. Turning
 * Firebase off has to be a decision about Android, not about push.
 *
 * Real database, recording senders. The senders are doubles here rather than
 * the real ones because the subject is which rows reach which sender, and the
 * real pair would need a Firebase project and a push service to answer at all.
 */
final class PushDispatcherFanOutTest extends JmapTestCase
{
    public function testABrowserAndAPhoneAreBothToldByTheirOwnTransport(): void
    {
        $this->subscribe(PushSubscription::webPush($this->user, 'a-browser', 'https://push.example.test/e'));
        $this->subscribe(PushSubscription::fcm($this->user, 'a-phone', 'a-device-token'));

        $webPush = new RecordingPushSender(PushTransport::WebPush);
        $fcm     = new RecordingPushSender(PushTransport::Fcm);

        $this->dispatch($webPush, $fcm);

        self::assertCount(1, $webPush->sent, 'the browser was not told');
        self::assertCount(1, $fcm->sent, 'the phone was not told');
        self::assertSame('a-browser', $webPush->sent[0]->deviceClientId);
        self::assertSame('a-phone', $fcm->sent[0]->deviceClientId);
    }

    public function testATransportThatIsSwitchedOffSkipsItsOwnRowsAndNoOthers(): void
    {
        $this->subscribe(PushSubscription::webPush($this->user, 'a-browser', 'https://push.example.test/e'));
        $this->subscribe(PushSubscription::fcm($this->user, 'a-phone', 'a-device-token'));

        $webPush = new RecordingPushSender(PushTransport::WebPush);
        $fcm     = new RecordingPushSender(PushTransport::Fcm, configured: false);

        $this->dispatch($webPush, $fcm);

        self::assertCount(1, $webPush->sent, 'Firebase being off must not silence the browser');
        self::assertCount(0, $fcm->sent);
    }

    private function dispatch(PushSenderInterface ...$senders): void
    {
        $container = self::getContainer();

        new PushDispatcher(
            $container->get(PushSubscriptionRepository::class),
            $container->get(UserRepository::class),
            new PushSenderRegistry($senders),
            $container->get(StateChangeBuilder::class),
            new NullLogger(),
        )->dispatch([(int) $this->account->id => ['Email' => '9']]);
    }

    /** Verified, because an unverified subscription is deliverable to nothing. */
    private function subscribe(PushSubscription $subscription): void
    {
        $subscription->verify((string) $subscription->verificationCode);
        $this->em->persist($subscription);
        $this->em->flush();
    }
}

/**
 * A sender that records rather than delivers.
 *
 * Written out because PushSenderInterface's implementations are final and the
 * question here is routing, not delivery — the real pair would need a Firebase
 * project and a push service before either could answer.
 */
final class RecordingPushSender implements PushSenderInterface
{
    /** @var list<PushSubscription> */
    public array $sent = [];

    public function __construct(
        private readonly PushTransport $transport,
        private readonly bool          $configured = true,
    ) {}

    public function transport(): PushTransport
    {
        return $this->transport;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(PushSubscription $subscription, array $payload): bool
    {
        $this->sent[] = $subscription;

        return true;
    }
}
