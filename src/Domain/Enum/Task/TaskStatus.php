<?php

declare(strict_types=1);

namespace App\Domain\Enum\Task;

/**
 * Where a task stands, in the four words VTODO has for it (RFC 5545 §3.8.1.11).
 *
 * Four rather than a done/not-done bool, because two of them only ever arrive
 * from somewhere else and plMail has no control that sets them. A task another
 * client marked IN-PROCESS and that plMail then renamed must go back out still
 * saying IN-PROCESS; a bool would have rounded it to NEEDS-ACTION on the first
 * edit, which is exactly the foreign data the design promises to keep.
 */
enum TaskStatus: string
{
    case NeedsAction = 'needs-action';

    /** Only from another client; plMail reads it as open and writes it back. */
    case InProcess = 'in-process';

    case Completed = 'completed';

    /** Only from another client; plMail reads it as finished and writes it back. */
    case Cancelled = 'cancelled';

    /** Whether the task still wants doing — what every list and My Day ask. */
    public function isOpen(): bool
    {
        return match ($this) {
            self::NeedsAction, self::InProcess => true,
            self::Completed, self::Cancelled   => false,
        };
    }
}
