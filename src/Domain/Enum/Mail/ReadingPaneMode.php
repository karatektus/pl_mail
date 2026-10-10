<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * Where the open message is shown relative to the message list.
 *
 * Two positions, and `Off` is the one everybody starts on: the list and the
 * message take turns on the same space, which is what the mailbox did before
 * this setting existed. `Right` puts the message beside the list, on a screen
 * wide enough to hold both.
 *
 * A bottom position was left out on purpose. It is a second split with its own
 * drag axis, its own minimums and its own set of browser cases, and nobody has
 * asked for it; adding a case here later is cheap, and taking one back out
 * after people have chosen it is not.
 *
 * "Right" is a preference rather than a promise. Whether it is drawn is
 * decided by the width of the mail card in the browser (see app.css), because a
 * docked calendar can leave a wide window with a mail card no wider than a
 * phone, and a setting that forced two cramped panes there would be worse than
 * the one pane it replaced.
 */
enum ReadingPaneMode: string
{
    /** One pane at a time: the list, or the message that replaced it. */
    case Off = 'off';

    /** The message beside the list, divided by the drag handle. */
    case Right = 'right';

    /** Whether the message is drawn next to the list instead of in place of it. */
    public function besideList(): bool
    {
        return self::Right === $this;
    }

    /**
     * Whatever was stored, read charitably — the settings bag is untyped and
     * may hold a value written by an older version, or nothing at all.
     */
    public static function fromSetting(mixed $value, self $default = self::Off): self
    {
        return true === is_string($value) ? self::tryFrom($value) ?? $default : $default;
    }
}
