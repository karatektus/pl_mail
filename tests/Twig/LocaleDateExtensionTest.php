<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\LocaleDateExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Dates come out in the reader's language, and in their language's order.
 *
 * The reported symptom was calendar column heads reading MON TUE WED over a
 * German interface, and "Aug 3, 13:04" on German mail. Both came from PHP's
 * date(), which has no locale: `date('D')` is "Mon" for every reader there has
 * ever been.
 *
 * The assertions that matter here are the ones about ORDER. Translating the
 * month name and leaving "Aug 3" arranged as it is would be a smaller version
 * of the same mistake — German writes the day first, and the skeleton is what
 * lets ICU decide that rather than the template.
 */
final class LocaleDateExtensionTest extends TestCase
{
    private const string INSTANT = '2026-08-03 13:04:00';

    /** @return iterable<string, array{string, string, string}> */
    public static function renderings(): iterable
    {
        // locale, skeleton, expected
        yield 'en weekday'        => ['en', 'EEE', 'Mon'];
        yield 'de weekday'        => ['de', 'EEE', 'Mo'];
        yield 'en month'          => ['en', 'MMM', 'Aug'];
        yield 'de month'          => ['de', 'MMM', 'Aug'];
        yield 'en day and month'  => ['en', 'dMMM', 'Aug 3'];
        yield 'de day and month'  => ['de', 'dMMM', '3. Aug.'];
        yield 'en full date'      => ['en', 'dMMMy', 'Aug 3, 2026'];
        yield 'de full date'      => ['de', 'dMMMy', '3. Aug. 2026'];
        yield 'en weekday date'   => ['en', 'EEEdMMM', 'Mon, Aug 3'];
        yield 'de weekday date'   => ['de', 'EEEdMMM', 'Mo., 3. Aug.'];
        yield 'en month and year' => ['en', 'yMMMM', 'August 2026'];
        yield 'de month and year' => ['de', 'yMMMM', 'August 2026'];
    }

    #[DataProvider('renderings')]
    public function testTheLocaleDecidesTheWording(string $locale, string $skeleton, string $expected): void
    {
        self::assertSame($expected, $this->extension($locale)->format($this->instant(), $skeleton, false));
    }

    /**
     * The pirate catalogue translates words, not calendars. ICU has no en_PI
     * data and resolves it to en, which is the intended behaviour rather than
     * something to work around.
     */
    public function testPirateRidesTheEnglishFormats(): void
    {
        $pirate  = $this->extension('en_PI')->format($this->instant(), 'EEEdMMM', false);
        $english = $this->extension('en')->format($this->instant(), 'EEEdMMM', false);

        self::assertSame($english, $pirate);
    }

    /**
     * The one thing that must NOT be locale-driven: whether the day number
     * comes before the month is a language question, but whether 13:04 is
     * written "1:04 pm" is a preference, and it lives in ClockGlobal. This
     * filter renders no time at all, so a skeleton asking for one gets nothing
     * rather than quietly overriding that preference.
     */
    public function testNothingHereRendersATime(): void
    {
        $rendered = $this->extension('de')->format($this->instant(), 'dMMMy', false);

        self::assertStringNotContainsString('13', $rendered);
        self::assertStringNotContainsString('04', $rendered);
    }

    public function testAnEmptyDateRendersNothingRatherThanToday(): void
    {
        self::assertSame('', $this->extension('en')->format(null, 'dMMM'));
    }

    /** A zone given explicitly moves the instant before it is written down. */
    public function testTheTimezoneCanPushItOntoAnotherDay(): void
    {
        $extension = $this->extension('en');
        $instant   = new \DateTimeImmutable('2026-08-03 23:30:00', new \DateTimeZone('UTC'));

        self::assertSame('Aug 3', $extension->format($instant, 'dMMM', 'UTC'));
        self::assertSame('Aug 4', $extension->format($instant, 'dMMM', 'Europe/Berlin'));
    }

    private function instant(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::INSTANT, new \DateTimeZone('UTC'));
    }

    private function extension(string $locale): LocaleDateExtension
    {
        $request = new Request();
        $request->setLocale($locale);

        $stack = new RequestStack();
        $stack->push($request);

        return new LocaleDateExtension($stack);
    }
}
