<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Event\Subscriber;

use App\Domain\Enum\PushTransport;
use App\Entity\User\PushSubscription;
use App\Infrastructure\Event\Subscriber\JmapPushSubscriber;
use App\Jmap\Protocol\StateChangeBuilder;
use App\Jmap\Push\PushDispatcher;
use App\Jmap\Push\PushSenderRegistry;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\User\PushSubscriptionRepository;
use App\Repository\User\UserRepository;
use App\Service\Mail\MailChangeNotifier;
use App\Tests\Jmap\JmapTestCase;
use App\Tests\Jmap\Push\RecordingArrivalsSender;
use Psr\Log\NullLogger;

/**
 * The flush is where arrivals meet the dispatcher. An arrival recorded in one
 * unit of work must reach that unit's push, and must not be carried into the
 * next one, where it would announce mail that did not arrive there.
 *
 * Drives onWorkerMessage() because it is the public entry point that needs no
 * event object; it runs the same flush as kernel.terminate.
 */
final class JmapPushSubscriberArrivalsTest extends JmapTestCase
{
    public function testAnArrivalReachesTheDispatcherOnceAndDoesNotLeakIntoTheNextFlush(): void
    {
        $container = self::getContainer();
        $state     = $container->get(StateManager::class);
        $accountId = (int) $this->account->id;

        $subscription = PushSubscription::webPush($this->user, 'pwa-device', 'https://push.example.test/p');
        $subscription->markArrivalsOnly();
        $subscription->verify((string) $subscription->verificationCode);
        $this->em->persist($subscription);
        $this->em->flush();

        $sender     = new RecordingArrivalsSender(PushTransport::WebPush);
        $subscriber = new JmapPushSubscriber(
            $state,
            new PushDispatcher(
                $container->get(PushSubscriptionRepository::class),
                $container->get(UserRepository::class),
                new PushSenderRegistry([$sender]),
                $container->get(StateChangeBuilder::class),
                new NullLogger(),
            ),
            $container->get(MailChangeNotifier::class),
            new NullLogger(),
        );

        $state->recordUpdated($accountId, JmapObjectType::Email, '9');
        $this->em->flush();
        $subscriber->onWorkerMessage();

        self::assertCount(0, $sender->sent, 'an update-only flush does not mark the PWA as arrived');

        $state->recordArrival($accountId);
        $state->recordUpdated($accountId, JmapObjectType::Email, '10');
        $this->em->flush();
        $subscriber->onWorkerMessage();

        self::assertCount(1, $sender->sent, 'presence: the arrival reached the dispatcher');

        $state->recordUpdated($accountId, JmapObjectType::Email, '11');
        $this->em->flush();
        $subscriber->onWorkerMessage();

        self::assertCount(1, $sender->sent, 'the arrival was drained by the flush above and did not leak into this one');
    }
}
