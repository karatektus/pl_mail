<?php

declare(strict_types=1);

namespace App\Domain\Enum\Contacts;

/**
 * How much of the row the address book has: the calendar's three positions
 * (CalendarPaneMode), for the contacts pane, and for the same reason — the
 * topbar button is a switch that steps through them, so somebody can have the
 * address book full width without leaving the mail behind.
 *
 * `Contacts` is the full contacts page's layout (`data-contacts-full` on the
 * shell, see contacts/_page_shell.html.twig) on the mailbox itself: the mail
 * column put away, not gone, so opening a message from a contact brings it
 * back beside the contact (contacts--pane#reveal) instead of being a visit.
 *
 * The order of the cases is the order the switch cycles in, as the calendar's
 * is, and for its reason: a cycle that jumped is one nobody can predict.
 */
enum ContactsPaneMode: string
{
    /** No address book: the mail has the row. */
    case Mail = 'mail';

    /** The address book docked beside the mail. What "open" always meant. */
    case Split = 'split';

    /** The address book has the row; the mail is still there, behind it. */
    case Contacts = 'contacts';

    /**
     * The next position of the switch, wrapping, with CalendarPaneMode::next()'s
     * rules for a skipped position: the switch goes between the mail and the
     * one left, and from a skipped position that is still stored (a drag, or
     * a full page left by a message) one press is the mail.
     */
    public function next(ContactsSwitchSkip $skip = ContactsSwitchSkip::None): self
    {
        return match ($skip) {
            ContactsSwitchSkip::None => match ($this) {
                self::Mail     => self::Split,
                self::Split    => self::Contacts,
                self::Contacts => self::Mail,
            },
            ContactsSwitchSkip::Split    => self::Mail === $this ? self::Contacts : self::Mail,
            ContactsSwitchSkip::Contacts => self::Mail === $this ? self::Split : self::Mail,
        };
    }

    /** Whether the address book is on screen at all. */
    public function showsContacts(): bool
    {
        return self::Mail !== $this;
    }

    /** Font Awesome icon for the switch, saying which position it is in. */
    public function icon(): string
    {
        return match ($this) {
            self::Mail     => 'fa-regular fa-address-book',
            self::Split    => 'fa-solid fa-table-columns',
            self::Contacts => 'fa-solid fa-address-book',
        };
    }

    /** Translation key for "what pressing this does next". */
    public function nextTransKey(ContactsSwitchSkip $skip = ContactsSwitchSkip::None): string
    {
        return 'contacts.pane.mode.' . $this->next($skip)->value;
    }
}
