<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Push;

use App\Domain\Enum\PushTransport;
use App\Domain\Interface\PushSenderInterface;
use App\Entity\User\PushSubscription;

/**
 * A sender that records rather than delivers. Named apart from the one in
 * PushDispatcherFanOutTest because both files declare a class in this namespace,
 * and a second declaration of the same name is a fatal error in one run.
 */
final class RecordingArrivalsSender implements PushSenderInterface
{
    /** @var list<PushSubscription> */
    public array $sent = [];

    public function __construct(
        private readonly PushTransport $transport,
        private readonly bool          $configured = true,
    ) {}

    public function transport(): PushTransport
    {
        return $this->transport;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(PushSubscription $subscription, array $payload): bool
    {
        $this->sent[] = $subscription;

        return true;
    }
}
