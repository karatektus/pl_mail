<?php

declare(strict_types=1);

namespace App\Encryption;

use RuntimeException;

/**
 * Raised when a value cannot be encrypted or decrypted — a malformed or
 * missing APP_ENCRYPTION_KEY, or a ciphertext that fails its integrity check.
 *
 * Deliberately never carries the offending value in its message: these
 * exceptions reach the logs, and the whole point of the class that throws them
 * is to keep credentials out of anything readable.
 */
final class EncryptionException extends RuntimeException
{
}
