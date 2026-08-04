<?php

declare(strict_types=1);

namespace App\Controller\Webhook;

use App\Entity\Calendar\Calendar;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Repository\Calendar\CalendarRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives Microsoft Graph notifications for a mirrored calendar's events.
 *
 * A separate route from GraphNotificationController's, and a separate class,
 * although both speak the same protocol. The reason is what a notification is
 * looked up against: mail resolves a subscriptionId to an Account, this
 * resolves one to a Calendar, and one endpoint doing both would have to try one
 * table and then the other — which means an unknown subscription is
 * indistinguishable from a mismatched secret in the half that answered second.
 * Two routes make each lookup exactly one query and each refusal exactly one
 * reason.
 *
 * Unauthenticated by necessity — Graph is the caller — so it is covered by the
 * existing `^/webhook/graph` PUBLIC_ACCESS rule and exempt from CSRF.
 * Authenticity is `clientState`: 256 bits minted per subscription, compared in
 * constant time against the calendar's own.
 *
 * **A notification carries no changes**, only "something in this calendar
 * happened", so this dispatches SyncCalendarMessage and nothing else. The
 * engine reads the delta.
 *
 * Graph expects an answer within a few seconds and retries, then eventually
 * drops the subscription, if it does not get one — which is why nothing here
 * touches a provider and the response is immediate.
 */
final class GraphCalendarNotificationController extends AbstractController
{
    /** How long one unknown subscription stays quiet after it is logged. */
    private const int UNKNOWN_SUBSCRIPTION_QUIET_FOR = 3600;

    public function __construct(
        private readonly CalendarRepository  $calendars,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface     $logger,
        #[Autowire(service: 'app.notice_throttle')]
        private readonly CacheItemPoolInterface $noticeCache,
    ) {}

    #[Route('/webhook/graph/calendar', name: 'app_graph_calendar_notification', methods: ['POST'])]
    public function notify(Request $request): Response
    {
        // First, before anything else: Graph validates a notification URL by
        // POSTing ?validationToken=… and expecting the raw token back as
        // text/plain within ten seconds, synchronously inside the create call.
        // A validation request carries no body and no clientState, so every
        // check below would refuse it and the subscription would never exist.
        $validationToken = $request->query->get('validationToken');

        if (null !== $validationToken) {
            return new Response((string) $validationToken, Response::HTTP_OK, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $seen = [];

        foreach ($this->notificationsIn($request) as $notification) {
            $calendar = $this->authenticate($notification);

            if (null === $calendar) {
                continue;
            }

            // Graph batches, and several changes to one calendar arrive in a
            // single POST. The sync is delta-driven and idempotent, so a job
            // per notification would be harmless — but pointless, and this
            // queue also carries mail.
            $seen[(int) $calendar->id] = true;
        }

        foreach (array_keys($seen) as $calendarId) {
            $this->bus->dispatch(new SyncCalendarMessage($calendarId));
        }

        // 202: accepted for processing, which is what it is.
        return new Response('', Response::HTTP_ACCEPTED);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The calendar one notification names, or null when it cannot be trusted.
     *
     * @param array<string,mixed> $notification
     */
    private function authenticate(array $notification): ?Calendar
    {
        $subscriptionId = trim((string) ($notification['subscriptionId'] ?? ''));
        $clientState    = (string) ($notification['clientState'] ?? '');

        if ('' === $subscriptionId || '' === $clientState) {
            return null;
        }

        $calendar = $this->calendars->findOneByPushChannel($subscriptionId);

        if (null === $calendar || false === $calendar->hasPushChannel()) {
            $this->logUnknownSubscription($subscriptionId);

            return null;
        }

        if (false === hash_equals((string) $calendar->pushSecret, $clientState)) {
            $this->logger->warning('GraphCalendarNotification: clientState mismatch, ignoring', [
                'calendarId' => $calendar->id,
            ]);

            return null;
        }

        if (false === $calendar->isSynced()) {
            return null;
        }

        return $calendar;
    }

    /**
     * A registration Microsoft still has and plMail no longer does. Nothing can
     * be done about it from here — without the row there is no account to
     * cancel it with — so it is logged once an hour per subscription rather
     * than once per notification, which on a busy calendar is several a second.
     */
    private function logUnknownSubscription(string $subscriptionId): void
    {
        $item = $this->noticeCache->getItem('calendar.unknown_subscription.' . sha1($subscriptionId));

        if (true === $item->isHit()) {
            return;
        }

        $this->logger->warning('GraphCalendarNotification: unknown subscription', [
            'subscriptionId' => $subscriptionId,
            'note'           => 'Microsoft still holds this registration; it stops when it expires.',
        ]);

        $item->set(true)->expiresAfter(self::UNKNOWN_SUBSCRIPTION_QUIET_FOR);
        $this->noticeCache->save($item);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function notificationsIn(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        if (false === is_array($decoded)) {
            return [];
        }

        $value = $decoded['value'] ?? null;

        if (false === is_array($value)) {
            return [];
        }

        $notifications = [];

        foreach ($value as $notification) {
            if (true === is_array($notification)) {
                $notifications[] = $notification;
            }
        }

        return $notifications;
    }
}
