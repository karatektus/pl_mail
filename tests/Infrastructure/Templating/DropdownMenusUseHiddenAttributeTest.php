<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Templating;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every ui--dropdown menu is hidden by the ATTRIBUTE, never by the class.
 *
 * The controller opens a menu with `this.menuTarget.hidden = false` and never
 * touches classList. So a menu carrying Tailwind's `hidden` class is
 * display:none for the whole life of the page no matter what the controller
 * does — and because `hidden` as a property is then false, the first click
 * reads the menu as already open and CLOSES it.
 *
 * The background-work indicator shipped that way. The button pulsed in the
 * topbar saying work was running, which was true, and clicking it did nothing
 * whatsoever — no error, no console warning, nothing to search for. It is
 * invisible to csp.spec.ts and to any test that does not click that exact
 * button while a job happens to be running, which is a narrow window to catch
 * by luck.
 *
 * Static, for the same reason NoInlineEventHandlersTest is: the failure needs
 * a specific widget in a specific state to show itself, and the mistake is one
 * word in a class list that reviews slide past.
 */
final class DropdownMenusUseHiddenAttributeTest extends TestCase
{
    public function testNoDropdownMenuIsHiddenByClass(): void
    {
        $offenders = [];

        foreach ($this->templates() as $file) {
            $markup = (string) file_get_contents($file->getPathname());

            // Each element that declares itself a dropdown menu, with the rest
            // of that opening tag — which is where its class list lives.
            preg_match_all('/<[^>]*data-ui--dropdown-target="menu"[^>]*>/s', $markup, $matches);

            foreach ($matches[0] as $tag) {
                if (1 !== preg_match('/\bclass="([^"]*)"/s', $tag, $class)) {
                    continue;
                }

                // `hidden` as a whole word in the class list. Not `hidden-foo`,
                // and not `md:hidden`, which is a real responsive utility and
                // says nothing about the menu's open state.
                if (1 === preg_match('/(^|\s)hidden(\s|$)/', $class[1])) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "A ui--dropdown menu is hidden by the `hidden` CLASS in:\n  "
            . implode("\n  ", array_unique($offenders))
            . "\nUse the `hidden` ATTRIBUTE instead — the controller toggles the property.",
        );
    }

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
