<?php

declare(strict_types=1);

namespace App\Jmap\Push;

use App\Entity\PushSubscription as JmapPushSubscription;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;

/**
 * Delivers JMAP push payloads over Web Push (RFC 8030 transport, RFC 8291
 * payload encryption, RFC 8292 VAPID signing).
 *
 * One implementation covers every platform, because they all speak the same
 * protocol: UnifiedPush distributors on Android, Apple's gateway for an
 * installed PWA on iOS, and the browser push services on desktop.
 *
 * Endpoints that are permanently gone (404/410) are deleted rather than
 * retried — a browser that revokes a subscription answers 410 forever, and
 * keeping it would mean a failing POST on every state change until the heat
 * death of the universe.
 */
final class WebPushSender
{
    /**
     * Consecutive failures tolerated before an endpoint is dropped. Covers a
     * push service having a bad day without keeping dead endpoints forever.
     */
    private const int MAX_FAILURES = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $vapidSubject,
        private readonly string $vapidPublicKey,
        private readonly string $vapidPrivateKey,
    ) {
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
        if (false === $this->isConfigured()) {
            $this->logger->warning('Web Push is not configured; set VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY.');

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
                'error' => $exception->getMessage(),
            ]);

            $this->recordFailure($subscription);

            return false;
        }

        if (true === $report->isSuccess()) {
            $subscription->failureCount = 0;
            $this->em->flush();

            return true;
        }

        // isSubscriptionExpired() covers the 404/410 the push services use to
        // say "this endpoint will never work again".
        if (true === $report->isSubscriptionExpired()) {
            $this->logger->info('Push endpoint gone; removing subscription', [
                'subscriptionId' => $subscription->id,
            ]);

            $this->em->remove($subscription);
            $this->em->flush();

            return false;
        }

        $this->logger->warning('Web Push rejected', [
            'subscriptionId' => $subscription->id,
            'reason' => $report->getReason(),
        ]);

        $this->recordFailure($subscription);

        return false;
    }

    private function recordFailure(JmapPushSubscription $subscription): void
    {
        ++$subscription->failureCount;

        if ($subscription->failureCount >= self::MAX_FAILURES) {
            $this->logger->info('Push endpoint failed too often; removing subscription', [
                'subscriptionId' => $subscription->id,
                'failures' => $subscription->failureCount,
            ]);

            $this->em->remove($subscription);
        }

        $this->em->flush();
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
