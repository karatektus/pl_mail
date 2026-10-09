<?php

declare(strict_types=1);

namespace App\Domain\Enum\Template;

use IntlDateFormatter;

/**
 * The ready-made ways a date variable can be written.
 *
 * Four of them are the locale's own — ICU decides that "long" is
 * "14 October 2026" in English and "14. Oktober 2026" in German — which is the
 * point: a template written once reads correctly whichever language the app is
 * in. The other two are fixed patterns, because "the weekday" and an ISO date
 * are things people ask for by shape.
 *
 * Anything else is a custom ICU pattern, written `format=pattern:dd.MM.yyyy` in
 * the token; that is not a case here because it is not a preset.
 */
enum DateFormatPreset: string
{
    case Short   = 'short';
    case Medium  = 'medium';
    case Long    = 'long';
    case Full    = 'full';
    case Weekday = 'weekday';
    case Iso     = 'iso';

    /** The IntlDateFormatter date style, for the presets the locale decides. */
    public function intlStyle(): int
    {
        return match ($this) {
            self::Short                => IntlDateFormatter::SHORT,
            self::Medium               => IntlDateFormatter::MEDIUM,
            self::Long                 => IntlDateFormatter::LONG,
            self::Full                 => IntlDateFormatter::FULL,
            // Unused for these two: pattern() answers instead.
            self::Weekday, self::Iso   => IntlDateFormatter::NONE,
        };
    }

    /** The fixed ICU pattern, for the presets that are a shape rather than a style. */
    public function pattern(): ?string
    {
        return match ($this) {
            self::Short, self::Medium, self::Long, self::Full => null,
            self::Weekday                                     => 'EEEE',
            self::Iso                                         => 'yyyy-MM-dd',
        };
    }
}
