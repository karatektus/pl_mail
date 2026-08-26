<?php

declare(strict_types=1);

namespace App\Jmap\Push;

use App\Domain\Enum\PushDeliveryOutcome;
use App\Domain\Enum\PushTransport;
use App\Domain\Interface\PushSenderInterface;
use App\Entity\User\PushSubscription;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Delivers JMAP push payloads through Firebase Cloud Messaging (HTTP v1).
 *
 * The second transport, beside WebPushSender, and it exists for one platform:
 * a native Android app has no push service of its own, and Android's is FCM.
 * UnifiedPush remains the answer for a user who wants no Google in the path —
 * this is the answer for the far larger number who want notifications to work
 * the way every other app on their phone does.
 *
 * **Data messages only, never `notification`.** A `notification` payload is
 * rendered by the system tray before the app sees it, which would put a
 * server-authored string on a lock screen and take the decision of whether to
 * show anything away from the client. JMAP pushes no mail content by design;
 * turning a state token into a visible alert is the client's job, because only
 * the client knows whether the user is looking at that mailbox right now.
 * Data-only also means the app is woken in both foreground and background,
 * which is the behaviour a mail client needs.
 *
 * **The body is the same JSON WebPushSender sends**, carried as one string
 * under `data.payload` — FCM data maps are string→string, so the object cannot
 * be nested — and the `@type` inside it says whether it is a StateChange or the
 * PushVerification of the RFC 8620 §7.2.2 handshake. A client therefore parses
 * one thing and gets the identical object whichever transport delivered it.
 *
 * **The collapse key is per payload type, and that is a bug fix rather than a
 * refinement.** Collapsing is the point — a phone that was off for an hour
 * should be woken by the newest state, not by nine stale ones — but a single
 * key would let a StateChange replace an undelivered PushVerification, and the
 * subscription would then wait forever for a code that FCM discarded. Two keys,
 * so the handshake can never be collapsed away by ordinary traffic.
 *
 * Error handling, and where it deliberately differs from Web Push:
 *
 *   **UNREGISTERED / NOT_FOUND destroy the subscription.** The token belonged
 *   to an app instance that has been uninstalled, restored onto another device
 *   or had its data cleared. This is FCM's 404/410, and it is answered the same
 *   way, for the same reason: retrying it means a failing POST on every state
 *   change forever.
 *
 *   **QUOTA_EXCEEDED, UNAVAILABLE, 429 and 5xx do not even count as failures.**
 *   WebPushSender increments failureCount on every rejection it cannot classify,
 *   because a Web Push report gives it a reason string and not a vocabulary.
 *   FCM names the transient cases, so counting them would retire ten perfectly
 *   good devices during one Firebase outage — the failure counter exists to
 *   retire endpoints that are broken, and an outage is not a broken endpoint.
 *
 *   **Everything else counts.** INVALID_ARGUMENT, SENDER_ID_MISMATCH and
 *   THIRD_PARTY_AUTH_ERROR are all permanent for this row against this project,
 *   but none of them proves the device is gone — a SENDER_ID_MISMATCH is the
 *   admin having pasted a key for the wrong Firebase project, and destroying
 *   every subscription in the install over that would be unrecoverable. They
 *   count toward retirement instead, which is slow enough to be fixed.
 *
 * **Every one of those outcomes is recorded as a PushDelivery**, with FCM's own
 * error name in it — which is the whole reason the recording happens in here
 * rather than in a decorator around the interface. See PushDeliveryRecorder.
 *
 * Docs: https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages
 */
final class FcmSender implements PushSenderInterface
{
    /** projects/{projectId} is substituted; there is no other endpoint. */
    private const string SEND_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    /**
     * Consecutive failures tolerated before a token is dropped. The same number
     * WebPushSender uses, and deliberately the same: a device does not become
     * more or less patient for having chosen a different transport.
     */
    private const int MAX_FAILURES = 10;

    /**
     * How long FCM should hold an undelivered message for a device that is off
     * or out of coverage.
     *
     * A day. A StateChange does not go stale in the way a chat message does —
     * it says "your mailbox moved, ask what changed", and a client acting on
     * that twelve hours late is correct, merely late. Beyond a day the client
     * will have resynced on its own by opening, so holding it longer only
     * spends Firebase's storage on a notification that arrives after the user
     * already read the mail.
     */
    private const string TTL = '86400s';

