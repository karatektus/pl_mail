<?php

declare(strict_types=1);

namespace App\Domain\Filter;

/**
 * A rule's condition tree is malformed or uses something rules do not support.
 *
 * Messages are written for the person building the filter, not for a log —
 * they surface directly as a form error.
 */
final class InvalidFilterException extends \RuntimeException
{
}
