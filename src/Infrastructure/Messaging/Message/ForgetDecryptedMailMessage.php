<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Take the decrypted text of every encrypted message of one account back off
 * the server — the account's "Keep decrypted mail on this server" was just
 * switched off. See App\Service\Crypto\DecryptedMail::forget().
 */
final readonly class ForgetDecryptedMailMessage
{
    public function __construct(
        public int $accountId,
    ) {
    }
}
