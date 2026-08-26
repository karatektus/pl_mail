<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Templating;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * No template writes an inline event handler, because the policy refuses them.
 *
 * `script-src` is enforced in production with a nonce, and the CSP spec is
 * explicit that neither a nonce nor a hash authorises an inline event handler
 * without `unsafe-hashes`. So `onchange="this.form.requestSubmit()"` is not a
 * shortcut that works — it is a control that does nothing:
 *
 *     Executing inline event handler violates the following Content Security
 *     Policy directive 'script-src 'self' 'nonce-…''
 *
 * TWELVE OF THEM SURVIVED, and the reason they survived is the reason this test
 * is static rather than a browser check. An inline handler violates nothing
 * when the page loads — it violates when somebody touches the control. The
 * existing csp.spec.ts walks every surface and asserts a clean load, and it was
 * clean: the log browser's filters, the clock and timezone pickers, the compose
 * defaults and the push delivery filter all rendered perfectly and then did
 * nothing when used. The failure was silent unless a console was open.
 *
 * A browser test could be written for it, but it would have to drive each
 * widget — several are Tom Select, whose original element is hidden — and
 * would test twelve interactions to state one rule. The rule is what matters:
 * behaviour goes in a Stimulus controller, which is served from a file the
 * policy already allows. `ui--auto-submit` exists for the common case.
 */
final class NoInlineEventHandlersTest extends TestCase
{
    /**
     * The attributes a browser treats as script. Not an exhaustive list of
     * every `on*` in the HTML spec — a deliberately short list of the ones
     * anybody actually reaches for, because a regex broad enough to catch all
     * of them also catches `data-...on-something` and words in prose.
     */
    private const array HANDLERS = [
        'onclick', 'onchange', 'oninput', 'onsubmit', 'onfocus', 'onblur',
        'onkeydown', 'onkeyup', 'onmouseover', 'onmouseout', 'onload', 'onerror',
    ];

    public function testNoTemplateCarriesAnInlineEventHandler(): void
    {
        $pattern = '/\s(' . implode('|', self::HANDLERS) . ')\s*=/i';
        $offences = [];

        foreach ($this->templates() as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            foreach (explode("\n", $contents) as $number => $line) {
                if (1 === preg_match($pattern, $line, $matches)) {
                    $offences[] = sprintf(
                        '%s:%d  %s=',
                        str_replace(self::projectDir() . '/', '', $file->getPathname()),
                        $number + 1,
                        mb_strtolower($matches[1]),
                    );
                }
            }
        }

        self::assertSame(
            [],
            $offences,
            "These are inline event handlers, and the enforced CSP refuses to run them —\n"
            . "the control will render and then do nothing when somebody uses it.\n"
            . "Move the behaviour into a Stimulus controller; ui--auto-submit covers\n"
            . "\"submit the form when this changes\" and \"select all of this on click\".\n",
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
