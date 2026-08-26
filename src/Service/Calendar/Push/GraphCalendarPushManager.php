<?php

declare(strict_types=1);

namespace App\Service\Calendar\Push;

use App\Domain\Enum\PushHealth;
use App\Domain\Interface\CalendarPushSubscriptionManagerInterface;
use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Service\OAuth\OAuthTokenManager;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Microsoft Graph change subscriptions over one calendar's events.
 *
 * The same mechanism GraphSubscriptionManager already runs for mail, pointed at
 * `me/calendars/{id}/events` instead of at the mailbox — and that resource is
 * precisely why this is a per-calendar registration rather than a per-account
 * one: Graph subscribes to a resource, and six mirrored calendars are six
 * resources, six secrets and six expiries.
 *
 * ── The validation handshake ──────────────────────────────────────────────
 *
 * Graph POSTs a `validationToken` to the notification URL and expects the raw
 * token back as text/plain within seconds, synchronously, inside the create
 * call. So a deployment that is not actually reachable from the internet fails
 * here — loudly, at registration, and harmlessly: the calendar stays on the
 * fifteen-minute sweep. That is the same "loud but harmless" property the mail
 * subscriptions have, and it is worth more than it sounds, because the
 * alternative is a registration that succeeds and never delivers.
 *
 * ── Lifetime ──────────────────────────────────────────────────────────────
 *
 * Graph caps a calendar-events subscription at 4230 minutes, just under three
 * days — the same ceiling as messages. Renewal is a PATCH of expirationDateTime
 * rather than a fresh subscription, which is the one real difference from the
 * Google side, and the expiry stored is the one Graph answered with rather than
 * the one asked for.
 *
 * ── No client class ───────────────────────────────────────────────────────
 *
 * GraphCalendarSyncDriver states the reason for the absence of a
 * GraphCalendarClient beside it and the same reasoning holds here, with one
 * addition: nothing in this class needs the driver's failure vocabulary. Push
 * has exactly two outcomes — registered, or stay on polling — so a 403 that
 * means a missing scope and a 503 that means Microsoft is having a bad morning
 * are answered identically, and translating them into distinct exceptions in
 * order to discard the distinction would be ceremony.
 */
