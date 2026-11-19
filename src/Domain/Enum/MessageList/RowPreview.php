<?php

declare(strict_types=1);

namespace App\Domain\Enum\MessageList;

/**
 * How much of a message's text a list row shows (#608).
 *
 * Inline is the preview after the subject on the subject's own line, cut where
 * the line ends. One and Two give it a line, or two, of its own underneath.
 *
 * This shapes the STACKED row only. A one-line row has no line underneath to
 * give, so it shows any preview inline and None is the one choice it hears.
 * Two is the ceiling because the stacked row has room for two; three was tried
 * when this was a count and was a wall of grey text at any density below
 * Comfortable.
 */
enum RowPreview: string
{
    case None   = 'none';
    case Inline = 'inline';
    case One    = 'one';
    case Two    = 'two';

    /**
     * The count the JMAP Appearance object has always published as
     * `previewLines`, for the apps written against it. Inline answers 1: it is
     * one line of preview, and an app that only knows counts draws one line.
     */
    public function lines(): int
    {
        return match ($this) {
            self::None => 0,
            self::Inline, self::One => 1,
            self::Two => 2,
        };
    }

    /**
     * The other way, for an app that still writes `previewLines`. Out of range
     * is clamped to the nearest end, as the count's own setter always did.
     */
    public static function fromLines(int $lines): self
    {
        return match (true) {
            $lines <= 0 => self::None,
            1 === $lines => self::One,
            default => self::Two,
        };
    }
}
