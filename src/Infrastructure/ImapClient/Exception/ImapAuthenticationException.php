<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient\Exception;

/**
 * The server refused the login.
 *
 * Apart from other refused commands because the answer to it is different:
 * trying again at once will be refused again, and on some servers counts
 * towards locking the account.
 */
final class ImapAuthenticationException extends ImapCommandException
{
}
