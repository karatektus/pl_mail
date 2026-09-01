<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Templating;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A response frame's own attributes never reach the page, so it must not rely
 * on them.
 *
 * WHAT WENT WRONG, AND WHY NOBODY COULD SEE IT
 * ────────────────────────────────────────────
 * Turbo takes the CHILDREN of the <turbo-frame> in a response and puts them
 * inside the frame already in the document. The response's own frame element is
 * discarded, attributes and all. So this, at the top of admin/ai/_frame:
 *
 *     <turbo-frame id="admin-ai" class="block space-y-3">
 *
 * reached nothing. The frame on the page was the placeholder in
 * admin/index.html.twig, which carried no class — so it kept <turbo-frame>'s
 * default `display: inline`, `space-y-3` was never applied, and every card in
 * the section rendered flush against the one above it with no gap at all.
 *
 * SIX admin sections were doing this: ai, backup, insight-reports, integrations,
 * push and users. It was reported repeatedly as "padding issues in the admin UI"
 * and survived every attempt to fix it, because the template that appears to
 * declare the spacing declares it correctly — and reading that template is what
 * anybody does when told the spacing is wrong. The measurement is what found it:
 * three gaps of 0px, and one of 12px that came from an unrelated inner
 * `div.space-y-3` further down the tree.
 *
 * WHY A STATIC TEST AND NOT A RENDERED ONE
 * ────────────────────────────────────────
 * tests/e2e/admin-card-insets.spec.ts measures the rendered page and did not
 * catch this, because it checks HORIZONTAL insets — where a card's content
 * begins relative to its edge — and this bug is vertical. It would have to grow
 * a gap assertion per section to see it, and it can only see the sections a run
 * actually renders.
 *
 * This reads the markup instead, so it sees every frame in the tree including
 * the ones behind a branch no run takes, and it fails with the file and the id
 * rather than with a pixel count.
 *
 * WHAT IT DOES NOT FORBID
 * ───────────────────────
 * A frame that is rendered IN PLACE keeps its classes and must — settings/
 * accounts/_push_control.html.twig says `class="contents"` is load-bearing
 * there, and it is right, because that frame is the one in the document rather
 * than a response transplanted into another. The rule is only about ids that
 * exist in BOTH forms: a placeholder carrying `src`, and a response without
 * one. Only then is the response's class provably dead.
 */
final class TurboFrameClassesTest extends TestCase
{
    /**
     * @return list<array{file: string, id: string, class: ?string, placeholder: bool}>
     */
    private function frames(): array
    {
        $root  = dirname(__DIR__, 3) . '/templates';
        $found = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (false === $file->isFile() || 'twig' !== $file->getExtension()) {
                continue;
            }

            $markup = (string) file_get_contents($file->getPathname());

            if (0 === preg_match_all('~<turbo-frame\b([^>]*)>~s', $markup, $tags)) {
                continue;
            }

            foreach ($tags[1] as $attributes) {
                if (1 !== preg_match('~\bid="([a-z0-9-]+)"~i', $attributes, $id)) {
                    // An interpolated id — `id="{{ frame }}"` — cannot be
                    // resolved by reading, and guessing at one would make this
                    // test lie rather than fail.
                    continue;
                }

                $class = null;

                if (1 === preg_match('~\bclass="([^"]*)"~', $attributes, $matched)) {
                    $class = $matched[1];
                }

                $found[] = [
                    'file'        => str_replace($root . '/', '', $file->getPathname()),
                    'id'          => $id[1],
                    'class'       => $class,
                    'placeholder' => true === str_contains($attributes, 'src='),
                ];
            }
        }

        return $found;
    }

    public function testAResponseFrameNeverDeclaresClassesThePlaceholderDoesNotHave(): void
    {
        $frames       = $this->frames();
        $placeholders = [];

        foreach ($frames as $frame) {
            if (true === $frame['placeholder']) {
                $placeholders[$frame['id']] = $frame;
            }
        }

        $dead = [];

        foreach ($frames as $frame) {
            if (true === $frame['placeholder'] || null === $frame['class']) {
                continue;
            }

            if (false === array_key_exists($frame['id'], $placeholders)) {
                // Rendered in place, so its classes are its own and real.
                continue;
            }

            $carried = preg_split('~\s+~', (string) $placeholders[$frame['id']]['class']) ?: [];

            foreach (preg_split('~\s+~', $frame['class']) ?: [] as $wanted) {
                if ('' === $wanted || true === in_array($wanted, $carried, true)) {
                    continue;
                }

                $dead[] = sprintf(
                    '%s declares class "%s" on <turbo-frame id="%s">, which Turbo discards. '
                    . 'Put it on the placeholder in %s instead.',
                    $frame['file'],
                    $wanted,
                    $frame['id'],
                    $placeholders[$frame['id']]['file'],
                );
            }
        }

        self::assertSame([], $dead, implode("\n", $dead));
    }

    /**
     * And the placeholders that need to lay their children out actually say so.
     *
     * The other half of the same bug: `<turbo-frame>` is an unknown element, so
     * it is `display: inline` until something says otherwise, and a `space-y-*`
     * on an inline box lays nothing out. Every admin section frame stacks cards,
     * so every one of them needs both.
     */
    public function testEveryAdminSectionPlaceholderStacksItsCards(): void
    {
        $missing = [];

        foreach ($this->frames() as $frame) {
            if (false === $frame['placeholder'] || false === str_starts_with($frame['id'], 'admin-')) {
                continue;
            }

            $classes = preg_split('~\s+~', (string) $frame['class']) ?: [];

            if (false === in_array('block', $classes, true)) {
                $missing[] = sprintf(
                    '<turbo-frame id="%s"> in %s has no `block`, so it is display:inline and '
                    . 'any spacing utility on it does nothing.',
                    $frame['id'],
                    $frame['file'],
                );
            }
        }

        self::assertSame([], $missing, implode("\n", $missing));
    }
}
