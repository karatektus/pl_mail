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
