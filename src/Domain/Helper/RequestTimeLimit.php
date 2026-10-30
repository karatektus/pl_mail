<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Raising PHP's time limit for one request, and only for that one.
 *
 * A plain set_time_limit() outlives the request under FrankenPHP's worker mode:
 * the setting is not reset between requests (measured: the request after one
 * that raised it to 90 started at 90, not 30). A single summary or file transfer
 * would have loosened that thread's limit for good, and nothing would show it
 * until something hung. hold() remembers the configured value, and release()
 * puts it back at the end of the request, from ReleaseTimeLimitSubscriber.
 *
 * Always a positive value, re-armed: see ComposeAssistController for how
 * set_time_limit(0) failed. Where the limit is already 0, as in a messenger
 * worker, there is nothing to raise, and giving such a process a limit would be
 * the bug.
 *
 * Static because the value belongs to the thread rather than to a service, and
 * each FrankenPHP thread has its own copy of a static.
 */
final class RequestTimeLimit
{
    private static ?int $configured = null;

    public static function hold(int $seconds): void
    {
        $current = (int) ini_get('max_execution_time');

        if ($current > 0) {
            self::$configured ??= $current;
            set_time_limit($seconds);
        }
    }

    /** Back to the configured limit, restarted. Harmless when nothing held it. */
    public static function release(): void
    {
        if (null !== self::$configured) {
            set_time_limit(self::$configured);
            self::$configured = null;
        }
    }
}
