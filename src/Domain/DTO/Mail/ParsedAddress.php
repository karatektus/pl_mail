<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

/**
 * One mailbox out of an address header, as MessageParser read it.
 *
 * Both halves are decoded and UTF-8 already. Neither is normalised further:
 * AddressHelper does that where a row is written, the same for every provider.
 */
final readonly class ParsedAddress
{
    public function __construct(
        public string $name,
        public string $address,
    ) {}
}
