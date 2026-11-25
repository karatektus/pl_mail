<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Jmap\Push\PushSenderRegistry;
use App\Repository\User\PushSubscription\PushSubscriptionRepository;

/**
 * Sending one notification to every device a person has.
 *
 * The sending half of PushAlertChannel, taken out when task reminders needed it:
 * a reminder has nothing else in common with a calendar alert, and DueAlert is tied
 * to CalendarEvent. Both channels' reasoning about what a payload may carry is in
 * PushAlertChannel's docblock.
 */
final readonly class UserPushNotifier
{
    public function __construct(
        private PushSubscriptionRepository $subscriptions,
        private PushSenderRegistry         $senders,
    ) {}

    /**
     * @param array<string, mixed> $payload
     *
     * @return bool|null whether any device took it; null when there was nowhere to
     *                   send it (no transport, or a user with no devices), which is
     *                   an ordinary state and not worth a warning, unlike devices
     *                   that all refused
     */
    public function notify(int $userId, array $payload): ?bool
    {
        // Any transport, not Web Push specifically. An install that runs Firebase
        // and no VAPID keys still has devices to remind.
        if (false === $this->senders->anyConfigured()) {
            return null;
        }

        $devices = $this->subscriptions->findDeliverableForUser($userId);

        if ([] === $devices) {
            return null;
        }

        $delivered = false;

        foreach ($devices as $device) {
            $sender = $this->senders->for($device);

            if (null === $sender || false === $sender->isConfigured()) {
                continue;
            }

            // Every device, and an OR rather than an AND: one phone with a dead
            // endpoint must not make a reminder that reached the laptop count as
            // undelivered. The senders already retire the endpoints that keep failing.
            $delivered = $sender->send($device, $payload) || $delivered;
        }

        return $delivered;
    }
}
