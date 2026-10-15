<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

/**
 * A decrypted copy of an encrypted message that cannot be taken: the account
 * does not keep decrypted mail, the message is not one, or what came is not a
 * MIME message. The message is for the log; the browser is told 422.
 */
final class DecryptedMailRefused extends RuntimeException
{
}
