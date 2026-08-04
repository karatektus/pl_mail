<?php

declare(strict_types=1);

namespace App\Service\Calendar\Push;

use App\Domain\Enum\PushHealth;
use App\Domain\Interface\CalendarPushSubscriptionManagerInterface;
use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Service\Calendar\Sync\Google\GoogleCalendarApiClient;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Google Calendar watch channels — the push side of GoogleCalendarSyncDriver.
 *
 * A channel is a plain webhook: POST `id`, `type: web_hook`, `address`, a
 * `token` and a ttl to the calendar's `events/watch`, and Google POSTs to that
 * address whenever anything in the calendar changes. **This is not the path
 * Gmail push takes**, and the difference is the whole reason this class exists
 * rather than a call into GmailPushSubscriptionManager: Gmail's users.watch
 * publishes to a Cloud Pub/Sub topic, so it needs GMAIL_PUBSUB_TOPIC, a Cloud
 * project with a push subscription in it, and a publisher grant. None of that
 * applies here. An install with no Pub/Sub at all can have calendar push.
 *
 * ── The self-hosting constraint, stated plainly ────────────────────────────
 *
 * **Google will only deliver to a domain verified in the Cloud project that
 * owns the OAuth client.** Verify it in Search Console, then add it under
 * Domain verification in the Cloud console. Until that is done, `events.watch`
 * is refused — the failure is at registration, not at delivery, so it is
 * visible in the log rather than silent, but it is the one thing about this
 * feature a self-hoster cannot discover from inside plMail. Microsoft requires
 * no equivalent step, which is why only this half of the admin note mentions
 * one.
 *
 * ── What a notification contains ──────────────────────────────────────────
 *
 * Nothing. A notification carries headers naming the channel and a resource
 * state, and no description of what changed — so the webhook dispatches
 * SyncCalendarMessage and every decision stays in the sync engine, which
 * already knows how to read a delta. The first notification after registering
 * is a `sync` handshake and means only "the channel is open"; see
 * GoogleCalendarPushController.
 *
 * ── Renewal ───────────────────────────────────────────────────────────────
 *
 * There is no extend operation: a channel is renewed by opening a new one and
 * stopping the old. Renewal is driven off the `expiration` Google returned and
 * never off TTL_SECONDS — Google is free to grant less than was asked for, and
 * a local constant that disagrees with it is a channel that dies quietly a day
 * before anything tries to replace it.
 *
 * Failure at any point returns false and leaves the calendar on the sweep. That
 * is the contract, not a fallback: a calendar that polls is a working calendar
 * fifteen minutes behind, and there is no deployment in which refusing to sync
 * because push could not be registered would be the better answer.
 */
