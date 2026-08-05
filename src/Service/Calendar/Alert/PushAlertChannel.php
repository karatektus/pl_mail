<?php

declare(strict_types=1);

namespace App\Service\Calendar\Alert;

use App\Domain\DTO\Calendar\DueAlert;
use App\Domain\Enum\Calendar\AlertAction;
use App\Domain\Interface\AlertChannelInterface;
use App\Jmap\Push\WebPushSender;
use App\Repository\User\PushSubscriptionRepository;
use Psr\Log\LoggerInterface;

/**
 * A display alert, delivered to the devices the user has registered.
 *
 * **Reuses the JMAP push stack rather than building a second one.** The
 * subscriptions, the VAPID keys, the verification handshake, the retirement of
 * dead endpoints — all of that already exists for StateChange delivery and all
 * of it applies unchanged here. A parallel stack would mean a second table of
 * endpoints, a second set of keys to configure, and two places for a browser
 * that revoked its subscription to keep failing.
 *
 * **Unlike a StateChange, this payload carries content, and that is a
 * decision.** A StateChange deliberately says only that a token moved, because
 * the client can fetch the detail and mail bodies have no business in a push
 * service's logs. An alert cannot work that way: a notification that says
 * "something is happening" and makes you open the app to find out what is not a
 * reminder. The payload is encrypted end to end under the subscription's own key
 * (RFC 8291), so the push service sees ciphertext, and what it carries is a
 * title and a time — which the user is about to be shown on their lock screen
 * anyway.
 *
 * A user with no verified subscription is not an error. It is the ordinary state
 * of an install where nobody has granted notification permission, and it answers
 * false so AlertDeliverer can say so once rather than raise something.
 */
final readonly class PushAlertChannel implements AlertChannelInterface
{
    /**
     * The payload's `@type`, matched by the service worker in public/sw.js.
     *
     * Named here because that file is the only other place it appears, and a
     * push whose type nothing recognises is delivered, decrypted and dropped
     * without anything anywhere reporting it.
     */
    public const string PAYLOAD_TYPE = 'CalendarAlert';

    public function __construct(
        private PushSubscriptionRepository $subscriptions,
        private WebPushSender              $sender,
        private AlertMessageBuilder        $wording,
        private LoggerInterface            $logger,
    ) {
    }

    public function supports(AlertAction $action): bool
    {
        return AlertAction::Display === $action;
    }

    public function deliver(DueAlert $due): bool
    {
        if (false === $this->sender->isConfigured()) {
            return false;
        }

        $devices = $this->subscriptions->findDeliverableForUser($due->userId);

        if ([] === $devices) {
            return false;
        }

        $payload = [
            '@type' => self::PAYLOAD_TYPE,
            'title' => $this->wording->title($due),
            'body'  => $this->wording->body($due),
            // Where the notification takes the user. The occurrence's own day,
            // not the series' — an alert about Thursday that opens on Monday is
            // a reminder you then have to go looking for.
            'url'   => sprintf('/calendar/day/%s', $due->startsAt->format('Y-m-d')),
            // Identifies this alert to the worker so a second delivery — a
            // browser replaying a queued push after a wake-up — replaces the
            // notification instead of stacking a duplicate beside it.
            'tag'   => sprintf('%d/%s/%s', $due->eventId, $due->alert->key, $due->recurrenceId->format('Y-m-d\TH:i:s\Z')),
        ];

        $delivered = false;

        foreach ($devices as $device) {
            // Every device, and the result is an OR rather than an AND: one
            // phone with a dead endpoint must not make an alert that reached the
            // laptop count as undelivered. WebPushSender already retires the
            // endpoints that keep failing.
            $delivered = $this->sender->send($device, $payload) || $delivered;
        }

        if (false === $delivered) {
            $this->logger->warning('CalendarAlert: no device accepted the notification', [
                'userId'  => $due->userId,
                'eventId' => $due->eventId,
                'devices' => count($devices),
            ]);
        }

        return $delivered;
    }
}
