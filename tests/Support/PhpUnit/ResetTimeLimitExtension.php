<?php

declare(strict_types=1);

namespace App\Tests\Support\PhpUnit;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Gives the time limit back after every test.
 *
 * Four controllers stream for longer than PHP's default allows and call
 * set_time_limit() to say so — the JMAP event source, the thread summary, the
 * compose assistant and the AI warm-up. In a web request that is right: the
 * limit dies with the request. In the suite it does not, because every test
 * runs in the one PHPUnit process, and a limit set by a controller test is
 * still counting for every test after it. On a fast machine the rest of the
 * suite finished inside it; on a slower one the run died part-way with
 * "Maximum execution time of 300 seconds exceeded", in whichever unrelated
 * test happened to be running — CalendarPullerTest one time, GraphFolderMoveTest
 * the next — and passed again when that test was run on its own.
 *
 * In the suite rather than in the controllers: the four calls are correct, and
 * a seam in production code to hide them from tests would be four places to
 * get wrong for the sake of one place that is. ResetTimeLimitExtensionTest
 * holds it.
 */
final class ResetTimeLimitExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements FinishedSubscriber {
            public function notify(Finished $event): void
            {
                // 0 is the CLI default: no limit. set_time_limit() also
                // restarts the clock, which is what a limit somebody else set
                // needs.
                set_time_limit(0);
            }
        });
    }
}
