<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

/**
 * The composer sent a sealed draft or a built message that cannot be taken
 * as it is. The message is a translation key for the compose window.
 */
final class SealRefused extends RuntimeException
{
}
