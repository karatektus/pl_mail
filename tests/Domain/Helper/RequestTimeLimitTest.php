<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\RequestTimeLimit;
use PHPUnit\Framework\TestCase;

/**
 * A FrankenPHP worker keeps set_time_limit() from one request to the next, so
 * what a request raises it has to put back. See RequestTimeLimit.
 */
final class RequestTimeLimitTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestTimeLimit::release();
        set_time_limit(0);
    }

    public function testReleasePutsTheConfiguredLimitBack(): void
    {
        set_time_limit(30);

        RequestTimeLimit::hold(90);
        self::assertSame(90, (int) ini_get('max_execution_time'));

        // A second hold in the same request must not take 90 for the configured value.
        RequestTimeLimit::hold(300);
        self::assertSame(300, (int) ini_get('max_execution_time'));

        RequestTimeLimit::release();
        self::assertSame(30, (int) ini_get('max_execution_time'));
    }

    public function testAReleaseWithNothingHeldChangesNothing(): void
    {
        set_time_limit(45);

        RequestTimeLimit::release();

        self::assertSame(45, (int) ini_get('max_execution_time'));
    }

    public function testAProcessWithNoLimitIsNotGivenOne(): void
    {
        set_time_limit(0);

        RequestTimeLimit::hold(300);

        self::assertSame(0, (int) ini_get('max_execution_time'));
    }
}
