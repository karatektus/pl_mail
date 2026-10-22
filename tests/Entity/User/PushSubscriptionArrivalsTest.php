<?php

declare(strict_types=1);

namespace App\Tests\Entity\User;

use App\Entity\User\PushSubscription;
use App\Entity\User\User;
use PHPUnit\Framework\TestCase;

/**
 * The flag is what narrows a row, and the only thing that sets it is the
 * in-app registration. A row made the way PushSubscription/set makes one, by
 * the webPush() factory, must stay on the full stream.
 *
 * This is the entity half of the claim. That WebPushController marks its rows
 * is WebPushSubscribeArrivalsTest's, which posts to the real endpoint with the
 * push client mocked.
 */
final class PushSubscriptionArrivalsTest extends TestCase
{
    public function testAJmapClientsRowIsNotNarrowed(): void
    {
        $subscription = PushSubscription::webPush(new User(), 'a-jmap-client', 'https://push.example.test/c');

        self::assertFalse($subscription->arrivalsOnly, 'a row made by the factory keeps the full stream');
    }

    public function testMarkingARowNarrowsItAndOnlyThatRow(): void
    {
        $user = new User();
        $marked = PushSubscription::webPush($user, 'pwa-device', 'https://push.example.test/p');
        $other = PushSubscription::webPush($user, 'a-jmap-client', 'https://push.example.test/c');

        $marked->markArrivalsOnly();

        self::assertTrue($marked->arrivalsOnly, 'presence: the marked row is narrowed');
        self::assertFalse($other->arrivalsOnly, 'the other row is not narrowed by marking this one');
    }
}
