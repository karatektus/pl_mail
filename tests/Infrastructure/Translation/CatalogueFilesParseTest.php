<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Every catalogue parses, and every value in it is a string.
 *
 * A translation file is the one kind of source in this repository that the unit
 * suite never opens. Nothing loads `en_PI`; the tests that read `de` read it for
 * its wording. So a catalogue can be syntactically broken and every one of four
 * and a half thousand tests still passes — and then the prod container will not
 * boot, because cache:warmup compiles all of them and a single unparseable file
 * fails the build.
 *
 * That is not hypothetical. It is what shipped:
 *
 *     admin.users.password_too_short: {{ limit }} marks at least — ...
 *
 * A YAML value that opens with `{` is a flow mapping, not text. The placeholder
 * that makes the sentence useful is the very thing that stops it being a
 * sentence, and it only bites when the value is placed FIRST — the same key in
 * `en` and `de` leads with a word and parses fine. Green tests, green PHPStan,
 * and the release image failed to build.
 *
 * Two assertions rather than one. Parsing catches the broken file; requiring
 * every value to be a string catches the near miss that parses — a value like
 * `{ limit: null }` is valid YAML, is silently an array, and would reach a
 * translator's screen as nothing at all.
 */
final class CatalogueFilesParseTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function catalogues(): iterable
    {
        $files = glob(dirname(__DIR__, 3) . '/translations/*.yaml');

        self::assertNotFalse($files, 'the translations directory should be readable');
        self::assertNotEmpty($files, 'there should be catalogues to check');

        foreach ($files as $file) {
            yield basename($file) => [$file];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('catalogues')]
    public function testACatalogueParsesAndHoldsOnlyStrings(string $file): void
    {
        try {
            $parsed = Yaml::parseFile($file);
        } catch (ParseException $e) {
            self::fail(sprintf(
                "%s is not valid YAML and would fail cache:warmup in prod:\n  %s\n"
                . 'A value that begins with a placeholder needs quoting — "{{ limit }} …", not {{ limit }} ….',
                basename($file),
                $e->getMessage(),
            ));
        }

        self::assertIsArray($parsed, basename($file) . ' should read as a map of keys to messages');

        // Nested maps are ordinary here — the message catalogues are written as
        // trees and the validators ones as flat dotted keys, and both are fine.
        // What is being looked for is a LEAF that is not text.
        foreach (self::leaves($parsed) as $key => $value) {
            // Scalar rather than string: `settings.filters.placeholder.number`
            // is the integer 0 in all three locales, deliberately, and renders
            // as "0". Insisting on a string would only teach somebody to quote
            // it to appease a test. Null is the shape the bug actually takes —
            // `{{ limit }} …` parses to a map whose key is the placeholder and
            // whose value is nothing — so that is what is refused.
            self::assertTrue(
                true === is_scalar($value),
                sprintf(
                    '%s: "%s" did not read as a message. A value opening with `{` is parsed as a '
                    . 'YAML mapping rather than a sentence — quote it.',
                    basename($file),
                    $key,
                ),
            );
        }
    }

    /**
     * Every leaf of the catalogue, keyed by its dotted path.
     *
     * @param array<string, mixed> $tree
     *
     * @return iterable<string, mixed>
     */
    private static function leaves(array $tree, string $prefix = ''): iterable
    {
        foreach ($tree as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

            // An empty map is itself the bug this is looking for — `foo: {}`
            // yields no leaves, so it would pass a recursion that only checks
            // what it finds. Reported as the leaf it should have been.
            if (true === is_array($value) && [] !== $value) {
                yield from self::leaves($value, $path);

                continue;
            }

            yield $path => $value;
        }
    }
}
