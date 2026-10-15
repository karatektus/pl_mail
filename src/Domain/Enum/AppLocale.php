<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Interface locales plMail ships translations for.
 *
 * The case values must match the suffixes of translations/messages.*.yaml and
 * the framework.enabled_locales list in config/packages/translation.yaml.
 */
enum AppLocale: string
{
    case English = 'en';
    case German = 'de';
    case French = 'fr';
    case Pirate = 'en_PI';

    /**
     * Name of the language, written in that language.
     */
    public function nativeLabel(): string
    {
        return match ($this) {
            self::English => 'English',
            self::German => 'Deutsch',
            self::French => 'Français',
            self::Pirate => 'Pirate English',
        };
    }

    /**
     * Flag-ish glyph for the picker. Pirates fly their own colours.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::English => '🇬🇧',
            self::German => '🇩🇪',
            self::French => '🇫🇷',
            self::Pirate => '🏴‍☠️',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $locale): string => $locale->value, self::cases());
    }

    public static function tryFromRequest(?string $locale): ?self
    {
        if (null === $locale || '' === $locale) {
            return null;
        }

        return self::tryFrom($locale);
    }
}
