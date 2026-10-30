<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Service\Integration\Driver\HttpLimits;

/**
 * What every driver owes #449, checked on the requests it really sent: nothing
 * reaches the network without a limit, and only a file is allowed to take long.
 */
trait AssertsHttpLimits
{
    /** @param list<array{options: array<string, mixed>}> $requests */
    private static function assertEveryRequestIsACall(array $requests): void
    {
        self::assertNotSame([], $requests);

        foreach ($requests as $request) {
            self::assertSame(HttpLimits::CALL['timeout'], $request['options']['timeout']);
            self::assertSame(HttpLimits::CALL['max_duration'], $request['options']['max_duration']);
        }
    }

    /** @param list<array{options: array<string, mixed>}> $requests */
    private static function assertARequestIsATransfer(array $requests): void
    {
        foreach ($requests as $request) {
            if (0.0 === ($request['options']['max_duration'] ?? null) && is_callable($request['options']['on_progress'] ?? null)) {
                return;
            }
        }

        self::fail('No request moved its file under HttpLimits::transfer().');
    }
}
