<?php

declare(strict_types=1);

namespace App\Jmap\Method\Core;

use App\Entity\User\PushSubscription;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\User\PushSubscriptionRepository;

/**
 * "PushSubscription/get" (RFC 8620 §7.2.1).
 *
 * Unusual among /get methods: it takes no accountId — subscriptions belong to
 * the authenticated user, not to a mail account — and it MUST NOT return the
 * verification code or the client's keys, so the response is deliberately
 * narrower than the stored object.
 *
 * `transport` is a plMail addition and read-only. A client that can create both
 * kinds needs it: registration replaces itself per deviceClientId, so a phone
 * that moved from a UnifiedPush distributor to FCM has one row rather than two,
 * and "which kind did I end up with?" is otherwise answerable only by noticing
 * that `url` is null.
 */
final class PushSubscriptionGetMethod implements JmapMethod
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
    ) {
    }

    public function name(): string
    {
        return 'PushSubscription/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $requestedIds = $arguments['ids'] ?? null;

        if (null !== $requestedIds && false === is_array($requestedIds)) {
            throw new MethodException('invalidArguments', '"ids" must be an array or null.');
        }

        $wanted = null;

        if (null !== $requestedIds) {
            $wanted = array_values(array_map(
                static fn (mixed $id): string => $context->resolveId((string) $id) ?? (string) $id,
                $requestedIds,
            ));
        }

        $list = [];
        $found = [];

        foreach ($this->subscriptions->findForUser($context->user) as $subscription) {
            $id = (string) $subscription->id;

            if (null !== $wanted && false === in_array($id, $wanted, true)) {
                continue;
            }

            $found[] = $id;
            $list[] = $this->toJmap($subscription);
        }

        $notFound = [];

        if (null !== $wanted) {
            $notFound = array_values(array_diff($wanted, $found));
        }

        return [
            'list' => $list,
            'notFound' => $notFound,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function toJmap(PushSubscription $subscription): array
    {
        return [
            'id' => (string) $subscription->id,
            'deviceClientId' => $subscription->deviceClientId,
            // plMail extension. "webpush" or "fcm"; see the class docblock.
            'transport' => $subscription->transport->value,
            // Null on an FCM subscription — there is no URL, and inventing one
            // would be a value a client could try to POST to.
            'url' => $subscription->url,
            // "keys", "fcmToken" and "verificationCode" are write-only by
            // design: echoing them back would let anyone who can read one
            // response forge pushes to that device. The token is in that class
            // as much as the keys are — it is the whole address of a phone.
            'types' => $subscription->types,
            'expires' => $subscription->expires?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
