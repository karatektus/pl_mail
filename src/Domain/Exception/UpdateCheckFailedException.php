<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * An update check that got no answer: the registry or the history refused,
 * could not be reached, or answered with something that is not an image.
 *
 * The message is shown to the administrator as it is, so it says what was
 * asked and what came back rather than how the code got there.
 */
final class UpdateCheckFailedException extends \RuntimeException
{
}
