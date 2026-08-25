<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Translation;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A flash message must reach the bag already translated.
 *
 * The flash bag on this install holds sentences, not keys. `_layout/app.html.twig`
 * reads `app.flashes` and hands each message straight to the toast partial,
 * which prints it — there is no `|trans` anywhere on that path, and there
 * cannot be, because most of these messages interpolate a name or a count that
 * only the controller has.
 *
 * The consequence is that a key added raw is not a missing translation. It is a
 * *present* translation that nothing looked up, and it reaches the user as
 * `two_factor.flash.code_rejected` in a red toast — which is exactly how this
 * was found, months after the strings themselves were written and translated
 * into all three locales.
 *
 * Nothing about that failure is visible in review: the call site reads exactly
 * like every correct one, the key exists, the translators did their job, and no
 * test touched the two-factor error path. A string comparison is a crude
 * instrument; it is also the only one that would have caught this.
 */
final class FlashMessagesAreTranslatedTest extends TestCase
{
    /**
     * A literal that looks like a translation key: two or more dot-separated
     * lower-snake segments, which is the shape every key in messages.*.yaml has
     * and no shape an English sentence has.
     */
    private const string RAW_KEY = "/->(?:addFlash|flash)\(\s*'[a-z]+'\s*,\s*'[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+'\s*[,)]/";

    public function testNoFlashCallPassesAnUntranslatedKey(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            // The helper that translates on the caller's behalf is the one
            // legitimate way to pass a key, so a file defining one is asking
            // for keys on purpose. Only SecurityStepHandler does this today.
            if (1 === preg_match('/function flash\([^)]*\)[^{]*\{[^}]*->trans\(/s', $contents)) {
                continue;
            }

            if (1 === preg_match(self::RAW_KEY, $contents, $match)) {
                $offenders[] = sprintf(
                    '%s — %s',
                    str_replace(self::projectDir().'/', '', $file->getPathname()),
                    trim($match[0], " ,("),
                );
            }
        }

        self::assertSame(
            [],
            $offenders,
            "These flash calls pass a translation key where the flash bag expects a sentence.\n"
            . "The toast region prints what it is given, so the user sees the key itself.\n"
            . "Wrap it: \$this->translator->trans('the.key').\n",
        );
    }

    /** @return iterable<SplFileInfo> */
    private function sourceFiles(): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::projectDir().'/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && 'php' === $file->getExtension()) {
                yield $file;
            }
        }
    }

    private static function projectDir(): string
    {
        return dirname(__DIR__, 3);
    }
}
