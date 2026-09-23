<?php

declare(strict_types=1);

namespace App\Domain\Enum\User;

/**
 * Where the running clock is drawn, if anywhere.
 *
 * The question it answers is the one people ask while reading mail — "what time
 * is it?" — and the three places are three answers to "how much room may it
 * take":
 *
 * - Topbar, the default: on the Happening Soon button, which is already the
 *   control about time. It costs one short word of width, and the button is
 *   there on every page rather than only when something is coming up.
 * - Brand: a second line under the wordmark, in space the topbar already gives
 *   the logo. Quietest of the three; gone with the wordmark below `sm`.
 * - Sidebar: a card at the foot of the sidebar with the date and the next
 *   thing coming up, for someone who wants the day at a glance and has the
 *   height to spare.
 *
 * Off is the topbar as it was before any of this: the Happening Soon button
 * only when something is coming up, and no clock.
 */
enum ClockPlacement: string
{
    case Topbar  = 'topbar';
    case Brand   = 'brand';
    case Sidebar = 'sidebar';
    case Off     = 'off';

    public function transKey(): string
    {
        return 'settings.clock.placement.option.' . $this->value;
    }

    /**
     * Absent or unknown is the default — the stored value is a string in the
     * settings bag, so a value from a later version that this one does not know
     * must not fail the page that reads it.
     */
    public static function fromSetting(mixed $value, self $default = self::Topbar): self
    {
        return true === is_string($value) ? self::tryFrom($value) ?? $default : $default;
    }
}
