<?php

declare(strict_types=1);

namespace App\Jmap\Method\Core;

use App\Domain\Enum\PushTransport;
use App\Entity\User\PushSubscription;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\Push\PushSenderRegistry;
use App\Repository\User\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "PushSubscription/set" (RFC 8620 §7.2.2). No accountId — subscriptions are
 * per authenticated user.
 *
 * THE VERIFICATION HANDSHAKE IS THE POINT. On create the server immediately
 * sends a PushVerification object to the address the client gave; the client
 * reads the code out of it and echoes it back via an update. Until it does, the
 * subscription receives nothing.
 *
 * That is what stops this endpoint being an open relay: without it, anyone
 * with an account could register a stranger's address and have plMail deliver
 * to it on every state change. The code proves whoever registered the address
 * can also read what arrives there.
 *
 * **Two transports, one handshake.** A create carrying `url` and `keys` is a
 * Web Push subscription; a create carrying `fcmToken` is a Firebase one, and
 * `fcmToken` is a plMail extension of the RFC's object. The two shapes are
 * exclusive and a create carrying both is refused rather than resolved by
 * precedence — a client that sends both has a bug, and picking one for it would
 * mean the device it actually reaches depends on which check this class runs
 * first. Everything else is identical: deviceClientId still identifies the
 * device, `types` still filters, `expires` is still echoed unchanged, and the
 * verification round trip is required of both. FCM is not exempt; the code is
 * delivered as an ordinary data message and echoed back the same way.
 *
 * **`fcmToken` is the one address property an update may change.** Web Push
 * refuses a `url` patch because repointing a verified subscription would carry
 * the verification to an endpoint that proved nothing — but Android reissues
 * registration tokens on its own schedule, so refusing rotation would mean a
 * device going permanently silent for doing something normal. The safety
 * property is kept rather than dropped: rotating re-arms the handshake, exactly
 * as re-creating with a new URL does, and the client verifies again against the
 * new token.
 */
final class PushSubscriptionSetMethod implements JmapMethod
{
    /**
     * What a create may carry, named in the refusals. Two shapes, so the
     * message can say which one the caller is halfway into.
     *
     * @var array<string,list<string>>
     */
    private const array CREATE_PROPERTIES = [
        PushTransport::WebPush->value => ['deviceClientId', 'url', 'keys', 'types', 'expires'],
        PushTransport::Fcm->value     => ['deviceClientId', 'fcmToken', 'types', 'expires'],
    ];

    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly PushSenderRegistry $senders,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'PushSubscription/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $created = [];
        $notCreated = [];
        $updated = [];
        $notUpdated = [];
        $destroyed = [];
        $notDestroyed = [];

        $this->applyCreates($arguments['create'] ?? null, $context, $created, $notCreated);
        $this->applyUpdates($arguments['update'] ?? null, $context, $updated, $notUpdated);
        $this->applyDestroys($arguments['destroy'] ?? null, $context, $destroyed, $notDestroyed);

        $this->entityManager->flush();

