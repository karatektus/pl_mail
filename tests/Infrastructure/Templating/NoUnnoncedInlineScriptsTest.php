<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Templating;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every inline <script> and every import map carries the CSP nonce.
 *
 * The sibling of NoInlineEventHandlersTest, for the same silent failure: the
 * enforced `script-src 'self' 'nonce-…'` drops an unnonced inline script
 * without a word, and the page renders as though nothing were missing. Four
 * had slipped through — the booking form's hours, the booking page's visitor
 * zone, the restart page's way back and the public layout's dark theme — plus
 * two `importmap('app')` calls that left the public pages and the error page
 * with no JavaScript at all.
 *
 * A script with a `src` is exempt (it is 'self'), and so is the reading
 * frame's height reporter, which the policy authorises by hash instead —
 * see MessageFrameScript.
 */
final class NoUnnoncedInlineScriptsTest extends TestCase
{
    public function testEveryInlineScriptAndImportMapCarriesTheNonce(): void
    {
        $offences = [];

        foreach ($this->templates() as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            foreach (explode("\n", $contents) as $number => $line) {
                $inline = 1 === preg_match('/<script(?![^>]*\s(?:src|nonce)=)[\s>]/i', $line)
                    && false === str_contains($line, 'frameScript');
                $importMap = 1 === preg_match('/importmap\(\s*[\'"][^\'"]+[\'"]\s*\)/', $line);

                if ($inline || $importMap) {
                    $offences[] = sprintf(
                        '%s:%d  %s',
                        str_replace(self::projectDir() . '/', '', $file->getPathname()),
                        $number + 1,
                        trim($line),
                    );
                }
            }
        }

        self::assertSame(
            [],
            $offences,
            "The enforced CSP refuses these without a nonce, silently.\n"
            . "In a <head> that must run before paint: nonce=\"{{ csp_nonce() }}\"; importmap('app', { nonce: csp_nonce() }).\n"
            . "Anywhere else: a Stimulus controller — Turbo cannot carry a nonce into a later page or a frame.\n",
        );
    }

    /** @return iterable<SplFileInfo> */
    private function templates(): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::projectDir() . '/templates'),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && 'twig' === $file->getExtension()) {
                yield $file;
            }
        }
    }

    private static function projectDir(): string
    {
        return \dirname(__DIR__, 3);
    }
}
