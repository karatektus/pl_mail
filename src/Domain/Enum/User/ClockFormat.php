<?php

declare(strict_types=1);

namespace App\Domain\Enum\User;

use App\Domain\Enum\AppLocale;

/**
 * Twelve-hour or twenty-four-hour, and the format strings that follow from it.
 *
 * A display preference in the same family as the locale and the timezone: it
 * changes no stored value and nothing but the digits a page prints.
 *
 * **The formats live on the enum, not at the call sites.** plMail prints a clock
 * time in a dozen templates — a mail row, a thread, a chip, an agenda line, the
 * grid's gutter, the topbar's tooltip — and a preference honoured in eleven of
 * them is worse than one honoured in none: the user changes the setting, most of
 * the app moves, and the one that did not looks broken rather than unimplemented.
 * So there are exactly three shapes and every template asks for one of them by
 * name.
 *
 * `null` for a user's stored value means "follow the language", which is
 * `forLocale()` below — the state everyone is in until they open Settings, and
 * the reason the picker's first option is not blank.
 */
enum ClockFormat: string
{
    case Twelve     = '12';
    case TwentyFour = '24';

    /**
     * A time on its own: "9:30 am", or "09:30".
     *
     * The meridiem is lower-case and spaced, which is what the mail list has
     * always printed and therefore what the rest of the app matches.
     */
    public function time(): string
    {
        return match ($this) {
            self::Twelve     => 'g:i a',
            self::TwentyFour => 'H:i',
        };
    }

    /**
     * The same time where the surrounding context already says which half of the
     * day it is, or where there is no room to say it: "9:30", "09:30".
     *
     * Only the chip in a dense grid uses this, and it exists because a meridiem
     * costs a fifth of the width of a month cell. Note that the two answers are
     * NOT the same string — 24-hour keeps its leading zero so a column of times
     * stays aligned, and 12-hour drops it because "09:30 pm" is not how anybody
     * writes it.
     */
    public function timeCompact(): string
    {
        return match ($this) {
            self::Twelve     => 'g:i',
            self::TwentyFour => 'H:i',
        };
    }

    /**
     * An hour label on an axis: "9 am", or "09:00".
     *
     * The time-grid's gutter, where every label is on the hour and printing
     * ":00" twenty-four times in the 12-hour form is noise.
     */
    public function hour(): string
    {
        return match ($this) {
            self::Twelve     => 'g a',
            self::TwentyFour => 'H:i',
        };
    }

    /**
     * What a user who has never chosen reads.
     *
     * From the interface language rather than from the timezone, because the
     * convention is a property of how people write, not of where they are: a
     * German speaker in Chicago writes 14:00. English gets the twelve-hour form
     * plMail has always printed, so nobody's app changes under them the day this
     * setting arrives.
     */
    public static function forLocale(?AppLocale $locale): self
    {
        return match ($locale) {
            AppLocale::German => self::TwentyFour,
            default           => self::Twelve,
        };
    }
}