        return [
            'created' => 0 === count($created) ? new \stdClass() : $created,
            'notCreated' => 0 === count($notCreated) ? new \stdClass() : $notCreated,
            'updated' => 0 === count($updated) ? new \stdClass() : $updated,
            'notUpdated' => 0 === count($notUpdated) ? new \stdClass() : $notUpdated,
            'destroyed' => array_values($destroyed),
            'notDestroyed' => 0 === count($notDestroyed) ? new \stdClass() : $notDestroyed,
        ];
    }

    /**
     * @param array<string,mixed> $created
     * @param array<string,mixed> $notCreated
     */
    private function applyCreates(mixed $create, JmapContext $context, array &$created, array &$notCreated): void
    {
        if (null === $create) {
            return;
        }

        if (false === is_array($create)) {
            throw new MethodException('invalidArguments', '"create" must be an object.');
        }

        foreach ($create as $creationId => $properties) {
            $creationId = (string) $creationId;

            if (false === is_array($properties)) {
                $notCreated[$creationId] = ['type' => 'invalidProperties', 'description' => 'Each create must be an object.'];
                continue;
            }

            try {
                $transport = $this->transportOf($properties);
                $deviceClientId = $this->requireString($properties['deviceClientId'] ?? null, 'deviceClientId');
                $types = $this->types($properties['types'] ?? null);
                $expires = $this->expires($properties['expires'] ?? null);

                $url = PushTransport::WebPush === $transport ? $this->requireUrl($properties['url'] ?? null) : null;
                $keys = PushTransport::WebPush === $transport ? $this->requireKeys($properties['keys'] ?? null) : null;
                $token = PushTransport::Fcm === $transport ? $this->requireString($properties['fcmToken'] ?? null, 'fcmToken') : null;
            } catch (MethodException $exception) {
                $notCreated[$creationId] = $exception->toError();
                continue;
            }

            // deviceClientId is stable per device+app, so re-registering after
            // a reinstall replaces the old row rather than accumulating one
            // dead endpoint per install.
            $subscription = $this->subscriptions->findOneByDeviceClientId($context->user, $deviceClientId);

            // A device that changed transports — the user installed a
            // UnifiedPush distributor, or removed it — cannot have its row
            // mutated across the two shapes, so the old one goes and a new one
            // takes its place. Flushed on its own first: (usr_id,
            // device_client_id) is unique and Doctrine orders inserts before
            // deletes within a flush, so doing both at once hits the
            // constraint on the row being replaced.
            if (null !== $subscription && $subscription->transport !== $transport) {
                $this->entityManager->remove($subscription);
                $this->entityManager->flush();
                $subscription = null;
            }

            if (null === $subscription) {
                $subscription = PushTransport::Fcm === $transport
                    ? PushSubscription::fcm($context->user, $deviceClientId, (string) $token)
                    : PushSubscription::webPush($context->user, $deviceClientId, (string) $url);

                $this->entityManager->persist($subscription);
            } elseif (PushTransport::Fcm === $transport) {
                $subscription->rotateFcmToken((string) $token);
            } else {
                $subscription->pointAt((string) $url);
            }

            $subscription->types = $types;
            $subscription->expires = $expires;
            $subscription->p256dh = $keys['p256dh'] ?? null;
            $subscription->auth = $keys['auth'] ?? null;

            $this->entityManager->flush();

            $this->sendVerification($subscription);

            $created[$creationId] = [
                'id' => (string) $subscription->id,
                // The spec lets the server shorten the requested expiry; we
                // honour whatever was asked for, so echo it back unchanged.
                'expires' => $subscription->expires?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ];

            $context->recordCreatedId($creationId, (string) $subscription->id);
        }
    }

    /**
     * @param array<string,mixed> $updated
     * @param array<string,mixed> $notUpdated
     */
    private function applyUpdates(mixed $update, JmapContext $context, array &$updated, array &$notUpdated): void
    {
        if (null === $update) {
            return;
        }

        if (false === is_array($update)) {
            throw new MethodException('invalidArguments', '"update" must be an object.');
        }

        foreach ($update as $id => $patch) {
            $id = (string) $id;

            if (false === is_array($patch)) {
                $notUpdated[$id] = ['type' => 'invalidPatch', 'description' => 'Each update must be an object.'];
                continue;
            }

            $resolved = $context->resolveId($id) ?? $id;
            $subscription = ctype_digit($resolved)
                ? $this->subscriptions->findOneOwnedBy((int) $resolved, $context->user)
                : null;

            if (null === $subscription) {
                $notUpdated[$id] = ['type' => 'notFound', 'description' => 'No such PushSubscription.'];
                continue;
            }

            try {
                $reverify = $this->applyPatch($subscription, $patch);
            } catch (MethodException $exception) {
                $notUpdated[$id] = $exception->toError();
                continue;
            }

            if (true === $reverify) {
                // Flushed here rather than with the rest of the call: the
                // verification carries the code that was just minted, and a
                // send made before the write would race a client that echoes
                // it back faster than this method returns.
                $this->entityManager->flush();
                $this->sendVerification($subscription);
            }

            $updated[$id] = null;
        }
    }

    /**
     * @param array<string,mixed> $patch
     */
    private function applyPatch(PushSubscription $subscription, array $patch): bool
    {
        $reverify = false;

        foreach ($patch as $property => $value) {
            switch ((string) $property) {
                case 'verificationCode':
                    if (false === is_string($value) || false === $subscription->verify($value)) {
                        throw new MethodException('invalidProperties', 'The verification code does not match.');
                    }

                    break;

                case 'expires':
                    $subscription->expires = $this->expires($value);
                    break;

                case 'types':
                    $subscription->types = $this->types($value);
                    break;

                case 'fcmToken':
                    if (PushTransport::Fcm !== $subscription->transport) {
                        throw new MethodException('invalidPatch', 'This is a Web Push subscription; "fcmToken" cannot be set on it. Create a new subscription with the same deviceClientId to move the device to FCM.');
                    }

                    $subscription->rotateFcmToken($this->requireString($value, 'fcmToken'));
                    $reverify = true;
                    break;

                default:
                    // url and keys are create-only: changing where an encrypted
                    // payload goes has to redo the handshake, which means a new
                    // create. fcmToken above is the deliberate exception, and
                    // it redoes the handshake in place rather than skipping it.
                    throw new MethodException('invalidPatch', sprintf('Property "%s" cannot be updated. Updatable properties are "verificationCode", "expires", "types" and, on an FCM subscription, "fcmToken".', $property));
            }
        }

        return $reverify;
    }

    /**
     * @param list<string>        $destroyed
     * @param array<string,mixed> $notDestroyed
     */
    private function applyDestroys(mixed $destroy, JmapContext $context, array &$destroyed, array &$notDestroyed): void
    {
        if (null === $destroy) {
            return;
        }

        if (false === is_array($destroy)) {
            throw new MethodException('invalidArguments', '"destroy" must be an array of ids.');
        }

        foreach ($destroy as $id) {
            $id = (string) $id;
            $resolved = $context->resolveId($id) ?? $id;
            $subscription = ctype_digit($resolved)
                ? $this->subscriptions->findOneOwnedBy((int) $resolved, $context->user)
                : null;

            if (null === $subscription) {
                $notDestroyed[$id] = ['type' => 'notFound', 'description' => 'No such PushSubscription.'];
                continue;
            }

            $this->entityManager->remove($subscription);
            $destroyed[] = $id;
        }
    }

    /**
     * RFC 8620 §7.2.2: a PushVerification object sent to the address itself —
     * POSTed to the endpoint for Web Push, delivered as an FCM data message for
     * Firebase, and identical JSON either way.
     *
     * A failure is not fatal — the subscription simply stays unverified and
     * the client can ask for a new one by creating again.
     */
    private function sendVerification(PushSubscription $subscription): void
    {
        $this->senders->for($subscription)?->send($subscription, [
            '@type' => 'PushVerification',
            'pushSubscriptionId' => (string) $subscription->id,
            'verificationCode' => (string) $subscription->verificationCode,
        ]);
    }

    /**
     * Which kind of subscription this create is asking for, refusing the ones
     * that are asking for both or for one that is switched off.
     *
     * `fcmToken` is what decides it, because it is the property RFC 8620 does
     * not define — a create without it is the standard object and must keep
     * meaning exactly what it meant before FCM existed.
     *
     * The conflict is refused rather than resolved. A create carrying both a
     * token and a URL is a client bug, and choosing one would mean the device
     * that actually receives the mail depends on the order of two ifs in here.
     *
     * @param array<string,mixed> $properties
     */
    private function transportOf(array $properties): PushTransport
    {
        if (false === array_key_exists('fcmToken', $properties) || null === $properties['fcmToken']) {
            return PushTransport::WebPush;
        }

        foreach (['url', 'keys'] as $webPushOnly) {
            if (true === array_key_exists($webPushOnly, $properties) && null !== $properties[$webPushOnly]) {
                throw new MethodException('invalidProperties', sprintf(
                    '"fcmToken" and "%s" cannot both be set: a subscription is either an FCM one or a Web Push one. '
                    . 'An FCM create takes %s; a Web Push create takes %s.',
                    $webPushOnly,
                    implode(', ', self::CREATE_PROPERTIES[PushTransport::Fcm->value]),
                    implode(', ', self::CREATE_PROPERTIES[PushTransport::WebPush->value]),
                ));
            }
        }

        $sender = $this->senders->of(PushTransport::Fcm);

        // Refused rather than stored, because a stored FCM subscription on an
        // install with no Firebase project is a device that has completed
        // registration, is waiting for a verification that will never arrive,
        // and has no way to find out. The Session says the same thing earlier
        // and more cheaply — this is the backstop for a client that did not
        // look.
        if (null === $sender || false === $sender->isConfigured()) {
            throw new MethodException('forbidden', 'FCM push is not configured on this server. Check "fcm" in the "urn:plmail:params:jmap:push" session capability before creating an FCM subscription, and use "url" with "keys" for Web Push instead.');
        }

        return PushTransport::Fcm;
    }

    /**
     * RFC 8291 encryption keys. Required, even though RFC 8620 marks the field
     * optional: without them the Web Push library drops the payload and sends
     * a bodiless POST, so the verification code would never reach the client
     * and the subscription could never become deliverable. Failing loudly at
     * registration beats a subscription that silently never works.
     *
     * Browsers supply both from pushManager.subscribe(); UnifiedPush
     * distributors do too under the Web Push profile.
     *
     * @return array{p256dh:string,auth:string}
     */
    private function requireKeys(mixed $keys): array
    {
        if (false === is_array($keys)) {
            throw new MethodException('invalidProperties', '"keys" with "p256dh" and "auth" is required.');
        }

        $p256dh = $keys['p256dh'] ?? null;
        $auth = $keys['auth'] ?? null;

        if (false === is_string($p256dh) || '' === $p256dh || false === is_string($auth) || '' === $auth) {
            throw new MethodException('invalidProperties', 'Both "keys.p256dh" and "keys.auth" are required for encrypted delivery.');
        }

        return ['p256dh' => $p256dh, 'auth' => $auth];
    }

    private function requireString(mixed $value, string $property): string
    {
        if (false === is_string($value) || '' === trim($value)) {
            throw new MethodException('invalidProperties', sprintf('A non-empty "%s" is required.', $property));
        }

        return trim($value);
    }

    /**
     * Only absolute http(s) URLs — anything else would let a client aim the
     * server at a non-HTTP scheme.
     */
    private function requireUrl(mixed $value): string
    {
        $url = $this->requireString($value, 'url');

        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            throw new MethodException('invalidProperties', '"url" must be an absolute URL.');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (false === in_array($scheme, ['http', 'https'], true)) {
            throw new MethodException('invalidProperties', '"url" must be http or https.');
        }

        return $url;
    }

    /**
     * @return list<string>|null
     */
    private function types(mixed $value): ?array
    {
        if (null === $value) {
            return null;
        }

        if (false === is_array($value)) {
            throw new MethodException('invalidProperties', '"types" must be an array or null.');
        }

        return array_values(array_map('strval', $value));
    }

    private function expires(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        if (false === is_string($value)) {
            throw new MethodException('invalidProperties', '"expires" must be a UTCDate or null.');
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new MethodException('invalidProperties', sprintf('"%s" is not a valid UTCDate.', $value));
        }
    }
}
