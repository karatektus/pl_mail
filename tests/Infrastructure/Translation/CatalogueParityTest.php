<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every key in the English catalogue exists in German and in pirate English.
 *
 * A missing key is not an error anywhere Symfony looks: the translator falls
 * back to `en`, the page renders, and the only symptom is an English sentence
 * in the middle of a German one. That is how 207 keys — whole admin screens,
 * the session-expired dialog, the confirm dialog — reached `en_PI` in English
 * without a single test noticing.
 *
 * One direction only. A key that exists in a translation but not in English is
 * dead weight, not a user-facing fault, and English is the catalogue new keys
 * are written into first.
 */
final class CatalogueParityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'de' => ['de'];
        yield 'en_PI' => ['en_PI'];
    }

    #[DataProvider('locales')]
    public function testEveryEnglishKeyIsTranslated(string $locale): void
    {
        $english = self::keys('en');
        $other = self::keys($locale);

        self::assertSame(
            [],
            array_values(array_diff($english, $other)),
            sprintf('These keys exist in messages.en.yaml but not in messages.%s.yaml.', $locale),
        );
    }

    /** @return list<string> the dotted key of every leaf */
    private static function keys(string $locale): array
    {
        $tree = Yaml::parseFile(dirname(__DIR__, 3) . sprintf('/translations/messages.%s.yaml', $locale));

        self::assertIsArray($tree);

        return self::flatten($tree);
    }

    /**
     * @param array<mixed> $tree
     *
     * @return list<string>
     */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $keys = [];

        foreach ($tree as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                array_push($keys, ...self::flatten($value, $path));
            } else {
                $keys[] = $path;
            }
        }

        return $keys;
    }
}
