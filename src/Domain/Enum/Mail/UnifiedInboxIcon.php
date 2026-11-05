<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * The icons a unified inbox can wear in the sidebar (#265).
 *
 * A closed set rather than uploads or a free text field. An upload would be a
 * file to store, scale and serve for a 16-pixel glyph, and would sit beside
 * nine Font Awesome rows looking like something else. A free class name would
 * let a typo draw an empty square. The case values are Font Awesome's own
 * names without the `fa-` prefix, and they are what the JMAP object carries,
 * so the native apps map the same names onto their own icon sets.
 *
 * Grouped loosely by what an inbox tends to be for: the default first, then
 * work, home and people, then interests.
 */
enum UnifiedInboxIcon: string
{
    case Inbox       = 'inbox';
    case Stacked     = 'boxes-stacked';
    case Envelope    = 'envelope';
    case Briefcase   = 'briefcase';
    case Building    = 'building';
    case Code        = 'code';
    case Graduation  = 'graduation-cap';
    case House       = 'house';
    case User        = 'user';
    case Users       = 'users';
    case Heart       = 'heart';
    case Star        = 'star';
    case Bell        = 'bell';
    case Bolt        = 'bolt';
    case Flag        = 'flag';
    case Folder      = 'folder';
    case Cart        = 'cart-shopping';
    case PiggyBank   = 'piggy-bank';
    case Plane       = 'plane';
    case Globe       = 'globe';
    case Leaf        = 'leaf';
    case Music       = 'music';
    case Gamepad     = 'gamepad';
    case Archive     = 'box-archive';

    /**
     * What Other starts with: a stack of boxes, everything else put together.
     * Not the layers glyph, which the sidebar's All Mail row already wears.
     */
    public const self OTHER_DEFAULT = self::Stacked;

    /**
     * The icon a stored value names, or the inbox tray for one that is no
     * longer in the set — a row must still draw if a case is ever taken out.
     */
    public static function of(string $value): self
    {
        return self::tryFrom($value) ?? self::Inbox;
    }

    /** The class the template puts beside `fa-solid`. */
    public function cssClass(): string
    {
        return 'fa-' . $this->value;
    }
}
