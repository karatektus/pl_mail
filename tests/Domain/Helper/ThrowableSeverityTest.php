<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\ThrowableSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * The one distinction a `catch (\Throwable)` cannot afford to lose.
 *
 * Sites all over this application catch everything, log it, and continue —
 * correctly, because a folder that will not list and a hub that is down are
 * ordinary weather rather than faults, and reporting each as an application
 * error would bury the dashboard. They log at info, which is below what is
 * stored by default.
 *
 * `\Throwable` also covers `\Error`, and an `\Error` is never weather. Written
 * at the same level as a network hiccup, a fatal reports itself as one and then
 * does not appear anywhere — which is exactly how a typo in ImageProxyFetcher
 * survived until it was found by reading the code.
 */
final class ThrowableSeverityTest extends TestCase
{
    /**
     * Anything a library or a peer can cause stays at the site's own level.
     *
     * All of these are `\Exception` subclasses, which is what every library
     * throws for conditions the caller is expected to survive.
     */
    #[DataProvider('routineThrowables')]
    public function testAnOrdinaryFailureKeepsTheSitesLevel(\Throwable $throwable): void
    {
        self::assertSame(LogLevel::INFO, ThrowableSeverity::level($throwable));
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function routineThrowables(): iterable
    {
        yield 'runtime'          => [new \RuntimeException('connection reset')];
        yield 'logic'            => [new \LogicException('unexpected state')];
        yield 'domain'           => [new \DomainException('no such folder')];
        yield 'plain exception'  => [new \Exception('something')];
    }

    /**
     * Every `\Error` is a bug in this codebase, and is reported as one.
     *
     * Undefined method, wrong type, wrong argument count — none of these can be
     * caused by a peer, a network or a malformed message. They can only be
     * caused by the code.
     */
    #[DataProvider('bugs')]
    public function testAProgrammingErrorIsAlwaysReportedAsAFault(\Throwable $throwable): void
    {
        self::assertSame(LogLevel::ERROR, ThrowableSeverity::level($throwable));
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function bugs(): iterable
    {
        // The literal shape of the one that got away: a call to a method that
        // does not exist raises \Error.
        yield 'undefined method' => [new \Error('Call to undefined method Nope::there()')];
        yield 'type'             => [new \TypeError('Argument #1 must be of type string')];
        yield 'value'            => [new \ValueError('Argument #1 must be greater than 0')];
        yield 'argument count'   => [new \ArgumentCountError('Too few arguments')];
        yield 'division by zero' => [new \DivisionByZeroError('Modulo by zero')];
    }

    /**
     * A site whose routine level is not info keeps its own choice — the helper
     * raises the floor, it does not impose a ceiling.
     */
    public function testTheRoutineLevelIsTheCallersToChoose(): void
    {
        self::assertSame(
            LogLevel::WARNING,
            ThrowableSeverity::level(new \RuntimeException('nope'), LogLevel::WARNING),
        );

        // ...but a bug still outranks it.
        self::assertSame(
            LogLevel::ERROR,
            ThrowableSeverity::level(new \TypeError('nope'), LogLevel::WARNING),
        );
    }
}
