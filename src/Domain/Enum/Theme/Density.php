<?php

declare(strict_types=1);

namespace App\Domain\Enum\Theme;

enum Density: string
{
    case Comfortable = 'comfortable';
    case Cosy        = 'cosy';
    case Compact     = 'compact';

    /**
     * Vertical padding for one row of the SHELL — which in practice means the
     * sidebar: its system rows, the "More" disclosure, the account rows and the
     * Compose button.
     *
     * Comfortable is 0.5rem because that is the `py-2` every one of those rows
     * has always been hardcoded to. It read 0.875rem until density actually
     * reached the sidebar, and that number was never anything's measure: the
     * only element consuming it was the Compose button, which `py-row` had
     * quietly grown by 6px a side the day the token was introduced. Pointing
     * fifteen more rows at 0.875rem would have made every install's sidebar
     * 12px-per-row taller on deploy — so the TOKEN was the thing that was
     * wrong, and this is the reconciliation. See listRowPadding() below, which
     * has made the same argument since it shipped.
     *
     * Rows are 20px of line box plus twice this: 36 / 32 / 28px.
     */
    public function rowPadding(): string
    {
        return match ($this) {
            self::Comfortable => '0.5rem',
            self::Cosy        => '0.375rem',
            self::Compact     => '0.25rem',
        };
    }

    /**
     * Vertical padding for a row one tier IN from a system row: the label tree
     * at every depth, and an account's own folder list.
     *
     * Its own scale rather than rowPadding(), because these rows are `py-1.5`
     * and the ones above them are `py-2` — they have always been visibly
     * tighter, and collapsing the two would move one of them at Comfortable.
     *
     * Rows are 20px of line box plus twice this: 32 / 28 / 24px.
     */
    public function treeRowPadding(): string
    {
        return match ($this) {
            self::Comfortable => '0.375rem',
            self::Cosy        => '0.25rem',
            self::Compact     => '0.125rem',
        };
    }

    /**
     * The shell's GUTTER — the space between panes and the frame around them.
     *
     * Not the space between two sidebar rows; that is rowGap(). The two were
     * one value for as long as neither had a second consumer, and the sidebar
     * is where they part company: 12px between panes is right, 12px between
     * two nav rows is not remotely.
     */
    public function gap(): string
    {
        return match ($this) {
            self::Comfortable => '0.75rem',
            self::Cosy        => '0.5rem',
            self::Compact     => '0.375rem',
        };
    }

    /**
     * The space between two adjacent sidebar rows — `space-y-0.5` at
     * Comfortable, which is what every list in that file is hardcoded to.
     *
     * Compact closes it entirely. The rows keep their own padding, so they do
     * not run together; what goes is the hairline of background between them.
     */
    public function rowGap(): string
    {
        return match ($this) {
            self::Comfortable => '0.125rem',
            self::Cosy        => '0.0625rem',
            self::Compact     => '0',
        };
    }

    /**
     * The breathing room ABOVE a sidebar section heading — `pt-4` today.
     *
     * This is the gap that separates the groups from each other, and it is the
     * one a person actually reads as "how tight is this sidebar": the rows
     * moving 4px each is subtle, a 16px band shrinking to 8px is not.
     */
    public function sectionPadding(): string
    {
        return match ($this) {
            self::Comfortable => '1rem',
            self::Cosy        => '0.75rem',
            self::Compact     => '0.5rem',
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
