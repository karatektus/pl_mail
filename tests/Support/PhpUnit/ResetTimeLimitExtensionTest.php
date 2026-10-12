<?php

declare(strict_types=1);

namespace App\Tests\Support\PhpUnit;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * A time limit one test sets is gone by the next one.
 *
 * Two tests in a fixed order, because the claim is about what one test leaves
 * behind for the next: the first does what a streaming controller does, the
 * second looks. Without ResetTimeLimitExtension registered in phpunit.dist.xml
 * the second sees the first's 300 seconds and fails.
 */
final class ResetTimeLimitExtensionTest extends TestCase
{
    public function testAControllerTestSetsALimitTheWayTheStreamingEndpointsDo(): void
    {
        set_time_limit(300);

        self::assertSame('300', ini_get('max_execution_time'));
    }

    #[Depends('testAControllerTestSetsALimitTheWayTheStreamingEndpointsDo')]
    public function testTheNextTestRunsWithoutIt(): void
    {
        self::assertSame('0', ini_get('max_execution_time'), 'a time limit set by one test is still counting in the next');
    }
}
