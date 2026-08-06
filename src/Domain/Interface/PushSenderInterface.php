<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\Enum\PushTransport;
use App\Entity\User\PushSubscription;

/**
 * Delivering one JMAP payload to one subscribed device.
 *
 * The axis that varies is the transport, not the platform: WebPushSender covers
 * every platform that speaks RFC 8030 — browsers, UnifiedPush distributors,
 * Apple's gateway for an installed PWA — and FcmSender covers the one that does
 * not. Same shape as MailSenderInterface and AccountSyncerInterface:
 * implementations are tagged, PushSenderRegistry picks by the subscription's
 * own transport, and no caller names one.
 *
 * **Both the payload shape and the failure vocabulary are the implementation's
 * business.** PushDispatcher hands over the StateChange as an array and is told
 * only whether it went; whether the sender encrypted it to a P-256 key or
 * wrapped it in a data message, and whether the endpoint has just proved itself
 * permanently gone, is decided by the sender, which is the only participant
 * that can read its transport's answer.
 *
 * A sender that decides a subscription will never work again REMOVES it, rather
 * than returning a distinguished value. That is the contract Web Push
 * established for a 404/410 and FCM keeps for UNREGISTERED — the alternative is
 * a failing POST on every state change until the heat death of the universe,
 * and a caller that has to remember to clean up is a caller that forgets.
 */
interface PushSenderInterface
{
    /** The transport this sender is the implementation of. */
    public function transport(): PushTransport;

    /**
     * Whether this deployment can send at all — VAPID keys present, or a
     * Firebase project configured and enabled.
     *
     * Checked before anything is attempted so an unconfigured transport is a
     * quiet no-op rather than a stream of errors, and so the Session can
     * advertise the same verdict a client will actually get.
     */
    public function isConfigured(): bool;

    /**
     * @param array<string,mixed> $payload the StateChange or PushVerification
     *                                     object, as JMAP defines it
     *
     * @return bool true when the transport accepted it; false covers "refused",
     *              "unreachable" and "the subscription has just been removed"
     *              alike, because no caller does anything different about them
     */
    public function send(PushSubscription $subscription, array $payload): bool;
}
