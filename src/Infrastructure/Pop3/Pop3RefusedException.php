<?php

declare(strict_types=1);

namespace App\Infrastructure\Pop3;

/**
 * The server answered, and the answer was -ERR.
 *
 * Kept apart from a dropped or silent connection because the two call for
 * different things. A RETR the server refuses is about that one message: the
 * session is still good and the rest of the batch can be fetched. A connection
 * that dropped fails every command after it, and counting each of those as a
 * failed attempt at its message would give up on messages that were never the
 * problem.
 */
final class Pop3RefusedException extends Pop3Exception
{
}
