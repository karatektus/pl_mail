<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use App\Repository\Mail\UnifiedInboxAccountRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Which unified inbox an account's mail is listed in (#265).
 *
 * A row of its own rather than a column on Account, and that is the point of
 * it: an account is in ONE inbox for now, which the unique constraint on the
 * account says, and in several later if that turns out to be wanted. Dropping
 * the constraint is then the whole schema change — the queries already take a
 * set of accounts per inbox, and the JMAP object already sends each inbox's
 * accounts as a list.
 *
 * An account with no row is in the reader's first inbox: a new account shows
 * up where the reader already reads mail, without every place that creates an
 * account having to know about inboxes. See UnifiedInboxes::membership().
 */
#[ORM\Entity(repositoryClass: UnifiedInboxAccountRepository::class)]
#[ORM\Table(name: 'unified_inbox_account')]
#[ORM\UniqueConstraint(name: 'uniq_unified_inbox_account_account', columns: ['account_id'])]
#[ORM\Index(name: 'idx_unified_inbox_account_inbox', columns: ['inbox_id'])]
class UnifiedInboxAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public UnifiedInbox $inbox;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Account $account;

    public function __construct(UnifiedInbox $inbox, Account $account)
    {
        $this->inbox   = $inbox;
        $this->account = $account;
    }
}
