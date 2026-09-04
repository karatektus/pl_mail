<?php

declare(strict_types=1);

namespace App\Domain\Enum\Job;

/**
 * What a background job is doing, in the user's terms.
 *
 * The value is a translation key suffix rather than a class name, because this
 * is read by a person watching a progress indicator: "Marking as read" is the
 * answer to "what is happening", and `App\…\BulkStatusMessage` is not.
 */
enum JobKind: string
{
    case MarkRead   = 'mark_read';
    case MarkUnread = 'mark_unread';
    case Archive    = 'archive';
    case Trash      = 'trash';
    case Restore    = 'restore';

    /**
     * Asking the assistant again about mail it has already sorted.
     *
     * THE ODD ONE OUT, and deliberately admitted rather than disguised. Every
     * other kind here is a bulk action over a mail view — the enum grew around
     * that shape, which is why {@see action()} and {@see readFlag()} exist. This
     * one is a run of model calls and has no bulk action behind it.
     *
     * It is here anyway because what a reader needs from it is exactly what the
     * jobs indicator already provides: is it running, how far has it got, did it
     * fail. Building a second progress mechanism beside a working one, for a
     * feature whose whole complaint was "I cannot tell whether it is done",
     * would be two things to keep in step and one more thing to learn to read.
     */
    case Reclassify = 'reclassify';

    /** What the indicator calls it while it runs. */
    public function labelKey(): string
    {
        return 'jobs.kind.' . $this->value;
    }

    /**
     * The bulk action this kind performs.
     *
     * The two mark kinds are one action with a flag, which is how
     * BulkStatusController has always expressed it — kept that way here so the
     * handler does not have to invent a second vocabulary.
     */
    public function action(): string
    {
        return match ($this) {
            self::MarkRead, self::MarkUnread => 'read',
            self::Archive                    => 'archive',
            self::Trash                      => 'trash',
            self::Restore                    => 'restore',
            // Reclassify has no bulk action behind it and no caller that could
            // want one: RunBulkStatusHandler is the only reader of this method
            // and only ever holds a kind it was dispatched for. Throwing beats
            // inventing a string, which would be silently accepted by a
            // switch somewhere and act on somebody's mail.
            self::Reclassify => throw new \LogicException(
                'Reclassify is not a bulk action; nothing should be asking it for one.',
            ),
        };
    }

    public function readFlag(): bool
    {
        return self::MarkRead === $this;
    }

    public static function forAction(string $action, bool $read): self
    {
        return match ($action) {
            'read'    => true === $read ? self::MarkRead : self::MarkUnread,
            'archive' => self::Archive,
            'trash'   => self::Trash,
            'restore' => self::Restore,
            default   => throw new \InvalidArgumentException(sprintf('Unknown bulk action "%s".', $action)),
        };
    }
}