    /**
     * Collapse keys, by the `@type` of what is being sent. See the class
     * docblock: a shared key would let ordinary traffic discard a handshake.
     *
     * **A payload type that is not listed gets NO collapse key**, which is the
     * safe default rather than an oversight. Collapsing is only correct where
     * the newest message makes the earlier ones redundant — true of a state
     * token, true of a re-issued verification, and false of a CalendarAlert,
     * where two reminders about two different appointments would collapse into
     * one and the other would simply never be shown.
     *
     * FCM allows four distinct keys per device; two is comfortable.
     *
     * @var array<string,string>
     */
    private const array COLLAPSE_KEYS = [
        'StateChange'      => 'plmail-state-change',
        'PushVerification' => 'plmail-push-verification',
    ];

    /**
     * FCM error codes that clear on their own. Listed rather than inferred from
     * the status, because QUOTA_EXCEEDED arrives as a 429 and UNAVAILABLE as a
     * 503, and a 400 is never transient.
     *
     * @var list<string>
     */
    private const array TRANSIENT_CODES = [
        'QUOTA_EXCEEDED',
        'UNAVAILABLE',
        'INTERNAL',
    ];

    /**
     * The codes that mean this token will never work again.
     *
     * UNREGISTERED is what a live project answers for a dead token; NOT_FOUND
     * is the older spelling and still arrives, which is why both are here.
     *
     * @var list<string>
     */
    private const array GONE_CODES = [
        'UNREGISTERED',
        'NOT_FOUND',
    ];

    public function __construct(
        private readonly FcmSettings            $settings,
        private readonly FcmAccessTokenProvider $tokens,
        private readonly HttpClientInterface    $http,
        private readonly EntityManagerInterface $em,
        private readonly PushDeliveryRecorder   $deliveries,
        private readonly LoggerInterface        $logger,
    ) {}

    public function transport(): PushTransport
    {
        return PushTransport::Fcm;
    }

