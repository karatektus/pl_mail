<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use App\Domain\Enum\Mail\UnifiedInboxIcon;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Mail\UnifiedInbox\UnifiedInboxRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One of the reader's unified inboxes (#265): a name, an icon and a place in
 * the sidebar, over the accounts assigned to it.
 *
 * There used to be exactly one, the sidebar's Inbox, holding every account.
 * Now a reader can keep "Work" and "Personal" apart and still read each of
 * them as one list. Which accounts an inbox holds is not on this row: it is
 * UnifiedInboxAccount, one row per account, because an account lives in one
 * inbox and the question asked most is "where does this account go".
 *
 * ONE OF THEM IS "OTHER" ($isOther). It catches every account the reader took
 * out of the inboxes they made, so an account is never only reachable through
 * its own folder list. Accounts are not assigned to it as such — leaving an
 * inbox is what puts them there — and it cannot be deleted. It can be renamed,
 * given an icon and moved like the others, and the sidebar leaves it out while
 * it holds no switched-on account.
 *
 * Both rows a reader starts with — "Inbox" over everything, and an empty
 * Other — are made by UnifiedInboxes the first time they are needed.
 */
#[ORM\Entity(repositoryClass: UnifiedInboxRepository::class)]
#[ORM\Table(name: 'unified_inbox')]
// The only lookup: this reader's inboxes, on every sidebar render.
#[ORM\Index(name: 'idx_unified_inbox_usr', columns: ['usr_id'])]
// One Other per reader. UnifiedInboxes makes the starting rows the first time a
// reader's sidebar is drawn, and two requests racing there would otherwise
// leave two catch-alls that each claim the same accounts.
#[ORM\UniqueConstraint(name: 'uniq_unified_inbox_other', columns: ['usr_id'], options: ['where' => '(is_other = true)'])]
#[ORM\HasLifecycleCallbacks]
class UnifiedInbox
{
    use TimestampableTrait;

    public const int NAME_MAX_LENGTH = 40;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    /**
     * What the reader called it, or null for the word plMail uses — "Inbox",
     * or "Other" — in the reader's language.
     *
     * Null rather than "Inbox" written in, because the starting row is made
     * for everybody and a German reader's sidebar said "Posteingang" before
     * this existed. A stored English word would have changed that for every
     * one of them. Clearing the name in settings goes back to null.
     */
    #[ORM\Column(length: self::NAME_MAX_LENGTH, nullable: true)]
    public ?string $name = null;

    /**
     * A Font Awesome solid icon's name without its prefix — "inbox",
     * "briefcase". One of UnifiedInboxIcon's cases; a column of plain text
     * rather than an enum column so that the set can grow without a migration,
     * and an icon taken out of the set later falls back to the inbox tray
     * instead of failing to hydrate (UnifiedInboxIcon::of()).
     */
    #[ORM\Column(length: 40)]
    public string $icon = 'inbox';

    /** Where it sits in the sidebar, lowest first. */
    #[ORM\Column]
    public int $position = 0;

    #[ORM\Column(options: ['default' => false])]
    public bool $isOther = false;

    public function __construct(User $usr, ?string $name, string $icon = 'inbox', int $position = 0, bool $isOther = false)
    {
        $this->usr      = $usr;
        $this->name     = $name;
        $this->icon     = $icon;
        $this->position = $position;
        $this->isOther  = $isOther;
    }

    /** The icon as one of the set, whatever the column holds. */
    public function iconCase(): UnifiedInboxIcon
    {
        return UnifiedInboxIcon::of($this->icon);
    }
}
