<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * How many times a job has been put back to wait out a provider's rate limit.
 *
 * A stamp rather than a field on the message: it is a fact about the delivery
 * and not about the work, it applies to more than one message class, and
 * envelopes already on a transport must keep deserialising — which a new
 * constructor argument on each of them would put at risk.
 *
 * See GiveUpQuietlyOnThrottling, the only reader and the only writer.
 */
final readonly class ThrottleDeferralStamp implements StampInterface
{
    public function __construct(public int $count)
    {
    }
}