    public function isConfigured(): bool
    {
        return $this->settings->isActive();
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function send(PushSubscription $subscription, array $payload): bool
    {
        // Before the credential work as well as the request: fetching an
        // access token is part of what the device waits for, and a latency
        // that excluded it would hide the one FCM cost that is ours.
        $startedAt = microtime(true);

        $account = $this->settings->serviceAccount();

        if (null === $account) {
            $this->logger->warning('FCM push is not configured; enable it under Admin → Push.');

            $this->deliveries->record($subscription, $payload, PushDeliveryOutcome::Skipped, 'not-configured', $startedAt);

            return false;
        }

        $token = $subscription->fcmToken;

        if (null === $token || '' === $token) {
            $this->logger->error('FCM: subscription has no registration token', [
                'subscriptionId' => $subscription->id,
            ]);

            $this->deliveries->record($subscription, $payload, PushDeliveryOutcome::Skipped, 'no-registration-token', $startedAt);

            return false;
        }

        $accessToken = $this->tokens->tokenFor($account);

        if (null === $accessToken) {
            // Already logged with the reason by the provider. Not counted as a
            // failure of this subscription: the credentials being wrong says
            // nothing about the device. Recorded as a skip for the same reason
            // — nothing was sent, and an install whose service-account key has
            // been revoked should read as a configuration problem rather than
            // as every device having gone bad at once.
            $this->deliveries->record($subscription, $payload, PushDeliveryOutcome::Skipped, 'no-access-token', $startedAt);

            return false;
        }

        try {
            $response = $this->http->request('POST', sprintf(self::SEND_ENDPOINT, $account->projectId), [
                'auth_bearer' => $accessToken,
                'json'        => ['message' => $this->message($token, $payload)],
            ]);

            $status = $response->getStatusCode();
            // false: the error vocabulary lives in the body of a 4xx/5xx, and
            // throwing would discard the one field that decides whether this
            // subscription is retired or retried.
            $body = 200 === $status ? [] : $response->toArray(false);
        } catch (HttpException|\JsonException $exception) {
            $this->logger->error('FCM send failed', [
                'subscriptionId' => $subscription->id,
                'error'          => $exception->getMessage(),
                'exception'      => $exception,
            ]);

            $this->recordFailure($subscription, $payload, $exception->getMessage(), $startedAt);

            return false;
        }

        if (200 === $status) {
            $subscription->failureCount = 0;
            $this->em->flush();

            $this->deliveries->record($subscription, $payload, PushDeliveryOutcome::Accepted, 'HTTP 200', $startedAt);

            return true;
        }

        return $this->handleRejection($subscription, $payload, $status, $body, $startedAt);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The `message` object FCM is sent.
     *
     * `android` rather than a top-level field, because FCM HTTP v1 has no
     * platform-neutral TTL or collapse key — the neutral `Message` carries only
     * token, data and notification, and every delivery knob is under the
     * platform block. This transport exists for Android, so there is exactly
     * one block to fill in.
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private function message(string $token, array $payload): array
    {
        $type = $payload['@type'] ?? null;

        $android = [
            // Data-only messages are throttled and may be deferred at normal
            // priority once the device dozes, which is precisely the case a
            // background mail notification exists for.
            'priority' => 'HIGH',
            'ttl'      => self::TTL,
        ];

        $collapseKey = self::COLLAPSE_KEYS[is_string($type) ? $type : ''] ?? null;

        if (null !== $collapseKey) {
            $android['collapse_key'] = $collapseKey;
        }

        return [
            'token' => $token,
            // One key holding the whole object. FCM data maps are string to
            // string — a nested `changed` map cannot be expressed — so the
            // choice is one JSON string or a flattened shape that no longer
            // matches what Web Push delivers. The client parses this and gets
            // the identical object either way.
            'data'    => ['payload' => json_encode($payload, JSON_THROW_ON_ERROR)],
            'android' => $android,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $body
     */
    private function handleRejection(
        PushSubscription $subscription,
        array            $payload,
        int              $status,
        array            $body,
        float            $startedAt,
    ): bool {
        $code = $this->errorCode($body);

        // FCM's own name where it gave one, the status where it did not. The
        // name is the part worth keeping: UNREGISTERED and SENDER_ID_MISMATCH
        // both arrive as a 404-shaped rejection and mean entirely different
        // things — one is a dead phone and the other is the admin's key.
        $detail = $code ?? sprintf('HTTP %d', $status);

        if (true === in_array($code, self::GONE_CODES, true)) {
            $this->logger->info('FCM token gone; removing subscription', [
                'subscriptionId' => $subscription->id,
                'code'           => $code,
            ]);

            // Ahead of the remove, so the record that explains the
            // disappearance is written while the row it describes still exists.
            $this->deliveries->record(
                $subscription,
                $payload,
                PushDeliveryOutcome::SubscriptionDestroyed,
                $detail,
                $startedAt,
            );

            $this->em->remove($subscription);
            $this->em->flush();

            return false;
        }

        if (true === in_array($code, self::TRANSIENT_CODES, true) || 429 === $status || $status >= 500) {
            $this->logger->warning('FCM is refusing traffic; the subscription is kept', [
                'subscriptionId' => $subscription->id,
                'status'         => $status,
                'code'           => $code,
            ]);

            // Recorded as a failure even though failureCount is deliberately
            // not touched. The two are different questions: the counter asks
            // whether this endpoint should be retired, and an outage is not a
            // broken endpoint — the log asks what happened, and "Firebase
            // answered 503" is exactly what an admin comes here to find.
            $this->deliveries->record($subscription, $payload, PushDeliveryOutcome::Failed, $detail, $startedAt);

            return false;
        }

        $this->logger->warning('FCM rejected the message', [
            'subscriptionId' => $subscription->id,
            'status'         => $status,
            'code'           => $code,
            'reason'         => $body['error']['message'] ?? null,
        ]);

        $this->recordFailure($subscription, $payload, $detail, $startedAt);

        return false;
    }

    /**
     * FCM's own error code, which is not the same thing as `error.status`.
     *
     * The vocabulary that distinguishes UNREGISTERED from a generic NOT_FOUND
     * lives in a `google.firebase.fcm.v1.FcmError` detail, and `error.status`
     * carries only the gRPC-level name. Both are read — details first, because
     * it is the specific one — so a response that carries only the status is
     * still classified rather than falling through to "unknown, count it".
     *
     * @param array<string,mixed> $body
     */
    private function errorCode(array $body): ?string
    {
        $details = $body['error']['details'] ?? null;

        if (true === is_array($details)) {
            foreach ($details as $detail) {
                $code = is_array($detail) ? ($detail['errorCode'] ?? null) : null;

                if (true === is_string($code) && '' !== $code) {
                    return $code;
                }
            }
        }

        $status = $body['error']['status'] ?? null;

        return is_string($status) && '' !== $status ? $status : null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function recordFailure(PushSubscription $subscription, array $payload, ?string $detail, float $startedAt): void
    {
        ++$subscription->failureCount;

        $retired = $subscription->failureCount >= self::MAX_FAILURES;

        // The attempt that retires the token is recorded as a destruction, not
        // as the tenth failure: the row is gone afterwards, and a delivery log
        // that says "failed" leaves the device's disappearance from the list
        // with no explanation in it.
        $this->deliveries->record(
            $subscription,
            $payload,
            true === $retired ? PushDeliveryOutcome::SubscriptionDestroyed : PushDeliveryOutcome::Failed,
            $detail,
            $startedAt,
        );

        if (true === $retired) {
            $this->logger->info('FCM token failed too often; removing subscription', [
                'subscriptionId' => $subscription->id,
                'failures'       => $subscription->failureCount,
            ]);

            $this->em->remove($subscription);
        }

        $this->em->flush();
    }
}
