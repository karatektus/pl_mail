<?php

declare(strict_types=1);

namespace App\Domain\Enum\Theme;

enum Density: string
{
    case Comfortable = 'comfortable';
    case Cosy        = 'cosy';
    case Compact     = 'compact';

    public function rowPadding(): string
    {
        return match ($this) {
            self::Comfortable => '0.875rem',
            self::Cosy        => '0.625rem',
            self::Compact     => '0.375rem',
        };
    }

    public function gap(): string
    {
        return match ($this) {
            self::Comfortable => '0.75rem',
            self::Cosy        => '0.5rem',
            self::Compact     => '0.375rem',
        };
    }

    /**
     * Vertical padding for one row of the MAIL LIST.
     *
     * Its own scale rather than rowPadding(), for one reason worth stating:
     * Comfortable here is 0.625rem because that is the `py-2.5` the thread row
     * has always been hardcoded to. rowPadding()'s 0.875rem is the shell's
     * measure, and pointing the list at it would have made every existing
     * install's rows 4px taller the moment this shipped — a change nobody
     * asked for, dressed as a new feature. The default density therefore lands
     * on exactly today's geometry, and the other two steps go down from it.
     */
    public function listRowPadding(): string
    {
        return match ($this) {
            self::Comfortable => '0.625rem',
            self::Cosy        => '0.4375rem',
            self::Compact     => '0.25rem',
        };
    }

    /**
     * Vertical padding around one message in the reading pane.
     *
     * Same argument as listRowPadding(): Comfortable is the `py-4` the thread
     * message block already had, so nothing moves on deploy.
     */
    public function readingBlockPadding(): string
    {
        return match ($this) {
            self::Comfortable => '1rem',
            self::Cosy        => '0.75rem',
            self::Compact     => '0.5rem',
        };
    }
}
