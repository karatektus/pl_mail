<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

/**
 * A write aimed at a list the remote does not accept writes on.
 *
 * Models what the caller should do: nothing, and say so. The pane offers no
 * control for it, so reaching this means a stale page or a forged post.
 */
final class TaskListReadOnlyException extends RuntimeException
{
}
