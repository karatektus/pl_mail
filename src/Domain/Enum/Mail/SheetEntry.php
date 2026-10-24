<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

use LogicException;

/**
 * What the long-press action sheet offers that a swipe does not.
 *
 * The sheet offers every SwipeAction (read/unread, archive, star) as well, under
 * the same rules, so those three are not repeated here. These are the entries
 * outside the swipe set: the ones a swipe must never commit by itself. Trash
 * is the clearest case. A swipe has no tap in front of it, and trash has no
 * Undo on a single row, so trash lives here and not in SwipeAction. Do not
 * move it back to "complete" that enum; SwipeActionTest guards the four-case set.
 *
 * Move, Labels and Select post nothing themselves. The first two open the
 * existing pickers and the last ticks the row's own checkbox, so they have no
 * route. Spam posts the thread's spam report with no filter, which is the strip's
 * own "move this to Spam" without the sender filters the spam menu adds.
 *
 * Which entries a given row offers depends on its placement, its draft flag and
 * the signed-in user's delete-now setting. That rule lives in
 * SwipeActionResolver::sheet(), where it can be read next to the strip it
 * mirrors. This enum says only what each entry is, where it posts, and how it
 * reads; each rule is an exhaustive match with no default, so a new case is an
 * error at the first match that reaches it.
 */
enum SheetEntry: string
{
    /** Move the row to the bin. The strip's trash button, with no Undo. */
    case Trash = 'trash';

    /** Move the row back to the inbox from the bin or spam. */
    case Restore = 'restore';

    /** Delete the row for good. Offered only with the user's delete-now setting. */
    case Purge = 'purge';

    /** Snooze the row. The wake time is the snooze menu's, posted with the URL. */
    case Snooze = 'snooze';

    /**
     * Pick a destination for the row. Opens the move picker; posts nothing itself. Threads only:
     * the picker's move route looks up thread ids, so a bare message has no Move.
     */
    case Move = 'move';

    /** Tick labels on the row. Opens the label picker; posts nothing itself. */
    case Labels = 'labels';

    /** Mark the conversation as spam, with no sender filter. Threads only. */
    case Spam = 'spam';

    /** Select the row for a bulk action. Ticks the row's checkbox; posts nothing. */
    case Select = 'select';

    /**
     * The status route the entry posts to, or null for the three that open a
     * picker or tick a checkbox. Route names are the ones the status controllers
     * declare; nothing here is a URL.
     */
    public function route(): ?string
    {
        return match ($this) {
            self::Trash   => 'app_status_trash',
            self::Restore => 'app_status_restore',
            self::Purge   => 'app_status_purge',
            self::Snooze  => 'app_status_snooze',
            self::Spam    => 'app_status_spam_report',
            self::Move    => null,
            self::Labels  => null,
            self::Select  => null,
        };
    }

    /**
     * The route, for a caller that expects the entry to post. Throws for the
     * client-only entries, naming them, so a picker that reaches a path expecting
     * a URL fails loudly instead of posting to nowhere.
     */
    public function requireRoute(): string
    {
        return $this->route() ?? throw new LogicException(sprintf(
            'Sheet entry "%s" has no route: it opens a picker or ticks a checkbox, so it cannot be posted.',
            $this->value,
        ));
    }

    /**
     * Whether the route names the conversation by type and id. Spam's report
     * route is under the thread alone, so it takes only the id.
     */
    public function takesType(): bool
    {
        return match ($this) {
            self::Spam => false,
            self::Trash, self::Restore, self::Purge, self::Snooze => true,
            self::Move, self::Labels, self::Select => true,
        };
    }

    /**
     * The translation key for the entry's label. Every key is one the strip or
     * the toolbar already uses, except the checkbox's, which the sheet adds
     * because "Select" there is announced for a row, not for a sheet entry.
     */
    public function labelKey(): string
    {
        return match ($this) {
            self::Trash   => 'thread_row.actions.delete',
            self::Restore => 'discarded.actions.restore',
            self::Purge   => 'discarded.actions.purge',
            self::Snooze  => 'thread_row.actions.snooze',
            self::Move    => 'toolbar.actions.move_to',
            self::Labels  => 'toolbar.actions.label',
            self::Spam    => 'toolbar.actions.spam',
            self::Select  => 'thread_row.sheet.select',
        };
    }

    /**
     * Whether the strip withholds the entry from drafts. Move, labels, snooze and
     * spam are the ones the strip withholds, and trash, restore and purge are
     * not. Select is on every row, drafts included, because the avatar checkbox is.
     */
    public function needsNotADraft(): bool
    {
        return match ($this) {
            self::Trash   => false,
            self::Restore => false,
            self::Purge   => false,
            self::Snooze  => true,
            self::Move    => true,
            self::Labels  => true,
            self::Spam    => true,
            self::Select  => false,
        };
    }
}
