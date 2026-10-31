<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

/**
 * A contacts state token this server cannot place: malformed, ahead of the log,
 * or older than what it kept. JMAP answers it with cannotCalculateChanges, and
 * the client reads everything again.
 */
final class ContactStateTokenException extends RuntimeException
{
}