final readonly class GoogleCalendarPushManager implements CalendarPushSubscriptionManagerInterface
{
    /**
     * How long a channel is asked to live.
     *
     * A week, which is Google Calendar's own maximum. Asking for the maximum
     * costs nothing — the value that gets stored is whatever Google grants, and
     * every renewal is a fresh registration anyway — and it keeps the number of
     * re-registrations, each of which is two API calls, at one per calendar per
     * week rather than one per day.
     */
    private const string TTL_SECONDS = '604800';

    /**
     * Re-register once the channel has less than this left.
     *
     * A day, matching GmailPushSubscriptionManager's threshold against its
     * seven-day watches. The renewal sweep runs hourly, so a day of headroom
     * survives twenty-three consecutive missed runs.
     */
    private const string RENEW_THRESHOLD = '+24 hours';

    public function __construct(
        private GoogleCalendarApiClient $api,
        private PushCallbackUrl         $callback,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
    ) {}

    /**
     * Google's own words for "this calendar can never be watched", remembered
     * so the sweep stops asking.
     *
     * Some calendars Google serves are generated rather than stored — a
     * country's holidays, the birthdays drawn from Contacts, week numbers — and
     * events.watch answers 400 pushNotSupportedForRequestedResource for every
     * one of them. That is a fact about the calendar and it will not change, so
     * retrying hourly buys nothing and writes a warning an hour, for ever,
     * about something nobody can act on. This codebase already rate-limits one
     * such log (an orphaned Graph subscription); the better answer here is to
     * ask once and believe the answer.
     */
    private const string REFUSED_SETTING = 'push.unsupported';

    /** The reason Google returns for a resource that cannot carry a channel. */
    private const string REFUSED_REASON = 'pushNotSupportedForRequestedResource';

    /**
     * False for a calendar Google has already refused to watch, so it is not
     * merely skipped at registration but never offered to the sweep at all —
     * the registry answers "no manager" and the calendar reads as polled,
     * which is exactly what it is.
     */
    public function supports(Calendar $calendar): bool
    {
        if (true === $calendar->getSetting(self::REFUSED_SETTING, false)) {
            return false;
        }

        return null !== $this->googleAccountOf($calendar);
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
     * Unlike Gmail, a Google Calendar channel cannot be registered against an
     * endpoint that is not reachable: the address has to be on a domain the
     * Cloud project has verified, and an unverified one is refused outright. So
     * there is no silent-failure state to detect here and no equivalent of
     * gmailLastPushAt — a channel that exists is a channel Google accepted, and
     * Degraded means only that it has since lapsed.
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
        $account = $this->googleAccountOf($calendar);

        if (null === $account) {
            return false;
        }

        $remoteId = trim((string) $calendar->remoteId);

        if ('' === $remoteId) {
            return false;
        }

        if (false === $this->isConfigured()) {
            $this->logger->warning('GoogleCalendarPush: no usable public HTTPS address, staying on polling', [
                'calendarId'    => $calendar->id,
                'publicBaseUrl' => $this->callback->base(),
            ]);

            return false;
        }

        // Stop the previous channel first. Google happily runs several channels
        // over one calendar, and an orphan goes on delivering for its whole
        // week — to an endpoint that now holds a different secret, so every one
        // of those notifications is refused and logged as a forgery.
        $this->unsubscribe($calendar);

        $channelId = bin2hex(random_bytes(16));
        $token     = bin2hex(random_bytes(32));

        try {
            $channel = $this->api->watchChannel($account, $remoteId, [
                'id'      => $channelId,
                'type'    => 'web_hook',
                'address' => $this->callback->of('app_google_calendar_push'),
                'token'   => $token,
                // ttl lives under params, not at the top level. Sent as a
                // string of seconds, which is the form the API documents.
                'params'  => ['ttl' => self::TTL_SECONDS],
            ]);
        } catch (\Throwable $e) {
            // A calendar Google generates rather than stores — holidays,
            // birthdays, week numbers — cannot carry a channel and never will.
            // Recorded rather than retried, and logged once at info: it is not
            // a fault, and an hourly warning about an unfixable fact is how the
            // interesting lines stop being read.
            if (true === str_contains($e->getMessage(), self::REFUSED_REASON)) {
                $calendar->setSetting(self::REFUSED_SETTING, true);
                $this->em->flush();

                $this->logger->info('GoogleCalendarPush: this calendar cannot be watched, polling it from now on', [
                    'calendarId' => $calendar->id,
                ]);

                return false;
            }

            // Warning, not error: on a self-hosted install the overwhelmingly
            // likely cause is an unverified callback domain, which is a
            // deployment fact rather than a fault, and the calendar keeps
            // syncing on the sweep either way.
            $this->logger->warning('GoogleCalendarPush: watch failed, staying on polling', [
                'calendarId' => $calendar->id,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }

        $resourceId = trim((string) ($channel['resourceId'] ?? ''));

        if ('' === $resourceId) {
            // A channel that cannot be stopped is worse than no channel: it
            // delivers for a week and nothing here can cancel it. Better to
            // record nothing and poll than to record half a registration.
            $this->logger->warning('GoogleCalendarPush: watch answered without a resourceId, so the channel cannot be stopped later', [
                'calendarId' => $calendar->id,
            ]);

            return false;
        }

        $calendar->pushChannelId  = $channelId;
        $calendar->pushResourceId = $resourceId;
        $calendar->pushSecret     = $token;
        $calendar->pushExpiresAt  = $this->expirationOf($channel);

        $this->em->flush();

        $this->logger->info('GoogleCalendarPush: channel registered', [
            'calendarId' => $calendar->id,
            'expiresAt'  => $calendar->pushExpiresAt?->format(\DATE_ATOM),
        ]);

        return true;
    }

    /**
     * A channel cannot be extended, so renewal is registration — which stops
     * the old channel on the way in. Written as a delegation rather than as an
     * alias so the difference from Graph, where renewal really is a PATCH,
     * stays visible at the call site.
     */
    public function renew(Calendar $calendar): bool
    {
        return $this->subscribe($calendar);
    }

    public function unsubscribe(Calendar $calendar): void
    {
        $channelId  = (string) $calendar->pushChannelId;
        $resourceId = (string) $calendar->pushResourceId;
        $account    = $this->googleAccountOf($calendar);

        if ('' === $channelId || '' === $resourceId || null === $account) {
            // Still clear whatever half-written state is there: a secret with
            // no channel behind it can only ever verify a notification for a
            // channel plMail no longer owns.
            $calendar->clearPushChannel();
            $this->em->flush();

            return;
        }

        try {
            $this->api->stopChannel($account, $channelId, $resourceId);
        } catch (\Throwable $e) {
            // Swallowed by contract. A channel that cannot be stopped lapses
            // within the week, and its notifications are refused in the
            // meantime because the secret goes with it.
            $this->logger->info('GoogleCalendarPush: could not stop the channel, letting it lapse', [
                'calendarId' => $calendar->id,
                'error'      => $e->getMessage(),
            ]);
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

        return $expiresAt <= new DateTimeImmutable(self::RENEW_THRESHOLD);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The Google account this calendar rides, or null when it is not one.
     *
     * Asked of the account rather than of CalendarSource, although the sync
     * driver's supports() uses the DTO. The DTO exists for discovery, where
     * there is no Calendar row yet; here there always is one, and going through
     * it would mean building a throwaway object to read one string. The rule it
     * encodes is the same one and is stated once in each place: OAuth2, and the
     * Google provider — Account::isGmail() is exactly that pair.
     */
    private function googleAccountOf(Calendar $calendar): ?Account
    {
        // An integration-backed calendar is CalDAV and has no channels at all,
        // whatever account happens to be attached beside it.
        if (null !== $calendar->integration) {
            return null;
        }

        $account = $calendar->account;

        if (null === $account || false === $account->isGmail()) {
            return null;
        }

        return $account;
    }

    /**
     * Google's `expiration`, which is milliseconds since the epoch in a string.
     *
     * Null when it is absent or not a number, and null is honest: needsRenewal()
     * reads a missing expiry as "renew now", so an unparseable one costs one
     * extra registration per sweep rather than a channel nobody notices dying.
     *
     * @param array<string,mixed> $channel
     */
    private function expirationOf(array $channel): ?DateTimeImmutable
    {
        $raw = trim((string) ($channel['expiration'] ?? ''));

        if ('' === $raw || false === ctype_digit($raw)) {
            return null;
        }

        return new DateTimeImmutable('@' . intdiv((int) $raw, 1000));
    }
}
