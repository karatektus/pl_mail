<?php

declare(strict_types=1);

namespace App\Domain\DTO\Crypto;

use App\Domain\Enum\Crypto\WkdCheckResult;

/**
 * Both Web Key Directory methods, asked from outside for one of the person's
 * addresses (App\Service\Crypto\WkdDirectory::check()), each with its own
 * verdict — a mail program may try either, so either can be the one that is
 * broken.
 */
final readonly class WkdCheck
{
    public function __construct(
        public string         $email,
        public WkdCheckResult $advanced,
        public WkdCheckResult $direct,
    ) {}

    /**
     * For the flash bag, which keeps scalars across the redirect.
     *
     * @return array{email: string, advanced: string, direct: string}
     */
    public function toArray(): array
    {
        return ['email' => $this->email, 'advanced' => $this->advanced->value, 'direct' => $this->direct->value];
    }
}
