<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use Psr\Log\LogLevel;
use Throwable;

/**
 * How loudly a caught throwable deserves to be reported.
 *
 * WHY THIS EXISTS
 * ───────────────
 * A great many places in this application catch `\Throwable`, log it, and carry
 * on. That is usually right: a folder that will not list, a Mercure hub that is
 * down, a message already deleted upstream — none of them are faults in plMail,
 * all of them are ordinary weather on a mail server, and reporting each one as
 * an application error would fill the dashboard with things nobody can act on.
 * They are logged at info, and the handbook tells an administrator to lower
 * APP_DB_LOG_LEVEL when they want to see that traffic.
 *
 * The problem is that `\Throwable` also covers `\Error`, and an `\Error` is
 * never weather. It is a call to an undefined method, an argument of the wrong
 * type, a class that does not exist — always, without exception, a bug in this
 * codebase. Caught by the same clause and written at the same level, one of
 * those reports itself as a routine network hiccup and then, being below the
 * stored threshold, does not appear anywhere at all.
 *
 * That is not hypothetical. It is how a typo in ImageProxyFetcher survived long
 * enough to be found by hand: a fatal, logged as "fetch failed", invisible.
 *
 * So: the same catch, the same control flow, the same swallowing — and a level
 * chosen from what was actually caught.
 *
 * WHAT THIS IS NOT
 * ────────────────
 * Not a general severity oracle. Library exceptions are all `\Exception`
 * subclasses, so this cannot tell a genuine outage from a malformed header, and
 * it does not try. Where a site CAN name the exceptions it expects — as
 * ImageProxyFetcher does with the HTTP client's own interface — naming them is
 * sharper than this and should be preferred. This is the floor, for the many
 * places that legitimately cannot.
 */
final class ThrowableSeverity
{
    /**
     * @param string $routine the level this site uses when the failure is the
     *                        ordinary kind it was written to tolerate
     *
     * @return string a PSR-3 log level
     */
    public static function level(Throwable $throwable, string $routine = LogLevel::INFO): string
    {
        return $throwable instanceof \Error ? LogLevel::ERROR : $routine;
    }
}
