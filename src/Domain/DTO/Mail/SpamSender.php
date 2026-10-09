<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

/**
 * Who a conversation is from, for the purposes of calling it spam.
 *
 * Both halves are lower-cased and already checked: the address is a real
 * address, and the domain is the part of it after the last `@`.
 */
final readonly class SpamSender
{
    public function __construct(
        public string $address,
        public string $domain,
        /**
         * Whether the domain is a mail provider's — gmail.com, gmx.de — and so
         * shared by people who have nothing to do with each other. A filter on
         * such a domain bins a good part of anybody's address book, which is
         * why the menu puts one more click in front of it.
         */
        public bool   $sharedDomain,
    ) {
    }

    /** The domain as it is shown to a person: `@example.com`. */
    public function domainPattern(): string
    {
        return '@' . $this->domain;
    }
}
