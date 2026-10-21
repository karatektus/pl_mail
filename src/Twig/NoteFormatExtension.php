<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `note_format`: a note's body as HTML, for the web notes pages.
 *
 * Notes keep their formatting as plain text with a small markdown subset, the
 * same one the Android app renders, so either side can edit what the other
 * wrote: `**bold**`, `*italic*`, `__underline__`, `# ` heading lines and `- `
 * bullet lines. Everything is escaped first; only those marks become tags.
 */
final class NoteFormatExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('note_format', self::format(...), ['is_safe' => ['html']])];
    }

    public static function format(?string $body): string
    {
        $html  = [];
        $inList = false;

        foreach (explode("\n", (string) $body) as $line) {
            $escaped = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if (1 === preg_match('/^- (.*)$/', $escaped, $m)) {
                if (false === $inList) {
                    $html[] = '<ul class="list-disc pl-5">';
                    $inList = true;
                }
                $html[] = '<li>' . self::inline($m[1]) . '</li>';

                continue;
            }

            if (true === $inList) {
                $html[] = '</ul>';
                $inList = false;
            }

            if (1 === preg_match('/^# (.*)$/', $escaped, $m)) {
                $html[] = '<div class="font-semibold text-base">' . self::inline($m[1]) . '</div>';
            } else {
                $html[] = '<div>' . ('' === $escaped ? '<br>' : self::inline($escaped)) . '</div>';
            }
        }

        if (true === $inList) {
            $html[] = '</ul>';
        }

        return implode('', $html);
    }

    private static function inline(string $text): string
    {
        $text = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/__(.+?)__/u', '<u>$1</u>', $text);

        return (string) preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/u', '<em>$1</em>', $text);
    }
}
