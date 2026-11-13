<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Execution;

/**
 * The process handling a message ended without finishing it: killed at the
 * deadline, crashed, or never started. See ChildProcessExecutionStrategy.
 *
 * A plain RuntimeException on purpose, neither Recoverable nor Unrecoverable:
 * the Worker hands it to the transport's retry ladder like any other failure,
 * and that is exactly what should happen to a batch whose child hung — a
 * delay, another try, and the failed queue if it keeps happening.
 */
final class ChildProcessFailedException extends \RuntimeException
{
}
