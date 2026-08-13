<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Domain\Enum\PushHealth;
use App\Domain\Interface\PushSubscriptionManagerInterface;
use App\Entity\Mail\Account;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Gmail side of the provider-agnostic push contract.
 *
 * Wraps GmailWatchService rather than replacing it — that class still owns the
 * users.watch / users.stop calls and the expiry bookkeeping. What lives here is
 * everything the shared contract needs on top: the pushEnabled gate,
 * configuration checks, renewal thresholds and health.
 *
 * ── The important asymmetry with Graph ────────────────────────────────────
 * Graph validates its notification URL synchronously at subscribe time, so a
 * broken endpoint fails immediately and visibly. Gmail cannot: users.watch only
 * registers interest in a Pub/Sub TOPIC, and the push SUBSCRIPTION forwarding
 * that topic to /gmail/push lives in Google Cloud, outside plMail entirely. So
 * watch() returns a happy 200 while nothing is ever delivered.
 *
 * That is what PushHealth::Degraded is for, and why gmailLastPushAt is the
 * signal: a watch registered well over an hour ago that has never delivered
 * almost certainly means the Cloud-side push subscription is missing or
 * pointing somewhere else.
 */
final readonly class GmailPushSubscriptionManager implements PushSubscriptionManagerInterface
{
    /** Renew once the watch has less than this left. Google caps watches at 7 days. */
    private const string RENEW_THRESHOLD = '+24 hours';

    /**
     * How far behind a history advance the last push may sit before the push is
     * judged to have missed it.
     *
     * ── Why this number and not another ──────────────────────────────────────
     * It is not a tolerance for silence; it is the period of the sweep that
     * produces the comparison. `app:mail:sync` runs every fifteen minutes on
     * the quarter hour (MaintenanceSchedule), so a poll learns of a change up
     * to fifteen minutes after it happened. A push that is working announces
     * the same change within seconds, which means gmailLastPushAt is already at
     * or after the change when the poll gets there and the difference below is
     * negative.
     *
     * A history advance recorded MORE than one whole sweep period after the
     * last push therefore cannot be a push that was merely slow — it is a
     * change the poll found and the push never mentioned. Widening this beyond
     * the sweep period buys nothing; narrowing it below would start counting
     * ordinary scheduling jitter as evidence.
     *
     * This replaces a 36-hour silence threshold rather than shortening it. No
     * amount of elapsed quiet distinguishes a dead push from a quiet mailbox,
     * so the quantity being measured is the wrong one; this measures whether
     * mail arrived that push failed to announce, which is the actual question.
     */
    private const string PUSH_LAG_GRACE = '+15 minutes';

    /**
     * Grace period after registering, before a missing push means anything. A
     * watch created five minutes ago having delivered nothing is entirely
     * normal, and Google itself does not promise instant first delivery.
     */
    private const string STARTUP_GRACE = '-2 hours';

    public function __construct(
        private GmailWatchService      $watchService,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
        private GmailPushSettings      $pushSettings,
    ) {}

    public function supports(Account $account): bool
    {
        return $account->isGmail();
    }

    public function isConfigured(): bool
    {
        return '' !== trim(($this->pushSettings->topic() ?? ''));
    }

    public function messageKey(): string
    {
        return 'gmail';
    }

    public function subscribe(Account $account): bool
    {
        if (true !== $account->pushEnabled) {
            return false;
        }

        if (false === $this->isConfigured()) {
            $this->logger->warning('GmailPushSubscriptionManager: GMAIL_PUBSUB_TOPIC is not set, staying on polling', [
                'accountId' => $account->id,
            ]);

            return false;
        }

        try {
            $this->watchService->watch($account);
        } catch (\Throwable $e) {
            // The exception message carries only the status line; Google puts the
            // reason a watch was rejected — bad topicName, missing publisher
            // grant — in the response body, so it has to be pulled out here.
            $this->logger->error('GmailPushSubscriptionManager: watch failed, falling back to polling', [
                'accountId' => $account->id,
                'topic'     => ($this->pushSettings->topic() ?? ''),
                'error'     => $e->getMessage(),
                'body'      => $e instanceof HttpExceptionInterface
                    ? $e->getResponse()->getContent(false)
                    : null,
            ]);

            return false;
        }

        return true;
    }

    /**
     * users.watch is idempotent — calling it on an already-watched mailbox just
     * resets the expiry window — so renewal and subscription are the same call.
     */
    public function renew(Account $account): bool
    {
        return $this->subscribe($account);
    }

    public function unsubscribe(Account $account): void
    {
        // stopWatch already swallows remote errors and clears local state.
        $this->watchService->stopWatch($account);

        // Silence is meaningless once there is no watch; clearing it prevents a
        // stale timestamp from making a freshly re-enabled account look healthy.
        $account->gmailLastPushAt = null;

        // And the other half of that comparison goes with it. Left behind, an
        // old history advance would sit there newer than a null last-push and
        // report a brand-new watch as broken the moment push is turned back on.
        $account->gmailHistoryAdvancedAt = null;

        $this->em->flush();
    }

    public function needsRenewal(Account $account): bool
    {
        if (true !== $account->pushEnabled) {
            return false;
        }

        $expiry = $account->gmailWatchExpiry;

        if (null === $expiry) {
            return true;
        }

        return $expiry <= new DateTimeImmutable(self::RENEW_THRESHOLD);
    }

    public function expiresAt(Account $account): ?DateTimeImmutable
    {
        return $account->gmailWatchExpiry;
    }

    /**
     * Which of the three things is true of this account's push.
     *
     * ── The order matters, and so does what is NOT asked ─────────────────────
     * Expiry is settled first because it is the only certainty available: a
     * watch past its expiry is not delivering, full stop, and no amount of
     * reasoning about mailbox activity is needed or wanted. It is also the
     * failure with a cause the user cannot otherwise discover — renewal is a
     * daily scheduled command, and a scheduler that has stopped firing produces
     * no error, no log line and no clue.
     *
     * Only then is delivery judged, and never by elapsed time. The question is
     * not "has it been quiet for long enough to worry" — that question has no
     * correct threshold, because the same silence means a dead push on a busy
     * mailbox and an ordinary week on a quiet one. The question is whether mail
     * arrived that push should have announced and did not, which
     * gmailHistoryAdvancedAt answers as a fact about this account.
     *
     * The consequence worth stating plainly: an account whose mailbox has not
     * changed reports Active however long it has been silent. That is correct.
     * A push that has delivered nothing because there was nothing to deliver is
     * not broken, and telling somebody it is, is how a health page loses the
     * credibility it needs for the day something really has failed.
     */
    public function health(Account $account): PushHealth
    {
        if (true !== $account->pushEnabled) {
            return PushHealth::Inactive;
        }

        $expiry = $account->gmailWatchExpiry;

        if (null === $expiry) {
            return PushHealth::Inactive;
        }

        // Certain, and true at any hour. Nothing below can override it.
        if ($expiry <= new DateTimeImmutable()) {
            return PushHealth::Lapsed;
        }

        $advanced = $account->gmailHistoryAdvancedAt;

        if (null === $advanced) {
            // No evidence either way. The mailbox has not been seen to change
            // since this column started being written, so there is nothing push
            // can be accused of missing.
            return PushHealth::Active;
        }

        $lastPush = $account->gmailLastPushAt;

        if (null === $lastPush) {
            // Never delivered, and the mailbox HAS changed — so there was
            // something to deliver. Only meaningful once the watch has had time
            // to fire; watches are registered with a 7-day expiry, so working
            // backwards from it gives the registration time without storing a
            // second timestamp.
            $registeredAt = $expiry->modify('-7 days');

            if ($registeredAt >= new DateTimeImmutable(self::STARTUP_GRACE)) {
                return PushHealth::Active;
            }

            return PushHealth::Degraded;
        }

        // The mailbox changed more than a full sweep period after the last push
        // announced anything: a change push missed. See PUSH_LAG_GRACE.
        if ($advanced > $lastPush->modify(self::PUSH_LAG_GRACE)) {
            return PushHealth::Degraded;
        }

        return PushHealth::Active;
    }
}
