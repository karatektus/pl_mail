<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * The system rows of the sidebar's nav block — the ones a person can choose to
 * keep on top or tuck behind "More".
 *
 * Its own enum rather than LabelRole because the two sets differ at both ends:
 * **Starred** is a row with no label behind it (it is a flag on a
 * conversation), and the calendar link that joins the block in the mobile
 * drawer is not a mail row at all. The overlap is exactly the part that
 * matters, and {@see role()} is where they meet.
 *
 * **The case order is the display order.** The sidebar draws the rows it is
 * told to in this sequence, in whichever group they land, so a choice never
 * reorders anything — it only moves a row between the two groups. Nothing
 * else says what the order is, which is why a test pins it.
 *
 * The value is also the key `sidebar.nav.<value>` is translated under and the
 * string a person's choice is stored as, so renaming a case would orphan every
 * stored choice and every label that was found by it. Add cases freely; do not
 * rename.
 */
enum SidebarRow: string
{
    case Inbox = 'inbox';
    case Unread = 'unread';
    case Starred = 'starred';
    case Sent = 'sent';
    case Drafts = 'drafts';
    case Snoozed = 'snoozed';
    case Archive = 'archive';
    case Spam = 'spam';
    case Trash = 'trash';

    /**
     * Whether the row stays on top whatever anybody chooses.
     *
     * Only the Inbox. A disclosure with the Inbox inside it reads as a broken
     * sidebar, and the open-on-the-current-page rule that keeps More from
     * snapping shut would then have to carry the most-visited page of all.
     * Every other row is somebody's call.
     */
    public function isPinned(): bool
    {
        return self::Inbox === $this;
    }

    /**
     * The rows that were behind "More" before this was a choice — and so what
     * everybody who has never chosen still gets, which is the point: shipping
     * the setting moved nobody's sidebar.
     *
     * @return list<self>
     */
    public static function defaultBehindMore(): array
    {
        return [self::Snoozed, self::Archive, self::Spam, self::Trash];
    }

    /**
     * The label role this row stands for, or null for Starred, which is a flag
     * on a conversation and has no label.
     *
     * Same string as the row's own value for every row that has one. That is
     * deliberate and tested: it is what lets `sidebar.nav.<value>` and the
     * role-keyed badge and new-mail dot all be found from either side.
     */
    public function role(): ?LabelRole
    {
        return self::Starred === $this ? null : LabelRole::from($this->value);
    }

    /** The route of the list this row opens. */
    public function route(): string
    {
        return 'app_mail_' . $this->value;
    }
}
