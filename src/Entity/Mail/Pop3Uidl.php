<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use App\Repository\Mail\Pop3UidlRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One message a POP3 server holds for an account, as this installation knows
 * it: by the UIDL the server gave it (#289).
 *
 * POP3 has no sync cursor and no folder to keep a high-water mark on, so this
 * table IS the sync's memory. A message the server lists is new exactly when
 * no row here names its UIDL.
 *
 * A table and not a list in the account's settings bag, which is where this
 * started. A list has to be capped to keep the bag small, and any cap is wrong
 * for a mailbox that keeps its mail on the server: the server lists the whole
 * mailbox on every sync, so the oldest UIDLs fell off the end, came back as
 * new, were fetched again, and pushed the next oldest off in turn. Here the
 * bound is the server itself — every sync drops the rows for UIDLs the server
 * no longer lists (Pop3UidlRepository::prune()) — so the table holds exactly
 * as many rows as the mailbox has messages, and never more.
 *
 * ── Stored, or only tried ────────────────────────────────────────────────────
 * A row with $storedAt is a message that is here. A row without one is a
 * message that could not be fetched or could not be stored, and $attempts
 * says how often. Only stored rows may ever be deleted from the server, and
 * the difference is the reason this is a row rather than a set of strings:
 * marking a message seen because it was in the batch, whether or not it made
 * it, is how a message that failed to parse would have been deleted from the
 * only place it existed.
 */
#[ORM\Entity(repositoryClass: Pop3UidlRepository::class)]
#[ORM\Table(name: 'pop3_uidl')]
#[ORM\UniqueConstraint(name: 'uniq_pop3_uidl_account_uidl', columns: ['account_id', 'uidl'])]
class Pop3Uidl
{
    /**
     * How many failed attempts a message gets before the sync stops asking.
     *
     * The same three IMAP gives a message it cannot build (see
     * MessageSyncer::holdForRetry()). A message that can never be parsed would
     * otherwise be downloaded again on every poll, for ever. Given up on, it
     * stays on the server — it was never stored, so no delete mode touches it.
     */
    public const int MAX_ATTEMPTS = 3;

    /** RFC 1939 §7: a UIDL is 1 to 70 characters. */
    public const int UIDL_LENGTH = 70;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) Account $account;

    #[ORM\Column(length: self::UIDL_LENGTH)]
    public private(set) string $uidl;

    /** When the message was stored here (or recognised as already here); null while it is not. */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $storedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $attempts = 0;

    public function __construct(Account $account, string $uidl)
    {
        $this->account = $account;
        $this->uidl    = $uidl;
    }
}
