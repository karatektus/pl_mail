<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Service\Integration\Driver\HttpLimits;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * A file may take as long as it keeps moving, and not a moment after it stops.
 *
 * The first test uses a real socket, because what it guards is how the client
 * behaves: a listening socket nobody accepts from is exactly the host in #449,
 * one that takes the connection and never says anything.
 */
final class HttpLimitsTest extends TestCase
{
    protected function tearDown(): void
    {
        HttpLimits::release();
        set_time_limit(0);
    }

    public function testATransferToAHostThatNeverAnswersFailsAsAClientError(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);

        $started = microtime(true);

        try {
            new CurlHttpClient()
                ->request('GET', 'http://'.stream_socket_get_name($server, false).'/', HttpLimits::transfer(0.5))
                ->getContent();

            self::fail('A silent host must not count as an answer.');
        } catch (ExceptionInterface $e) {
            // The type the drivers translate into "could not reach the server".
            self::assertStringContainsString('No data moved', $e->getMessage());
        } finally {
            fclose($server);
        }

        self::assertLessThan(5.0, microtime(true) - $started);
    }

    public function testBytesInEitherDirectionKeepATransferAlive(): void
    {
        $progress = HttpLimits::transfer(0.05)['on_progress'];

        foreach ([[0, 0], [10, 0], [10, 20], [30, 20]] as [$downloaded, $uploaded]) {
            usleep(60_000);
            $progress($downloaded, 0, ['size_upload' => $uploaded]);
        }

        $this->addToAssertionCount(1);
    }

    public function testATransferThatStopsMovingIsCancelled(): void
    {
        $progress = HttpLimits::transfer(0.05)['on_progress'];
        $progress(10, 0, ['size_upload' => 20]);

        usleep(60_000);

        $this->expectException(RuntimeException::class);
        $progress(10, 0, ['size_upload' => 20]);
    }

    /**
     * Past the idle limit, so the watchdog gives a reason before PHP's fatal,
     * and renewed by traffic rather than set once.
     */
    public function testPhpsLimitIsHeldOffWhileBytesMove(): void
    {
        set_time_limit(30);

        $progress = HttpLimits::transfer()['on_progress'];
        self::assertGreaterThan(HttpLimits::IDLE_SECONDS, (int) ini_get('max_execution_time'));

        set_time_limit(30);
        $progress(1, 0, []);
        self::assertGreaterThan(HttpLimits::IDLE_SECONDS, (int) ini_get('max_execution_time'));

        set_time_limit(30);
        $progress(1, 0, []);
        self::assertSame(30, (int) ini_get('max_execution_time'), 'no traffic, nothing renewed');
    }

    /** A worker keeps the setting from one request to the next. */
    public function testReleasePutsTheConfiguredLimitBack(): void
    {
        set_time_limit(30);

        HttpLimits::transfer()['on_progress'](1, 0, []);
        HttpLimits::transfer()['on_progress'](2, 0, []);
        self::assertGreaterThan(30, (int) ini_get('max_execution_time'));

        HttpLimits::release();
        self::assertSame(30, (int) ini_get('max_execution_time'));

        // And only once: a request that moved nothing has nothing to undo.
        set_time_limit(45);
        HttpLimits::release();
        self::assertSame(45, (int) ini_get('max_execution_time'));
    }

    /** A messenger worker has no limit, and must not be handed one. */
    public function testAProcessWithNoLimitIsNotGivenOne(): void
    {
        set_time_limit(0);

        HttpLimits::transfer()['on_progress'](1, 0, []);

        self::assertSame(0, (int) ini_get('max_execution_time'));
    }
}
