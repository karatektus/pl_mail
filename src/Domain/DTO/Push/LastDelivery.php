<?php

declare(strict_types=1);

namespace App\Domain\DTO\Push;

use App\Domain\Enum\PushDeliveryOutcome;
use DateTimeImmutable;

/**
 * The most recent thing that happened to one device, for the line under it in
 * the user's notification settings.
 *
 * A DTO rather than the PushDelivery entity, because the query that produces
 * it cannot hydrate one: the newest row per device is a `DISTINCT ON`, and what
 * comes back is one row per device rather than a set of managed entities. The
 * alternative was passing raw rows into a template, where `outcome` would be a
 * string and the enum's own tone() — the thing that decides whether the line is
 * drawn red — would not be reachable at all.
 *
 * Deliberately does not carry the payload's contents. It carries its `@type`,
 * for the same reason the entity does: a user looking at their own devices is
 * entitled to know whether the last thing sent was the verification handshake
 * or ordinary traffic, and to nothing further.
 */
final readonly class LastDelivery
{
    public function __construct(
        public string              $deviceClientId,
        public PushDeliveryOutcome $outcome,
        public DateTimeImmutable   $at,
        public ?string             $payloadType,
    ) {}
}
