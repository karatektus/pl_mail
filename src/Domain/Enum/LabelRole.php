<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * System role of a Label. User-created labels have a null role.
 *
 * Note: Archive exists primarily as an IMAP location-bookkeeping label —
 * "archived" in the domain model means "carries no Inbox label". The
 * Archive label marks messages physically stored in the server's Archive
 * folder so the location-label invariant holds for plain IMAP accounts.
 * It is created hidden, but the user can switch it visible in the label
 * settings, which surfaces an Archive entry in the sidebar.
 */
enum LabelRole: string
{
    case Inbox = 'inbox';
    case Sent = 'sent';
    case Drafts = 'drafts';
    case Trash = 'trash';
    case Spam = 'spam';
    case Archive = 'archive';

    public static function fromSpecialUse(MailboxSpecialUse $specialUse): self
    {
        return match ($specialUse) {
            MailboxSpecialUse::INBOX => self::Inbox,
            MailboxSpecialUse::SENT => self::Sent,
            MailboxSpecialUse::DRAFTS => self::Drafts,
            MailboxSpecialUse::TRASH => self::Trash,
            MailboxSpecialUse::JUNK => self::Spam,
            MailboxSpecialUse::ARCHIVE => self::Archive,
        };
    }

    public function displayName(): string
    {
        return match ($this) {
            self::Inbox => 'Inbox',
            self::Sent => 'Sent',
            self::Drafts => 'Drafts',
            self::Trash => 'Trash',
            self::Spam => 'Spam',
            self::Archive => 'Archive',
        };
    }

    /**
     * Fixed sidebar ordering for system labels. Custom labels sort after
     * these, alphabetically (sortOrder null).
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::Inbox => 0,
            self::Sent => 10,
            self::Drafts => 20,
            self::Spam => 30,
            self::Trash => 40,
            self::Archive => 50,
        };
    }

    /**
     * CREATION DEFAULT for Label::$isVisible — used only when the label
     * row is first created (LabelResolver::systemLabel). After creation,
     * visibility belongs to the user via the label settings and this
     * method must not be consulted again.
     */
    public function isVisible(): bool
    {
        if (self::Archive === $this) {
            return false;
        }

        return true;
    }
}
