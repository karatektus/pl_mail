<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Templating;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * No template may default a flag with `|default(true)`.
 *
 * THE BUG THIS EXISTS FOR
 *
 * Twig's `default` filter substitutes when a value is undefined **or empty**,
 * and `false` is empty. So `false|default(true)` is `true`, and every caller
 * that passed `false` to switch something off was ignored.
 *
 * It was not a near miss. Five templates used this form, and all five were
 * being passed `false` by somebody:
 *
 *   • compose/_sent.stream — a send showed "Message sent." beside its own
 *     countdown reading "Sending… 8", and a cancel that arrived too late was
 *     told off and congratulated in the same breath.
 *   • compose/_attachments — `live: false` means "these chips are already on
 *     screen, do not animate them in", so re-rendering the window replayed the
 *     entrance stagger.
 *   • calendar/_event_chip — the time-grid asks for no start time because its
 *     own axis already shows one. Every chip printed it anyway.
 *   • _partials/_thread_row — the per-account list asks not to be treated as a
 *     merged one, and got the account disambiguation regardless.
 *   • settings/_appearance — the setup wizard hides the export/import block,
 *     which its own comment calls "not worth showing someone who has been in
 *     the app for ninety seconds". It showed.
 *
 * Each is small on its own. Together they are one mistake made five times,
 * which is exactly what a guard is for.
 *
 * `?? true` is the form that was meant: the null-coalescing operator defaults
 * on null or undefined and leaves `false` alone. `|default(false)` is not
 * caught here and does not need to be — an empty value defaults to false, which
 * is what an empty value already meant.
 */
final class BooleanDefaultsAreNotFilteredTest extends TestCase
{
    public function testNoTemplateDefaultsAFlagWithTheDefaultFilter(): void
    {
        $offenders = [];

        foreach ($this->templates() as $file) {
            $contents = $this->withoutComments((string) file_get_contents($file->getPathname()));

            foreach (explode("\n", $contents) as $number => $line) {
                if (true === str_contains($line, '|default(true)')) {
                    $offenders[] = sprintf(
                        '%s:%d — use `?? true`; `false|default(true)` is true',
                        str_replace(self::projectDir() . '/', '', $file->getPathname()),
                        $number + 1,
                    );
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * The template with its `{# … #}` blanked out, line numbering intact.
     *
     * Blanked rather than matched around: the first version of this test
     * skipped lines that "looked like a comment", and the comments explaining
     * this very trap — which necessarily quote the offending form — were
     * reported as offenders. A rule about code should be applied to the code.
     */
    private function withoutComments(string $template): string
    {
        return (string) preg_replace_callback(
            '/\{#.*?#\}/s',
            static fn (array $match): string => preg_replace('/[^\n]/', ' ', $match[0]) ?? '',
            $template,
        );
    }

    /** @return list<SplFileInfo> */
    private function templates(): array
    {
        $found = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::projectDir() . '/templates', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (true === $file->isFile() && 'twig' === $file->getExtension()) {
                $found[] = $file;
            }
        }

        return $found;
    }

    private static function projectDir(): string
    {
        return \dirname(__DIR__, 3);
    }
}
