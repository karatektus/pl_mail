<?php

declare(strict_types=1);

namespace App\Entity\Contacts;

use App\Domain\Enum\Contacts\ContactChangeKind;
use App\Domain\Trait\TimestampableTrait;
use App\Repository\Contacts\ContactChangeLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only log of what happened to a user's contacts and address books; its
 * sequence is the JMAP state of both (RFC 9610 ContactCard and AddressBook).
 *
 * calendar_change_log's design, and its docblock gives the reasons at length:
 * keyed by user because contacts have no account, ids as scalars because a
 * tombstone outlives its row, written by ContactChangeLogListener inside the
 * flush so no writer can forget it.
 */
#[ORM\Entity(repositoryClass: ContactChangeLogRepository::class)]
#[ORM\Table(name: 'contact_change_log')]
#[ORM\Index(name: 'idx_contact_change_log_user_sequence', columns: ['user_id', 'sequence'])]
#[ORM\HasLifecycleCallbacks]
class ContactChangeLog
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public private(set) ?int $sequence = null;

    #[ORM\Column(name: 'user_id')]
    public private(set) int $userId;

    /** Null when the row is about an address book itself. */
    #[ORM\Column(name: 'contact_id', nullable: true)]
    public private(set) ?int $contactId;

    /** The address book a collection row is about; null on a contact's row. */
    #[ORM\Column(name: 'address_book_id', nullable: true)]
    public private(set) ?int $addressBookId;

    #[ORM\Column(name: 'change_kind', length: 16, enumType: ContactChangeKind::class)]
    public private(set) ContactChangeKind $changeKind;

    public function __construct(int $userId, ?int $contactId, ?int $addressBookId, ContactChangeKind $changeKind)
    {
        $this->userId        = $userId;
        $this->contactId     = $contactId;
        $this->addressBookId = $addressBookId;
        $this->changeKind    = $changeKind;
    }
}
