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

    /**
     * The same idea for the account dot on a unified list row, as a background
     * alone — the dot has nothing written in it, so it needs no text colour,
     * and reusing the avatar tones would have meant carrying `text-white` onto
     * an empty 6px circle.
     *
     * Saturated where the avatar tones are muted, and deliberately so: this one
     * is a 6px mark that has to be told apart from two others at a glance,
     * rather than a 36px circle sitting under a name.
     *
     * @var list<string>
     */
    private const array DOT_TONES = [
        'bg-[#c2410c] dark:bg-[#fb923c]',
        'bg-[#15803d] dark:bg-[#4ade80]',
        'bg-[#1d4ed8] dark:bg-[#60a5fa]',
        'bg-[#6d28d9] dark:bg-[#a78bfa]',
        'bg-[#a16207] dark:bg-[#facc15]',
        'bg-[#be185d] dark:bg-[#f472b6]',
        'bg-[#0f766e] dark:bg-[#2dd4bf]',
        'bg-[#4d7c0f] dark:bg-[#a3e635]',
    ];


    public function getFilters(): array
    {
        return [
            new TwigFilter('avatar_tone', $this->tone(...)),
            new TwigFilter('avatar_initial', $this->initial(...)),
            new TwigFilter('account_tone', $this->accountTone(...)),
            new TwigFilter('account_color', $this->accountColor(...)),
        ];
    }

    /**
     * A distinct colour per account, by POSITION rather than by hash.
     *
     * Accounts are a small ordered set — a handful, arranged by the user — not
     * the unbounded stream of senders `accountTone()` was written for. Hashing
     * an address into eight buckets makes a collision a coin-flip at four
     * accounts (it was: two shared a colour in the sidebar), and a dot whose
     * whole job is to tell accounts apart must not put two of them in the same
     * paint. `sortOrder` is the dense 0-based position AccountCreator keeps, so
     * the first eight accounts are guaranteed different and the same account is
     * the same colour wherever it appears — the sidebar dot and the message
     * row's dot included, which is the point of it.
     */
    public function accountColor(int $sortOrder): string
    {
        return self::DOT_TONES[(($sortOrder % count(self::DOT_TONES)) + count(self::DOT_TONES)) % count(self::DOT_TONES)];
    }

    public function accountTone(?string $seed): string
    {
        $normalised = mb_strtolower(trim((string) $seed));

        if ('' === $normalised) {
            return self::DOT_TONES[0];
        }

        return self::DOT_TONES[crc32($normalised) % count(self::DOT_TONES)];
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
