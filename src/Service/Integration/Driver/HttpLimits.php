<?php

declare(strict_types=1);

namespace App\Service\Integration\Driver;

use App\Domain\Helper\RequestTimeLimit;
use RuntimeException;

/**
 * How long a driver waits for a service before saying it could not be reached.
 *
 * Without these the only limit was PHP's max_execution_time, and reaching that
 * is not an error the picker can show. It is a fatal raised inside curl, no
 * catch runs, and the FrankenPHP worker thread goes with it (#449). A self-hosted
 * Paperless that lost its network but still accepts connections never answers,
 * and that was a 500 page rather than "could not reach the server".
 *
 * Two shapes, because the two kinds of request fail differently:
 *
 *   CALL is a listing, a lookup, a thumbnail: small, and nobody is waiting for
 *   it to finish slowly. Bounded both ways, and well inside PHP's limit even
 *   when a page makes two in a row against a dead host, as browse() does.
 *
 *   transfer() moves a file, for as long as the file is moving. A size-based
 *   guess at a ceiling would be wrong for somebody, so there is none: it runs
 *   until no byte has moved in either direction for IDLE_SECONDS.
 *
 * Why transfer() does not simply use the client's `timeout`, which is documented
 * as an idle timeout: it only counts bytes coming BACK. An upload that was going
 * perfectly well was cut off after `timeout` seconds, because the service has
 * nothing to say until it has the whole file. Measured against a real slow
 * reader. So `timeout` is set out of the way, and on_progress, which sees both
 * directions, does the job instead.
 *
 * The same callback holds PHP's limit off while bytes move. A positive value, re-armed,
 * and not set_time_limit(0): see ComposeAssistController for how 0 failed. Above
 * IDLE_SECONDS, so that the watchdog gives up first and the person gets a reason
 * instead of a fatal.
 * */
final class HttpLimits
{
    /** @var array{timeout: float, max_duration: float} */
    public const array CALL = ['timeout' => 10.0, 'max_duration' => 20.0];

    /**
     * Generous, because a service can go quiet with the whole file in hand:
     * it answers an upload after hashing or storing it, not during.
     */
    public const float IDLE_SECONDS = 60.0;

    /**
     * For code that runs both inside a web request and in a worker, such as a
     * DAV client: CALL under PHP's limit, and nothing where there is no limit.
     * A worker has no fatal to get ahead of, the client's default of 60 seconds
     * already ends a hang there, and a large sync report may be slow to start.
     *
     * @return array{timeout?: float, max_duration?: float}
     */
    public static function forThisProcess(): array
    {
        return (int) ini_get('max_execution_time') > 0 ? self::CALL : [];
    }

    private const int PHP_LIMIT_SECONDS = 90;

    /** Never reached: the callback below always gives up first. */
    private const float CLIENT_TIMEOUT_SECONDS = 86400.0;

    /**
     * Options for one request that moves a file. Call it for each request: the
     * callback remembers what that request has moved so far.
     *
     * Throwing from on_progress cancels the transfer, and the client hands it
     * back as a TransportException, which the drivers already translate.
     *
     * @return array{timeout: float, max_duration: float, on_progress: callable(int, int, array<string, mixed>): void}
     */
    public static function transfer(float $idleSeconds = self::IDLE_SECONDS): array
    {
        RequestTimeLimit::hold(self::PHP_LIMIT_SECONDS);

        $moved  = null;
        $lastAt = microtime(true);

        return [
            'timeout'      => self::CLIENT_TIMEOUT_SECONDS,
            'max_duration' => 0.0,
            'on_progress'  => static function (int $downloaded, int $size, array $info) use (&$moved, &$lastAt, $idleSeconds): void {
                $now   = microtime(true);
                $total = $downloaded + (int) ($info['size_upload'] ?? 0);

                if ($total !== $moved) {
                    $moved  = $total;
                    $lastAt = $now;

                    RequestTimeLimit::hold(self::PHP_LIMIT_SECONDS);

                    return;
                }

                if ($now - $lastAt >= $idleSeconds) {
                    throw new RuntimeException(sprintf('No data moved for %d seconds.', (int) $idleSeconds));
                }
            },
        ];
    }
}
