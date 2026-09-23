<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Controller\Calendar\CalendarController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The calendar editor's `returnTo` is an open-redirect check.
 *
 * `/\t/evil.test` got past the old `//` prefix test, and a browser strips the
 * tab before parsing, so it followed `//evil.test` — another host.
 */
final class SafeReturnToTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function offsite(): iterable
    {
        yield 'scheme-relative'   => ['//evil.test'];
        yield 'tab smuggled'      => ["/\t/evil.test"];
        yield 'newline smuggled'  => ["/\n/evil.test"];
        yield 'backslash'         => ['/\\evil.test'];
        yield 'absolute'          => ['https://evil.test/'];
        yield 'empty'             => [''];
    }

    #[DataProvider('offsite')]
    public function testAnOffsiteTargetIsRefused(string $candidate): void
    {
        self::assertNull($this->safeReturnTo($candidate));
    }

    public function testAPathOnThisHostIsKept(): void
    {
        self::assertSame('/mail/inbox?pane=1', $this->safeReturnTo('/mail/inbox?pane=1'));
    }

    private function safeReturnTo(string $candidate): ?string
    {
        $controller = new ReflectionClass(CalendarController::class)->newInstanceWithoutConstructor();

        return new \ReflectionMethod(CalendarController::class, 'safeReturnTo')->invoke($controller, $candidate);
    }
}
