<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * A JMAP server's PushVerification, to be echoed back to it.
 *
 * Queued by the webhook rather than answered there: confirming is a call to
 * the server, and the webhook answers a server that is waiting on it. See
 * JmapPushSubscriptionManager::confirm().
 */
final readonly class ConfirmJmapPushMessage
{
    public function __construct(
        public int    $accountId,
        public string $subscriptionId,
        public string $verificationCode,
    ) {
    }
}
