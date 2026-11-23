<?php

declare(strict_types=1);

namespace App\Domain\Enum\Ai;

use App\Domain\Enum\AppLocale;
use Collator;
use Symfony\Component\Intl\Locales;

/**
 * The languages a message can be translated into (#713).
 *
 * A closed list rather than every language Intl can name: the value arrives
 * from a form and is stored, so tryFrom() is the validation, and a list of six
 * hundred languages would offer mostly ones no model translates into. These are
 * the languages mail is commonly written in that the usual open models handle.
 *
 * The values are BCP 47 tags; Chinese is two, because which script a reader
 * gets is the whole difference between them.
 */
enum TranslationLanguage: string
{
    case Arabic             = 'ar';
    case Bulgarian          = 'bg';
    case Catalan            = 'ca';
    case Czech              = 'cs';
    case Danish             = 'da';
    case German             = 'de';
    case Greek              = 'el';
    case English            = 'en';
    case Spanish            = 'es';
    case Estonian           = 'et';
    case Persian            = 'fa';
    case Finnish            = 'fi';
    case French             = 'fr';
    case Hebrew             = 'he';
    case Hindi              = 'hi';
    case Croatian           = 'hr';
    case Hungarian          = 'hu';
    case Indonesian         = 'id';
    case Italian            = 'it';
    case Japanese           = 'ja';
    case Korean             = 'ko';
    case Lithuanian         = 'lt';
    case Latvian            = 'lv';
    case NorwegianBokmal    = 'nb';
    case Dutch              = 'nl';
    case Polish             = 'pl';
    case Portuguese         = 'pt';
    case Romanian           = 'ro';
    case Russian            = 'ru';
    case Slovak             = 'sk';
    case Slovenian          = 'sl';
    case Serbian            = 'sr';
    case Swedish            = 'sv';
    case Thai               = 'th';
    case Turkish            = 'tr';
    case Ukrainian          = 'uk';
    case Vietnamese         = 'vi';
    case ChineseSimplified  = 'zh-Hans';
    case ChineseTraditional = 'zh-Hant';

    /** The language's name in the reader's interface language, for the page. */
    public function label(string $displayLocale): string
    {
        return Locales::getName($this->intlCode(), $displayLocale);
    }

    /**
     * The language's name in English, for the prompt.
     *
     * English whatever the reader's interface is, because the instruction around
     * it is English; "Translate into Deutsch" asks the model to read one more
     * language than it needs to.
     */
    public function englishName(): string
    {
        return Locales::getName($this->intlCode(), 'en');
    }

    /** The default for a reader who has not chosen: their interface language. */
    public static function forInterface(?string $locale): self
    {
        return match (AppLocale::tryFromRequest($locale)) {
            AppLocale::German            => self::German,
            AppLocale::French            => self::French,
            AppLocale::SimplifiedChinese => self::ChineseSimplified,
            AppLocale::English,
            AppLocale::Pirate,
            null                         => self::English,
        };
    }

    /**
     * Every language, in the order of its name in the reader's language.
     *
     * @return list<self>
     */
    public static function sortedFor(string $displayLocale): array
    {
        $cases = self::cases();
        $collator = new Collator($displayLocale);
        usort($cases, static fn (self $a, self $b): int => (int) $collator->compare($a->label($displayLocale), $b->label($displayLocale)));

        return $cases;
    }

    /** Intl spells the script with an underscore. */
    private function intlCode(): string
    {
        return str_replace('-', '_', $this->value);
    }
}
