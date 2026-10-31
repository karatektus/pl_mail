<?php

declare(strict_types=1);

namespace App\Domain\Enum\Theme;

/**
 * What the buttons above a conversation and a selection show (#459).
 *
 * "I always have a hard time remembering what each icon is." A tooltip does not
 * answer that: it needs a pointer and a pause per button, and a phone has
 * neither. So the words can be on the button itself, beside the icon or instead
 * of it. Icons stays the default, so an existing install is unchanged.
 *
 * Four CSS values rather than a class on <html>, so the settings page can
 * preview a choice the way it previews every other one: by writing variables.
 */
enum ActionButtonStyle: string
{
    case Icons = 'icons';
    case Both  = 'both';
    case Text  = 'text';

    public function iconDisplay(): string
    {
        return self::Text === $this ? 'none' : 'inline-block';
    }

    public function labelDisplay(): string
    {
        return self::Icons === $this ? 'none' : 'inline';
    }

    /** Zero for icons, which is what keeps that button the 32px circle it was. */
    public function paddingX(): string
    {
        return self::Icons === $this ? '0px' : '0.625rem';
    }

    /**
     * The gap inside a button. Zero unless both parts are showing: a hidden part
     * takes no gap in a flex row, so this only matters for the look of Both, but
     * spelling it out keeps all four variables one decision.
     */
    public function gap(): string
    {
        return self::Both === $this ? '0.375rem' : '0px';
    }
}
