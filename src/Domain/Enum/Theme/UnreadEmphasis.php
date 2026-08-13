<?php

declare(strict_types=1);

namespace App\Domain\Enum\Theme;

/**
 * How loudly the list says a row is unread.
 *
 * The list signals unread four ways at once today — a bold sender, a bold
 * subject, a tinted row and (stacked) a green dot. That is right for somebody
 * triaging a busy inbox and heavy-handed for somebody whose inbox is mostly
 * unread, where every row is shouting and none of them stand out.
 *
 * The BOLD WEIGHT IS NOT PART OF THIS. It is the signal that survives a
 * colour-blind reader, a photograph behind a translucent pane and a printout,
 * so it stays at every setting; what varies is the tint behind the row and the
 * accent bar beside it. Standard is what the app has always done, so an
 * existing install is unchanged.
 */
enum UnreadEmphasis: string
{
    case Subtle   = 'subtle';
    case Standard = 'standard';
    case Strong   = 'strong';

    /**
     * Multiplier on the theme's own unread tint alpha.
     *
     * A multiplier rather than a literal alpha because the base differs by
     * theme — 0.4 over a light surface, 0.03 over a dark one — and a fixed
     * number for both would be invisible in one and a slab in the other.
     */
    public function tintScale(): float
    {
        return match ($this) {
            self::Subtle => 0.0,
            self::Standard => 1.0,
            self::Strong => 1.6,
        };
    }

    /** Width of the accent bar down the row's leading edge. */
    public function barWidth(): string
    {
        return match ($this) {
            self::Subtle, self::Standard => '0px',
            self::Strong => '3px',
        };
    }
}
