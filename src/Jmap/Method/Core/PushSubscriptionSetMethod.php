<?php

declare(strict_types=1);

namespace App\Jmap\Method\Core;

use App\Entity\User\PushSubscription;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\Push\WebPushSender;
use App\Repository\User\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "PushSubscription/set" (RFC 8620 §7.2.2). No accountId — subscriptions are
 * per authenticated user.
 *
 * THE VERIFICATION HANDSHAKE IS THE POINT. On create the server immediately
 * POSTs a PushVerification object to the client-supplied URL; the client reads
 * the code out of it and echoes it back via an update. Until it does, the
 * subscription receives nothing.
 *
 * That is what stops this endpoint being an open relay: without it, anyone
 * with an account could register a stranger's URL and have plMail POST to it
 * on every state change. The code proves whoever registered the URL can also
 * read what arrives there.
 */
final class PushSubscriptionSetMethod implements JmapMethod
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly WebPushSender $sender,
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
                $deviceClientId = $this->requireString($properties['deviceClientId'] ?? null, 'deviceClientId');
                $url = $this->requireUrl($properties['url'] ?? null);
                $types = $this->types($properties['types'] ?? null);
                $expires = $this->expires($properties['expires'] ?? null);
                $keys = $this->requireKeys($properties['keys'] ?? null);
            } catch (MethodException $exception) {
                $notCreated[$creationId] = $exception->toError();
                continue;
            }

            // deviceClientId is stable per device+app, so re-registering after
            // a reinstall replaces the old row rather than accumulating one
            // dead endpoint per install.
            $subscription = $this->subscriptions->findOneByDeviceClientId($context->user, $deviceClientId);

            if (null === $subscription) {
                $subscription = new PushSubscription($context->user, $deviceClientId, $url);
                $this->entityManager->persist($subscription);
            } else {
                $subscription->url = $url;
                $subscription->reissueVerification();
            }

            $subscription->types = $types;
            $subscription->expires = $expires;
            $subscription->p256dh = $keys['p256dh'];
            $subscription->auth = $keys['auth'];

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
                $this->applyPatch($subscription, $patch);
            } catch (MethodException $exception) {
                $notUpdated[$id] = $exception->toError();
                continue;
            }

            $updated[$id] = null;
        }
    }

    /**
     * @param array<string,mixed> $patch
     */
    private function applyPatch(PushSubscription $subscription, array $patch): void
    {
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

                default:
                    // url and keys are create-only: changing where pushes go
                    // has to redo the handshake, which means a new create.
                    throw new MethodException('invalidPatch', sprintf('Property "%s" cannot be updated.', $property));
            }
        }
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
     * RFC 8620 §7.2.2: a PushVerification object sent to the endpoint itself.
     * A failure is not fatal — the subscription simply stays unverified and
     * the client can ask for a new one by creating again.
     */
    private function sendVerification(PushSubscription $subscription): void
    {
        $this->sender->send($subscription, [
            '@type' => 'PushVerification',
            'pushSubscriptionId' => (string) $subscription->id,
            'verificationCode' => (string) $subscription->verificationCode,
        ]);
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
