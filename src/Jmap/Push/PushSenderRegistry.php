<?php

declare(strict_types=1);

namespace App\Jmap\Push;

use App\Domain\Enum\PushTransport;
use App\Domain\Interface\PushSenderInterface;
use App\Entity\User\PushSubscription;

/**
 * The sender that delivers to a given subscription, chosen by the transport
 * stored on the row.
 *
 * Same shape as MailSenderRegistry, with one deliberate difference: the lookup
 * is by an enum rather than by a `supports()` scan, because the transport is a
 * property of the subscription rather than an interpretation of it. A scan
 * would let two senders claim the same row and resolve the conflict by
 * injection order.
 *
 * Missing rather than throwing when nothing implements a transport: a
 * subscription whose transport has no sender registered is a deployment problem
 * and not this user's, and a push is an optimisation over polling — failing the
 * request that happened to flush a state change would turn a configuration
 * error into an outage.
 */
final class PushSenderRegistry
{
    /** @var array<string,PushSenderInterface> */
    private array $byTransport = [];

    /**
     * @param iterable<PushSenderInterface> $senders
     */
    public function __construct(iterable $senders)
    {
        foreach ($senders as $sender) {
            $this->byTransport[$sender->transport()->value] = $sender;
        }
    }

    public function for(PushSubscription $subscription): ?PushSenderInterface
    {
        return $this->byTransport[$subscription->transport->value] ?? null;
    }

    public function of(PushTransport $transport): ?PushSenderInterface
    {
        return $this->byTransport[$transport->value] ?? null;
    }

    /**
     * Whether any transport at all can deliver right now. PushDispatcher asks
     * before it resolves a single account owner, so an install with neither
     * VAPID keys nor Firebase does no work per request rather than one query
     * per changed account.
     */
    public function anyConfigured(): bool
    {
        foreach ($this->byTransport as $sender) {
            if (true === $sender->isConfigured()) {
                return true;
            }
        }

        return false;
    }
}
