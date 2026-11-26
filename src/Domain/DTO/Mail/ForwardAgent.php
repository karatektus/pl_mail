<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

/**
 * Somebody a mail can be handed to with "Forward to agent": a name to pick
 * from and the address the forward goes to.
 *
 * An agent is anything that reads email — a bot with a mailbox, a relay into
 * some other system, or a person. plMail knows nothing else about it.
 */
final readonly class ForwardAgent
{
    public function __construct(
        public string $name,
        public string $address,
    ) {
    }
}
