<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * The few Markdown forms CHANGELOG.md is written in, turned into HTML (#165).
 *
 * Not CommonMark, on purpose. league/commonmark is a dev dependency, there for
 * the documentation site, and promoting it to the image for one file plMail
 * itself writes would ship a full Markdown engine to render a dialect that
 * uses five constructs: paragraphs, `- ` lists, `code`, **bold**, *italic* and
 * [links](https://…). Those five are what this handles, and anything else in
 * the file arrives as the literal text it is, which is visible in review long
 * before a release.
 *
 * ESCAPE FIRST, THEN PUT BACK. Every character is escaped before any form is
 * recognised, so a stray `<` in an entry is a less-than sign and never a tag,
 * and the only markup in the output is markup this class wrote. Links keep
 * their target only when it is http(s); anything else stays as text.
 */
final class ChangelogMarkdown
{
    /**
     * Paragraphs and lists, from blocks already split on blank lines.
     *
     * @param list<string> $blocks
     */
    public static function blocks(array $blocks): string
    {
        $html = '';

        foreach ($blocks as $block) {
            $block = trim($block, "\n");

            if ('' === trim($block)) {
                continue;
            }

            $html .= self::isList($block) ? self::list($block) : '<p>' . self::inline(self::join($block)) . '</p>';
        }

        return $html;
    }

    /** One line or paragraph's inline forms. */
    public static function inline(string $text): string
    {
        $codes = [];

        // Code spans first and out of the way, so nothing inside one is read
        // as bold or as a link: `**` in a shell example stays two asterisks.
        $text = (string) preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES | ENT_HTML5) . '</code>';

            return "\u{E000}" . (count($codes) - 1) . "\u{E001}";
        }, $text);

        $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5);

        $text = (string) preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/',
            static fn (array $m): string => '<a href="' . $m[2] . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>',
            $text,
        );
        // A link to a page in the repository (`docs/…`) goes nowhere from
        // inside an installation, so it keeps its words and loses the target.
        $text = (string) preg_replace('/\[([^\]]+)\]\((?!https?:\/\/)[^)\s]+\)/', '$1', $text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![\w*])\*(?![\s*])(.+?)(?<![\s*])\*(?![\w*])/', '<em>$1</em>', $text);

        return (string) preg_replace_callback("/\u{E000}(\\d+)\u{E001}/u", static fn (array $m): string => $codes[(int) $m[1]] ?? '', $text);
    }

    /** Whether a block is a `- ` list, continuation lines and all. */
    public static function isList(string $block): bool
    {
        return str_starts_with(ltrim($block, "\n"), '- ');
    }

    /**
     * The items of a `- ` list, each with its wrapped lines joined back.
     *
     * @return list<string>
     */
    public static function items(string $block): array
    {
        $items = [];

        foreach (explode("\n", $block) as $line) {
            if (str_starts_with($line, '- ')) {
                $items[] = substr($line, 2);

                continue;
            }

            if ([] !== $items && '' !== trim($line)) {
                $items[count($items) - 1] .= ' ' . trim($line);
            }
        }

        return $items;
    }

    /** A paragraph hard-wrapped at a hundred columns, as one line again. */
    public static function join(string $block): string
    {
        return trim((string) preg_replace('/\s*\n\s*/', ' ', $block));
    }

    private static function list(string $block): string
    {
        $html = '<ul>';

        foreach (self::items($block) as $item) {
            $html .= '<li>' . self::inline($item) . '</li>';
        }

        return $html . '</ul>';
    }
}
