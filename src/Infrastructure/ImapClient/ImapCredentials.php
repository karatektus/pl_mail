<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

/**
 * Who to log in as.
 */
final readonly class ImapCredentials
{
    /**
     * @param string $secret a password, or an OAuth access token for XOAUTH2
     */
    public function __construct(
        public string            $username,
        #[\SensitiveParameter]
        public string            $secret,
        public ImapAuthMechanism $mechanism = ImapAuthMechanism::Login,
    ) {
    }

    /**
     * Kept out of var_dump() and of the arguments a stack trace prints, so a
     * logged exception from somewhere near a login cannot carry the password.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['username' => $this->username, 'mechanism' => $this->mechanism->value];
    }
}