final readonly class GraphCalendarPushManager implements CalendarPushSubscriptionManagerInterface
{
    private const string SUBSCRIPTIONS = 'https://graph.microsoft.com/v1.0/subscriptions';

    /**
     * Graph caps a calendar-events subscription just under three days (4230
     * minutes). Asked for slightly inside that, because the ceiling is checked
     * against Microsoft's clock and a request for the exact maximum is refused
     * outright when the two disagree by a minute.
     */
    private const int LIFETIME_MINUTES = 4200;

    /**
     * Renew once the remaining lifetime drops below this.
     *
     * Twelve hours, matching GraphSubscriptionManager. With an hourly sweep
     * that is twelve chances to renew before delivery stops, against a
     * subscription Microsoft will not revive once it has lapsed.
     */
    private const int RENEW_THRESHOLD_MINUTES = 720;

    /**
     * Everything that can happen to an event, spelled out. Graph has no "all"
     * and silently subscribes to nothing useful if the list is wrong — omitting
     * `deleted` in particular produces a calendar that grows but never shrinks.
     */
    private const string CHANGE_TYPE = 'created,updated,deleted';

    public function __construct(
        private HttpClientInterface    $httpClient,
        private OAuthTokenManager      $tokens,
        private PushCallbackUrl        $callback,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    public function supports(Calendar $calendar): bool
    {
        return null !== $this->microsoftAccountOf($calendar);
    }

    public function isConfigured(): bool
    {
        return $this->callback->isPubliclyRoutable();
    }

    public function expiresAt(Calendar $calendar): ?DateTimeImmutable
    {
        return $calendar->pushExpiresAt;
    }

    /**
     * A subscription that exists was validated when it was created, so there is
     * no "registered but never delivering" state to detect. Degraded therefore
     * means lapsed, which Graph will not undo and only a fresh subscription
     * repairs.
     */
    public function health(Calendar $calendar): PushHealth
    {
        if (false === $calendar->hasPushChannel()) {
            return PushHealth::Inactive;
        }

        $expiresAt = $calendar->pushExpiresAt;

        if (null === $expiresAt || $expiresAt <= new DateTimeImmutable()) {
            return PushHealth::Degraded;
        }

        return PushHealth::Active;
    }

    public function subscribe(Calendar $calendar): bool
    {
        $account = $this->microsoftAccountOf($calendar);

        if (null === $account) {
            return false;
        }

        $remoteId = trim((string) $calendar->remoteId);

        if ('' === $remoteId) {
            return false;
        }

        if (false === $this->isConfigured()) {
            $this->logger->warning('GraphCalendarPush: no usable public HTTPS address, staying on polling', [
                'calendarId'    => $calendar->id,
                'publicBaseUrl' => $this->callback->base(),
            ]);

            return false;
        }

        // Drop the old one first, for the reason the mail manager gives: Graph
        // allows several subscriptions over one resource and an orphan keeps
        // delivering until it lapses — against a clientState that no longer
        // matches, so every one of those is refused and logged.
        $this->unsubscribe($calendar);

        $clientState = bin2hex(random_bytes(32));
        $asked       = new DateTimeImmutable(sprintf('+%d minutes', self::LIFETIME_MINUTES));

        $subscription = $this->call($calendar, $account, 'POST', self::SUBSCRIPTIONS, [
            'changeType'         => self::CHANGE_TYPE,
            'notificationUrl'    => $this->callback->of('app_graph_calendar_notification'),
            'resource'           => sprintf('me/calendars/%s/events', $remoteId),
            'clientState'        => $clientState,
            'expirationDateTime' => $asked->format(\DATE_ATOM),
        ]);

        if (null === $subscription) {
            return false;
        }

        $subscriptionId = trim((string) ($subscription['id'] ?? ''));

        if ('' === $subscriptionId) {
            $this->logger->warning('GraphCalendarPush: subscription created without an id, so it could never be renewed or cancelled', [
                'calendarId' => $calendar->id,
            ]);

            return false;
        }

        $calendar->pushChannelId  = $subscriptionId;
        $calendar->pushResourceId = null;
        $calendar->pushSecret     = $clientState;
        $calendar->pushExpiresAt  = $this->expirationOf($subscription) ?? $asked;

        $this->em->flush();

        $this->logger->info('GraphCalendarPush: subscribed', [
            'calendarId' => $calendar->id,
            'expiresAt'  => $calendar->pushExpiresAt->format(\DATE_ATOM),
        ]);

        return true;
    }

    /**
     * Extend the subscription in place, and fall back to a fresh one.
     *
     * The fallback is not defensive padding: a lapsed subscription cannot be
     * PATCHed back to life, and Graph answers a PATCH against one that is gone
     * with a 404 that would otherwise leave the calendar polling forever while
     * a renewal ran hourly and failed hourly.
     */
    public function renew(Calendar $calendar): bool
    {
        $subscriptionId = trim((string) $calendar->pushChannelId);
        $account        = $this->microsoftAccountOf($calendar);

        if ('' === $subscriptionId || null === $account) {
            return $this->subscribe($calendar);
        }

        $asked = new DateTimeImmutable(sprintf('+%d minutes', self::LIFETIME_MINUTES));

        $subscription = $this->call(
            $calendar,
            $account,
            'PATCH',
            sprintf('%s/%s', self::SUBSCRIPTIONS, rawurlencode($subscriptionId)),
            ['expirationDateTime' => $asked->format(\DATE_ATOM)],
        );

        if (null === $subscription) {
            $calendar->clearPushChannel();
            $this->em->flush();

            return $this->subscribe($calendar);
        }

        $calendar->pushExpiresAt = $this->expirationOf($subscription) ?? $asked;
        $this->em->flush();

        return true;
    }

    public function unsubscribe(Calendar $calendar): void
    {
        $subscriptionId = trim((string) $calendar->pushChannelId);
        $account        = $this->microsoftAccountOf($calendar);

        if ('' !== $subscriptionId && null !== $account) {
            // Errors are swallowed by contract: a subscription that cannot be
            // deleted lapses within three days, and refusing to unsubscribe a
            // calendar over it would be the worse outcome.
            $this->call(
                $calendar,
                $account,
                'DELETE',
                sprintf('%s/%s', self::SUBSCRIPTIONS, rawurlencode($subscriptionId)),
                null,
            );
        }

        $calendar->clearPushChannel();
        $this->em->flush();
    }

    public function needsRenewal(Calendar $calendar): bool
    {
        if (false === $calendar->hasPushChannel()) {
            return true;
        }

        $expiresAt = $calendar->pushExpiresAt;

        if (null === $expiresAt) {
            return true;
        }

        return $expiresAt <= new DateTimeImmutable(sprintf('+%d minutes', self::RENEW_THRESHOLD_MINUTES));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function microsoftAccountOf(Calendar $calendar): ?Account
    {
        if (null !== $calendar->integration) {
            return null;
        }

        $account = $calendar->account;

        if (null === $account || false === $account->isMicrosoft()) {
            return null;
        }

        return $account;
    }

    /**
     * One call to the subscriptions endpoint, or null if anything at all went
     * wrong.
     *
     * Null rather than an exception, and every failure caught, because of what
     * the callers do with the answer: there is no recovery here beyond "leave
     * the calendar polling", and letting a token refusal or a transport error
     * out would take down whatever ran the sweep — a console command walking
     * every calendar in the install, where one unreachable tenant must not stop
     * the other nine calendars getting push.
     *
     * @param array<string,string>|null $payload
     *
     * @return array<string,mixed>|null
     */
    private function call(Calendar $calendar, Account $account, string $method, string $url, ?array $payload): ?array
    {
        try {
            $options = ['auth_bearer' => $this->tokens->getValidAccessToken($account)];

            if (null !== $payload) {
                $options['json'] = $payload;
            }

            $response = $this->httpClient->request($method, $url, $options);
            $status   = $response->getStatusCode();

            if (200 > $status || 300 <= $status) {
                $this->logger->warning('GraphCalendarPush: Microsoft refused a subscription call, staying on polling', [
                    'calendarId' => $calendar->id,
                    'method'     => $method,
                    'status'     => $status,
                    // The body is where Graph says whether this was a missing
                    // scope, an unreachable notification URL or a bad
                    // expiration, and the status alone says none of it.
                    'body'       => mb_substr($response->getContent(false), 0, 300),
                ]);

                return null;
            }

            // A successful DELETE is 204 with no body at all, and toArray() on
            // that is a decoding error rather than an empty array.
            if (204 === $status) {
                return [];
            }

            return $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('GraphCalendarPush: subscription call failed, staying on polling', [
                'calendarId' => $calendar->id,
                'method'     => $method,
                'error'      => $e->getMessage(),
                'exception'  => $e,
            ]);

            return null;
        }
    }

    /**
     * The expiry Graph granted, which need not be the one requested.
     *
     * @param array<string,mixed> $subscription
     */
    private function expirationOf(array $subscription): ?DateTimeImmutable
    {
        $raw = $subscription['expirationDateTime'] ?? null;

        if (false === is_string($raw) || '' === $raw) {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
