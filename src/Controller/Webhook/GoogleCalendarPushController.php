<?php

declare(strict_types=1);

namespace App\Controller\Webhook;

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
 * Receives Google Calendar watch-channel notifications.
 *
 * Unauthenticated by necessity — Google is the caller, not a logged-in user —
 * so it is allowed anonymously in security.yaml and must be read as an endpoint
 * anybody on the internet can POST to. Authenticity is the channel token: 256
 * bits minted per registration, stored on the calendar, sent back by Google in
 * `X-Goog-Channel-Token`, compared here in constant time. A POST that does not
 * carry it is refused and logged, exactly as GmailPushController refuses one
 * without its shared secret — because without that check this endpoint is a
 * free remote trigger for arbitrary Google API work on somebody else's account.
 *
 * **A notification says nothing about what changed.** Google sends headers and
 * an empty body; there is no event id, no list of changes, not even which
 * direction. So this does exactly one thing — dispatch SyncCalendarMessage for
 * the calendar the channel belongs to — and every decision about what actually
 * changed stays in the sync engine, which reads a delta and already works.
 *
 * **The first notification is a handshake, not a change.** Google POSTs
 * `X-Goog-Resource-State: sync` the moment a channel is created, meaning only
 * "the channel is open". Syncing on it would put a full calendar read in the
 * queue for every registration and every hourly renewal across the install, for
 * a notification that by definition reports nothing.
 *
 * The work is dispatched rather than done here, like every other webhook in
 * this directory: Google gives a callback a few seconds and stops delivering to
 * an endpoint that repeatedly fails or hangs.
 */
final class GoogleCalendarPushController extends AbstractController
{
    /** How long one unknown channel stays quiet after it is logged. */
    private const int UNKNOWN_CHANNEL_QUIET_FOR = 3600;

    public function __construct(
        private readonly CalendarRepository  $calendars,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface     $logger,
        #[Autowire(service: 'app.notice_throttle')]
        private readonly CacheItemPoolInterface $noticeCache,
    ) {}

    #[Route('/webhook/google/calendar', name: 'app_google_calendar_push', methods: ['POST'])]
    public function notify(Request $request): Response
    {
        $channelId = trim((string) $request->headers->get('X-Goog-Channel-ID', ''));
        $token     = (string) $request->headers->get('X-Goog-Channel-Token', '');

        if ('' === $channelId) {
            $this->logger->warning('GoogleCalendarPush: notification with no channel id');

            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $calendar = $this->calendars->findOneByPushChannel($channelId);

        if (null === $calendar || false === $calendar->hasPushChannel()) {
            $this->logUnknownChannel($channelId);

            // 404 rather than a quiet 204, and deliberately: a channel plMail
            // no longer holds is one Google keeps delivering for up to a week,
            // and repeated errors are the only signal that makes it stop. There
            // is nothing else that can be done from this side — cancelling a
            // channel needs the resourceId, which went with the row.
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if (false === hash_equals((string) $calendar->pushSecret, $token)) {
            $this->logger->warning('GoogleCalendarPush: rejected a notification with a bad or missing channel token', [
                'calendarId' => $calendar->id,
            ]);

            return new Response('', Response::HTTP_FORBIDDEN);
        }

        // Checked after the token, not before: a forged POST claiming to be a
        // handshake must be refused like any other, and answering it 204 before
        // the secret is looked at would be a way to probe which channel ids
        // exist.
        $state = strtolower((string) $request->headers->get('X-Goog-Resource-State', ''));

        if ('sync' === $state) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        if (false === $calendar->isSynced()) {
            // The channel outlived the mirroring: the calendar was unsubscribed
            // and the row kept. Nothing to sync, and dispatching would fail in
            // the handler instead of here.
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $this->bus->dispatch(new SyncCalendarMessage((int) $calendar->id));

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * A channel Google still has and plMail does not — a calendar unsubscribed,
     * a database restored, push re-registered. It arrives once per change in
     * that calendar until it lapses, and no admin can act on it, so it is
     * logged once an hour per channel rather than once per notification. The
     * mail side learned this the hard way; see GraphNotificationController.
     */
    private function logUnknownChannel(string $channelId): void
    {
        $item = $this->noticeCache->getItem('calendar.unknown_channel.' . sha1($channelId));

        if (true === $item->isHit()) {
            return;
        }

        $this->logger->warning('GoogleCalendarPush: notification for an unknown channel', [
            'channelId' => $channelId,
            'note'      => 'Google still holds this channel; it stops when it expires.',
        ]);

        $item->set(true)->expiresAfter(self::UNKNOWN_CHANNEL_QUIET_FOR);
        $this->noticeCache->save($item);
    }
}
