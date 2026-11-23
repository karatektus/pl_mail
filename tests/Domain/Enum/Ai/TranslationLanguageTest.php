<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Ai;

use App\Domain\Enum\Ai\TranslationLanguage;
use App\Domain\Enum\AppLocale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The languages a message can be translated into (#713) can be named on every
 * page plMail has, including the pirate one Intl has never heard of, and in
 * English for the prompt.
 */
final class TranslationLanguageTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function interfaces(): iterable
    {
        foreach (AppLocale::cases() as $locale) {
            yield $locale->value => [$locale->value];
        }
    }

    #[DataProvider('interfaces')]
    public function testEveryLanguageHasANameOnEveryPage(string $locale): void
    {
        $labels = array_map(static fn (TranslationLanguage $language): string => $language->label($locale), TranslationLanguage::cases());

        foreach ($labels as $index => $label) {
            self::assertNotSame('', trim($label), TranslationLanguage::cases()[$index]->value);
            self::assertNotSame(TranslationLanguage::cases()[$index]->value, $label, 'a code is not a name');
        }

        self::assertCount(count($labels), array_unique($labels), 'two languages with one name cannot be told apart');
        self::assertCount(count(TranslationLanguage::cases()), TranslationLanguage::sortedFor($locale));
    }

    public function testThePromptNamesTheLanguageInEnglish(): void
    {
        self::assertSame('Hebrew', TranslationLanguage::Hebrew->englishName());
        self::assertSame('Russian', TranslationLanguage::Russian->englishName());
        self::assertStringContainsString('Traditional', TranslationLanguage::ChineseTraditional->englishName());
    }

    public function testTheDefaultIsTheInterfaceLanguage(): void
    {
        self::assertSame(TranslationLanguage::German, TranslationLanguage::forInterface('de'));
        self::assertSame(TranslationLanguage::French, TranslationLanguage::forInterface('fr'));
        self::assertSame(TranslationLanguage::ChineseSimplified, TranslationLanguage::forInterface('zh_CN'));
        self::assertSame(TranslationLanguage::English, TranslationLanguage::forInterface('en_PI'));
        self::assertSame(TranslationLanguage::English, TranslationLanguage::forInterface(null));
        self::assertSame(TranslationLanguage::English, TranslationLanguage::forInterface('xx'));
    }
}
