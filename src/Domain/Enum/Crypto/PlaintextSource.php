<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * Where the readable text of an end-to-end encrypted message came from.
 *
 *   None    the server holds none — the default, and all an encrypted message
 *           ever has unless its account stores decrypted mail
 *   Client  a browser decrypted it and handed it over, because the person
 *           switched on "Keep decrypted mail on this server" for the account
 *           (App\Service\Crypto\DecryptedMail)
 *
 * Every step that reads a body can ask this, and the ones that would send it
 * to a model do (DecryptedMailPolicy::allowsAi()).
 */
enum PlaintextSource: string
{
    case None   = 'none';
    case Client = 'client';
}
