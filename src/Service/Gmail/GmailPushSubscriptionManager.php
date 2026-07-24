<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Domain\Enum\PushHealth;
use App\Domain\Interface\PushSubscriptionManagerInterface;
use App\Entity\Account;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
     * How long a registered watch may go without delivering before it is
     * treated as broken. Generous on purpose — a quiet mailbox is not a broken
     * one, and Gmail re-pushes on any change including label edits.
     */
    private const string SILENCE_THRESHOLD = '-36 hours';

    /**
     * Grace period after registering, before silence means anything. A watch
     * created five minutes ago having delivered nothing is entirely normal.
     */
    private const string STARTUP_GRACE = '-2 hours';

    public function __construct(
        private GmailWatchService      $watchService,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
        #[Autowire(env: 'GMAIL_PUBSUB_TOPIC')]
        private string                 $pubSubTopicName,
    ) {}

    public function supports(Account $account): bool
    {
        return $account->isGmail();
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->pubSubTopicName);
    }

    public function subscribe(Account $account): bool
    {
        if (true !== $account->isPushEnabled()) {
            return false;
        }

        if (false === $this->isConfigured()) {
            $this->logger->warning('GmailPushSubscriptionManager: GMAIL_PUBSUB_TOPIC is not set, staying on polling', [
                'accountId' => $account->getId(),
            ]);

            return false;
        }

        try {
            $this->watchService->watch($account);
        } catch (\Throwable $e) {
            $this->logger->error('GmailPushSubscriptionManager: watch failed, falling back to polling', [
                'accountId' => $account->getId(),
                'error'     => $e->getMessage(),
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
        $account->setGmailLastPushAt(null);
        $this->em->flush();
    }

    public function needsRenewal(Account $account): bool
    {
        if (true !== $account->isPushEnabled()) {
            return false;
        }

        $expiry = $account->getGmailWatchExpiry();

        if (null === $expiry) {
            return true;
        }

        return $expiry <= new DateTimeImmutable(self::RENEW_THRESHOLD);
    }

    public function expiresAt(Account $account): ?DateTimeImmutable
    {
        return $account->getGmailWatchExpiry();
    }

    public function health(Account $account): PushHealth
    {
        if (true !== $account->isPushEnabled()) {
            return PushHealth::Inactive;
        }

        $expiry = $account->getGmailWatchExpiry();

        if (null === $expiry) {
            return PushHealth::Inactive;
        }

        // A lapsed watch is not delivering, whatever the flag says.
        if ($expiry <= new DateTimeImmutable()) {
            return PushHealth::Degraded;
        }

        $lastPush = $account->getGmailLastPushAt();

        if (null !== $lastPush) {
            if ($lastPush >= new DateTimeImmutable(self::SILENCE_THRESHOLD)) {
                return PushHealth::Active;
            }

            return PushHealth::Degraded;
        }

        // Never delivered. Only meaningful once the watch has had time to fire;
        // watches are registered with a 7-day expiry, so working backwards from
        // it gives the registration time without storing a second timestamp.
        $registeredAt = $expiry->modify('-7 days');

        if ($registeredAt >= new DateTimeImmutable(self::STARTUP_GRACE)) {
            return PushHealth::Active;
        }

        return PushHealth::Degraded;
    }
}
