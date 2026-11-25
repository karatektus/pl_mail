<?php

declare(strict_types=1);

namespace App\Domain\Enum\Layout;

/**
 * What a pane in a workspace shows (#755).
 *
 * Five, and each at most once per workspace. The mail list is the reason for
 * "at most once": it is bound to the page's address, the list poll, the
 * selection and the arrow-key navigation, and a second independent list is a
 * feature of its own (a follow-up issue), not a case of this enum. The other
 * four are unique for a simpler reason: two copies of the address book side by
 * side would be the same address book twice.
 *
 * The cases are in the order a new workspace offers them and the order the
 * tray and the menus list them.
 */
enum PaneKind: string
{
    /** The conversations of a folder, a label or a search: the mail list frame. */
    case List = 'list';

    /**
     * The open conversation. Without this pane in the layout, a conversation
     * opens in place of the list, as it always did with the reading pane off.
     */
    case Message = 'message';

    case Calendar = 'calendar';

    case Contacts = 'contacts';

    case Tasks = 'tasks';

    /**
     * The narrowest this pane is usable at, in CSS pixels.
     *
     * A divider stops here and a split that would put a pane below it is
     * refused with a toast rather than drawn. The list's and the message's are
     * the floors the reading pane had (22rem and 24rem at the default text
     * size); the calendar's is CalendarView::minimumPaneWidth() for Day, and a
     * week or month in the pane raises it at run time (see the frame's
     * data-pane-min-width); the address book's is the floor its header was laid
     * out for (it used to be CONTACTS_PANE_MIN_WIDTH).
     */
    public function minWidth(): int
    {
        return match ($this) {
            self::List     => 352,
            self::Message  => 384,
            self::Calendar => 320,
            self::Contacts => 368,
            self::Tasks    => 320,
        };
    }

    /**
     * The lowest this pane is usable at, in CSS pixels, for a split above and
     * below. Its header plus a few rows: under this a list shows its toolbar
     * and nothing else.
     */
    public function minHeight(): int
    {
        return match ($this) {
            self::List, self::Message   => 220,
            self::Calendar              => 260,
            self::Contacts, self::Tasks => 200,
        };
    }

    /** The pane's name in menus, the tray and the tab strip. */
    public function transKey(): string
    {
        return 'layout.pane.' . $this->value;
    }

    /** Font Awesome classes for the pane's icon in menus and the tray. */
    public function icon(): string
    {
        return match ($this) {
            self::List     => 'fa-solid fa-inbox',
            self::Message  => 'fa-regular fa-envelope-open',
            self::Calendar => 'fa-regular fa-calendar',
            self::Contacts => 'fa-regular fa-address-book',
            self::Tasks    => 'fa-solid fa-list-check',
        };
    }
}
