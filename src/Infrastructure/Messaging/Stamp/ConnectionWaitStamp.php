<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * How many times an account sync has been put back because the mail provider
 * could not be reached at all.
 *
 * Its own stamp rather than ThrottleDeferralStamp: the two count different
 * things against different limits, and a sync that waited out a quota and
 * then met a network outage must not arrive at the second with the first's
 * count already spent.
 *
 * See WaitOutConnectionFailures, the only reader and the only writer.
 */
final readonly class ConnectionWaitStamp implements StampInterface
{
    public function __construct(public int $count)
    {
    }
}
