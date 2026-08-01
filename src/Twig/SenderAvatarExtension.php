<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * The initial and the colour behind it for a sender with no picture.
 *
 * A list of rows all beginning with the same grey circle is no better than one
 * with no circles, so the colour has to vary — but it also has to be the SAME
 * colour for the same person every time, or the avatar stops being something
 * you recognise and becomes decoration that moves. So it is derived from the
 * address rather than assigned, stored or randomised.
 *
 * crc32 of a normalised address, modulo the palette. Not for security, and it
 * does not need to be: the only cost of two people colliding is that they look
 * alike, which they already would with 8 colours and 9 correspondents.
 *
 * The palette is muted on purpose. These sit in a dense list next to a subject
 * and a snippet, and saturated circles pull the eye away from the words that
 * matter; each entry is a light-mode and a dark-mode class because a tone that
 * reads as "quiet" on white is invisible on near-black.
 */
final class SenderAvatarExtension extends AbstractExtension
{
    /**
     * Deliberately literal Tailwind classes rather than composed strings:
     * anything built at runtime is invisible to the content scanner and gets
     * purged out of the stylesheet.
     *
     * @var list<string>
     */
    private const array TONES = [
        'bg-[#a8563a] dark:bg-[#e8a07a] text-white dark:text-[#2b1a12]',
        'bg-[#2f6b52] dark:bg-[#8fd3b0] text-white dark:text-[#122b21]',
        'bg-[#2f5d8a] dark:bg-[#8fbdea] text-white dark:text-[#101f2e]',
        'bg-[#5b5aa0] dark:bg-[#b3b0ea] text-white dark:text-[#17162e]',
        'bg-[#6b6a1f] dark:bg-[#d6d174] text-white dark:text-[#26250a]',
        'bg-[#8a4a63] dark:bg-[#e6a3bd] text-white dark:text-[#2c1620]',
        'bg-[#2f6d6b] dark:bg-[#8ed2cf] text-white dark:text-[#0f2827]',
        'bg-[#8a6a2f] dark:bg-[#e0c084] text-white dark:text-[#2a1f0c]',
    ];

    public function getFilters(): array
    {
        return [
            new TwigFilter('avatar_tone', $this->tone(...)),
            new TwigFilter('avatar_initial', $this->initial(...)),
        ];
    }

    public function tone(?string $seed): string
    {
        $normalised = mb_strtolower(trim((string) $seed));

        if ('' === $normalised) {
            return self::TONES[0];
        }

        return self::TONES[crc32($normalised) % count(self::TONES)];
    }

    /**
     * The first letter of the display name, or of the address when there is no
     * name. Skips anything that is not a letter or a digit, so a sender called
     * "(no sender)" or "<hello@…>" does not get a bracket for an avatar.
     */
    public function initial(?string $name, ?string $fallback = null): string
    {
        foreach ([(string) $name, (string) $fallback] as $candidate) {
            if (1 === preg_match('/\p{L}|\p{N}/u', $candidate, $matches)) {
                return mb_strtoupper($matches[0]);
            }
        }

        return '?';
    }
}
