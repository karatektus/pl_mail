<?php

declare(strict_types=1);

namespace App\Tests\Service\User;

use App\Domain\Enum\User\ClockFormat;
use App\Entity\User\User;
use App\Service\User\ClockFormatResolver;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Twelve-hour or twenty-four, and where the answer comes from when nobody has
 * said.
 *
 * Three states, and the middle one is the one worth a test: "never chose" has
 * to keep meaning "never chose". A resolver that collapsed it into whichever
 * format it happens to produce would make the preference stop following a
 * language change the first time anything saved it, and nothing about that
 * would look wrong.
 *
 * The format strings themselves are asserted here too. They are read by name
 * from a dozen templates, and a wrong one is not an error — Twig prints
 * whatever `format()` makes of it, so a typo becomes a plausible-looking time
 * on every page.
 */
final class ClockFormatResolverTest extends TestCase
{
    public function testAChosenFormatWins(): void
    {
        $user = new User();
        $user->locale = 'de';
        $user->setSetting(User::SETTING_CLOCK, '12');

        self::assertSame(ClockFormat::Twelve, $this->resolver()->resolve($user));
    }

    /**
     * The default, and the reason nobody's app changed the day this shipped:
     * English kept the twelve-hour clock plMail had always printed.
     */
    public function testAUserWhoNeverChoseFollowsTheirLanguage(): void
    {
        $german = new User();
        $german->locale = 'de';

        $english = new User();
        $english->locale = 'en';

        self::assertSame(ClockFormat::TwentyFour, $this->resolver()->resolve($german));
        self::assertSame(ClockFormat::Twelve, $this->resolver()->resolve($english));
    }

    /** A user with no locale of their own follows the install's. */
    public function testNoLanguageOfTheirOwnFallsBackToTheInstallDefault(): void
    {
        self::assertSame(ClockFormat::TwentyFour, $this->resolver('de')->resolve(new User()));
        self::assertSame(ClockFormat::Twelve, $this->resolver('en')->resolve(new User()));
    }

    /** A page rendered to nobody still has to print a time. */
    public function testAnAnonymousRequestGetsTheInstallDefault(): void
    {
        self::assertSame(ClockFormat::TwentyFour, $this->resolver('de')->resolve(null));
    }

    /**
     * A value the enum does not know — hand-edited into the jsonb bag, or left
     * over from a format that no longer exists. It falls back rather than
     * producing a blank format string, which Twig renders as an empty span
     * instead of as an error.
     */
    public function testAnUnknownStoredValueFallsBackRatherThanBlanking(): void
    {
        $user = new User();
        $user->locale = 'de';
        $user->setSetting(User::SETTING_CLOCK, 'roman-numerals');

        self::assertSame(ClockFormat::TwentyFour, $this->resolver()->resolve($user));
    }

    /**
     * What the picker shows, which is deliberately NOT the resolved value: it
     * has to be able to say "you have not chosen".
     */
    public function testThePickerCanTellNeverChoseFromChose(): void
    {
        $resolver = $this->resolver();

        $undecided = new User();
        $undecided->locale = 'de';

        $decided = new User();
        $decided->setSetting(User::SETTING_CLOCK, '24');

        self::assertNull($resolver->chosen($undecided), 'following the language is not a choice');
        self::assertSame(ClockFormat::TwentyFour, $resolver->chosen($decided));
    }

    /** The three shapes, on a time that distinguishes all of them. */
    public function testTheFormatStringsAreWhatTheyClaimToBe(): void
    {
        $at = new DateTimeImmutable('2026-08-04 09:30', new DateTimeZone('UTC'));

        self::assertSame('9:30 am', $at->format(ClockFormat::Twelve->time()));
        self::assertSame('09:30', $at->format(ClockFormat::TwentyFour->time()));

        self::assertSame('9:30', $at->format(ClockFormat::Twelve->timeCompact()));
        self::assertSame('09:30', $at->format(ClockFormat::TwentyFour->timeCompact()));

        self::assertSame('9 am', $at->format(ClockFormat::Twelve->hour()));
        self::assertSame('09:30', $at->format(ClockFormat::TwentyFour->hour()));
    }

    private function resolver(string $defaultLocale = 'en'): ClockFormatResolver
    {
        return new ClockFormatResolver($defaultLocale);
    }
}
