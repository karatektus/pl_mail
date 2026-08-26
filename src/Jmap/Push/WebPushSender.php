<?php

declare(strict_types=1);

namespace App\Jmap\Push;

use App\Domain\Enum\PushDeliveryOutcome;
use App\Domain\Enum\PushTransport;
use App\Domain\Interface\PushSenderInterface;
use App\Entity\User\PushSubscription as JmapPushSubscription;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;

/**
 * Delivers JMAP push payloads over Web Push (RFC 8030 transport, RFC 8291
 * payload encryption, RFC 8292 VAPID signing).
 *
 * One implementation covers every platform that speaks the protocol:
 * UnifiedPush distributors on Android, Apple's gateway for an installed PWA on
 * iOS, and the browser push services on desktop. The one that does not is
 * Android's own service, which is FcmSender's subject — the two are separate
 * implementations of PushSenderInterface and share nothing but the JSON.
 *
 * Endpoints that are permanently gone (404/410) are deleted rather than
 * retried — a browser that revokes a subscription answers 410 forever, and
 * keeping it would mean a failing POST on every state change until the heat
 * death of the universe.
 *
 * **Every exit records a PushDelivery**, including the ones that send nothing
 * and the one that deletes the row. See PushDeliveryRecorder for why that
 * happens here rather than in a decorator: the status code and the
 * failed-versus-destroyed distinction exist only inside this method, and the
 * bool it returns has already discarded both.
 */
final class WebPushSender implements PushSenderInterface
{
    /**
     * Consecutive failures tolerated before an endpoint is dropped. Covers a
     * push service having a bad day without keeping dead endpoints forever.
     */
    private const int MAX_FAILURES = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PushDeliveryRecorder $deliveries,
        private readonly LoggerInterface $logger,
        private readonly string $vapidSubject,
        private readonly string $vapidPublicKey,
        private readonly string $vapidPrivateKey,
    ) {
    }

    public function transport(): PushTransport
    {
        return PushTransport::WebPush;
    }

    public function isConfigured(): bool
    {
        return '' !== $this->vapidPublicKey && '' !== $this->vapidPrivateKey;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function send(JmapPushSubscription $subscription, array $payload): bool
    {
        // Taken before the first thing that can cost time, so a recorded
        // latency covers what the device waited for rather than what this
        // method did after the answer arrived.
        $startedAt = microtime(true);

        if (false === $this->isConfigured()) {
            $this->logger->warning('Web Push is not configured; set VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY.');

            $this->deliveries->record($subscription, $payload, PushDeliveryOutcome::Skipped, 'no-vapid-keys', $startedAt);

            return false;
        }

        // Nullable since FCM subscriptions arrived, and they hold a token
        // instead. PushSenderRegistry routes by transport so this cannot
        // normally fire — it is here so a mis-routed row is a logged refusal
        // rather than a TypeError inside the push library.
        if (null === $subscription->url || '' === $subscription->url) {
            $this->logger->error('Web Push: subscription has no endpoint URL', [
                'subscriptionId' => $subscription->id,
                'transport'      => $subscription->transport->value,
            ]);

            $this->deliveries->record($subscription, $payload, PushDeliveryOutcome::Skipped, 'no-endpoint-url', $startedAt);

            return false;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $this->vapidSubject,
                    'publicKey' => $this->vapidPublicKey,
                    'privateKey' => $this->vapidPrivateKey,
                ],
            ]);

            $report = $webPush->sendOneNotification(
                $this->toLibrarySubscription($subscription),
                json_encode($payload, JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Web Push send failed', [
                'subscriptionId' => $subscription->id,
                'error'          => $exception->getMessage(),
                'exception'      => $exception,
            ]);

            $this->recordFailure($subscription, $payload, $exception->getMessage(), $startedAt);

            return false;
        }

        if (true === $report->isSuccess()) {
            $subscription->failureCount = 0;
            $this->em->flush();

            $this->deliveries->record($subscription, $payload, PushDeliveryOutcome::Accepted, $this->statusOf($report), $startedAt);

            return true;
        }

        // isSubscriptionExpired() covers the 404/410 the push services use to
        // say "this endpoint will never work again".
        if (true === $report->isSubscriptionExpired()) {
            $this->logger->info('Push endpoint gone; removing subscription', [
                'subscriptionId' => $subscription->id,
            ]);

            // Before the remove, not after: the record names the device by the
            // subscription's own fields, and reading them off a row that has
            // just been deleted and flushed is reading a detached entity for
            // the one delivery anybody will ever go looking for.
            $this->deliveries->record(
                $subscription,
                $payload,
                PushDeliveryOutcome::SubscriptionDestroyed,
                $this->statusOf($report),
                $startedAt,
            );

            $this->em->remove($subscription);
            $this->em->flush();

            return false;
        }

        $this->logger->warning('Web Push rejected', [
            'subscriptionId' => $subscription->id,
            'reason' => $report->getReason(),
        ]);

        $this->recordFailure($subscription, $payload, $this->statusOf($report), $startedAt);

        return false;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function recordFailure(JmapPushSubscription $subscription, array $payload, ?string $detail, float $startedAt): void
    {
        ++$subscription->failureCount;

        $retired = $subscription->failureCount >= self::MAX_FAILURES;

        // The tenth failure is recorded as a destruction rather than as a
        // failure, because that is what it was: the row is gone by the time
        // anyone reads this, and a log saying "failed" for the attempt that
        // retired the endpoint would leave the disappearance unexplained.
        // Ahead of the remove for the reason the expiry path above states.
        $this->deliveries->record(
            $subscription,
            $payload,
            true === $retired ? PushDeliveryOutcome::SubscriptionDestroyed : PushDeliveryOutcome::Failed,
            $detail,
            $startedAt,
        );

        if (true === $retired) {
            $this->logger->info('Push endpoint failed too often; removing subscription', [
                'subscriptionId' => $subscription->id,
                'failures' => $subscription->failureCount,
            ]);

            $this->em->remove($subscription);
        }

        $this->em->flush();
    }

    /**
     * What the push service actually answered, as one short string.
     *
     * The status alone where there is one, because that is the vocabulary a
     * reader can look up; the library's reason string only when there is no
     * response at all, which is a connection that never completed and whose
     * only description is that sentence.
     */
    private function statusOf(MessageSentReport $report): ?string
    {
        $status = $report->getResponse()?->getStatusCode();

        if (null === $status) {
            return '' === $report->getReason() ? null : $report->getReason();
        }

        return sprintf('HTTP %d', $status);
    }

    /**
     * Keys are mandatory upstream of here — WebPush::sendNotification silently
     * DROPS the payload when either is missing (it guards on
     * !empty($userPublicKey) && !empty($userAuthToken)) and sends a bodiless
     * POST instead. A push with no body cannot carry the verification code, so
     * PushSubscription/set rejects a subscription without them rather than
     * letting the handshake fail in a way nothing reports.
     */
    private function toLibrarySubscription(JmapPushSubscription $subscription): Subscription
    {
        return Subscription::create([
            'endpoint' => $subscription->url,
            'publicKey' => $subscription->p256dh,
            'authToken' => $subscription->auth,
            'contentEncoding' => 'aes128gcm',
        ]);
    }
}
