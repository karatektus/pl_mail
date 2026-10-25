<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every key in the English catalogue exists in every other interface language,
 * and every translation keeps the placeholders its English message has.
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
 *
 * A lost or misspelt placeholder is quieter still. `%count%` translated as
 * `%anzahl%` renders literally, and a dropped `{{ limit }}` leaves "use at least
 * characters" with the number the sentence was for gone. Neither fails to
 * parse, and neither is a missing key. The French and Chinese catalogues were
 * each checked for this by hand when they came in; this makes the check stay.
 */
final class CatalogueParityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'de' => ['de'];
        yield 'fr' => ['fr'];
        yield 'zh_CN' => ['zh_CN'];
        yield 'en_PI' => ['en_PI'];
    }

    /**
     * Every domain, not only `messages`: a validation message is where a
     * placeholder like `{{ limit }}` is most often the point of the sentence.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function domainsAndLocales(): iterable
    {
        foreach (['messages', 'security', 'validators'] as $domain) {
            foreach (self::locales() as [$locale]) {
                yield $domain . '.' . $locale => [$domain, $locale];
            }
        }
    }

    #[DataProvider('locales')]
    public function testEveryEnglishKeyIsTranslated(string $locale): void
    {
        $english = array_keys(self::messages('messages', 'en'));
        $other = array_keys(self::messages('messages', $locale));

        self::assertSame(
            [],
            array_values(array_diff($english, $other)),
            sprintf('These keys exist in messages.en.yaml but not in messages.%s.yaml.', $locale),
        );
    }

    #[DataProvider('domainsAndLocales')]
    public function testEveryTranslationKeepsTheEnglishPlaceholders(string $domain, string $locale): void
    {
        $english = self::messages($domain, 'en');
        $other = self::messages($domain, $locale);
        $broken = [];

        foreach ($english as $key => $message) {
            if (!isset($other[$key])) {
                continue; // a missing key is the other test's to report
            }

            if (self::placeholders($message) !== self::placeholders($other[$key])) {
                $broken[] = $key;
            }
        }

        self::assertSame(
            [],
            $broken,
            sprintf('These keys in %s.%s.yaml do not carry the placeholders their English message has.', $domain, $locale),
        );
    }

    /** @return array<string, string> every leaf, by its dotted key */
    private static function messages(string $domain, string $locale): array
    {
        $tree = Yaml::parseFile(dirname(__DIR__, 3) . sprintf('/translations/%s.%s.yaml', $domain, $locale));

        self::assertIsArray($tree);

        return self::flatten($tree);
    }

    /**
     * The placeholders a message is filled in through, sorted, so a translation
     * may put them in any order its grammar wants: `%name%` (Symfony),
     * `{{ name }}` (validator constraints) and `{ name }` (filled in by the
     * Stimulus controllers).
     *
     * @return list<string>
     */
    private static function placeholders(string $message): array
    {
        preg_match_all('/%\w+%|\{\{\s*\w+\s*\}\}|\{\s*\w+\s*\}/', $message, $matches);
        $found = array_map(static fn (string $p): string => (string) preg_replace('/\s+/', '', $p), $matches[0]);
        sort($found);

        return $found;
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<string, string>
     */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $leaves = [];

        foreach ($tree as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $leaves += self::flatten($value, $path);
            } else {
                $leaves[$path] = (string) $value;
            }
        }

        return $leaves;
    }
}
