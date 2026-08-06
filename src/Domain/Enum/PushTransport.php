<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * How a JMAP PushSubscription is actually delivered to.
 *
 * Web Push was the only answer for a long time and needed no name: one
 * protocol, one sender, one set of columns. Firebase Cloud Messaging is the
 * second, and it is not a variant of the first — it carries no endpoint URL, no
 * RFC 8291 keys and no VAPID signature, and it is addressed by a registration
 * token the device's copy of Google Play services minted. Nothing about the two
 * overlaps except the JSON that travels inside.
 *
 * So the transport is stored rather than inferred. Deciding "it has a token,
 * therefore it is FCM" would work exactly until a subscription arrived with
 * both, and the shape of the row would then depend on which check ran first.
 * The column says what the row is, and PushSubscription's named constructors
 * are the only way to set it.
 *
 * The value is also the wire spelling: PushSubscription/get publishes it as
 * `transport`, because a client that offers both has to know which one it got
 * back — the same registration replaces itself per deviceClientId, so a device
 * that switched from UnifiedPush to FCM has one row and needs to be told which
 * kind it is.
 */
enum PushTransport: string
{
    /** RFC 8030/8291/8292 — a push service owns the endpoint URL. */
    case WebPush = 'webpush';

    /** FCM HTTP v1 — Google owns the device token. */
    case Fcm = 'fcm';

    /**
     * Whether a subscription of this kind is addressed by a URL this server
     * POSTs to, as opposed to a token handed to somebody else's gateway.
     *
     * Stays a method rather than a `url !== null` test on the entity: it is a
     * property of the transport, and asking the row would answer "no" for a
     * Web Push subscription that is merely half-built.
     */
    public function addressedByUrl(): bool
    {
        return match ($this) {
            self::WebPush => true,
            self::Fcm     => false,
        };
    }
}
