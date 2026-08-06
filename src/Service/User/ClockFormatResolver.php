<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Domain\Enum\AppLocale;
use App\Domain\Enum\User\ClockFormat;
use App\Entity\User\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The single answer to "does this person read a twelve-hour clock?".
 *
 * The sibling of UserTimezoneResolver, and it exists for the same reason: a
 * display decision made in one place cannot go wrong in one template and not the
 * others. That resolver's docblock is the argument in full — this one adds only
 * that a clock convention has a *second* fallback behind the install default,
 * the interface language, because "12 or 24?" has a sensible answer for a
 * language and no answer at all for a server.
 *
 * Three states, and the middle one is the important one:
 *
 *   the user chose        ClockFormat::Twelve or ::TwentyFour, stored under
 *                         User::SETTING_CLOCK
 *   the user never chose  follow the language — German reads 14:00, English
 *                         reads 2:00 pm, which is what plMail printed before
 *                         this setting existed, so nobody's app changes under
 *                         them the day it arrives
 *   no user at all        the install's configured locale, for a page rendered
 *                         to somebody not signed in
 *
 * Reading the key directly anywhere else would skip the fallback and give a
 * blank format string, which Twig renders as an empty span rather than as an
 * error.
 */
final readonly class ClockFormatResolver
{
    public function __construct(
        #[Autowire('%kernel.default_locale%')]
        private string $defaultLocale,
    ) {
    }

    public function resolve(?User $user): ClockFormat
    {
        $chosen = ClockFormat::tryFrom((string) $user?->getSetting(User::SETTING_CLOCK));

        if (null !== $chosen) {
            return $chosen;
        }

        return ClockFormat::forLocale(
            AppLocale::tryFromRequest($user?->locale)
                ?? AppLocale::tryFromRequest($this->defaultLocale),
        );
    }

    /**
     * What the picker shows as selected — the user's own choice, or null for
     * "follow the language".
     *
     * Deliberately NOT resolve(): a picker that pre-selected the resolved value
     * would turn "I never chose" into "I chose 24-hour" the first time anybody
     * saved the form, and the setting would then stop following a language
     * change.
     */
    public function chosen(?User $user): ?ClockFormat
    {
        return ClockFormat::tryFrom((string) $user?->getSetting(User::SETTING_CLOCK));
    }
}
