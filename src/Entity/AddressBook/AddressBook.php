<?php

declare(strict_types=1);

namespace App\Entity\AddressBook;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Repository\AddressBook\AddressBookRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One address book on a CardDAV server that plMail mirrors and writes back to.
 *
 * The contacts half of what Calendar is for a CalDAV collection, and shaped the
 * same way on purpose: the connection (Integration) holds the server and the
 * credential, this row holds one collection's address and how far the last
 * sync got, and the cards hang off it. Unsubscribing deletes the row and the
 * cards go with it by cascade — they are copies, and the server still has
 * them.
 *
 * Owned by the user directly as well as through the connection, so "this
 * user's address books" is one indexed column rather than a join through the
 * integration on every autocomplete keystroke.
 */
#[ORM\Entity(repositoryClass: AddressBookRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'address_book')]
#[ORM\Index(name: 'idx_address_book_usr', columns: ['usr_id'])]
#[ORM\UniqueConstraint(name: 'uniq_address_book_integration_remote', columns: ['integration_id', 'remote_id'])]
class AddressBook
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $usr;

    /** Deleting the connection deletes what was mirrored through it. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) Integration $integration;

    /**
     * Absolute URL of the collection, as discovery found it. Absolute for the
     * reason CalDavCalendarDriver gives for calendars: an href means something
     * only against the server that issued it, and bootstrapping routinely lands
     * on another host than the one typed.
     */
    #[ORM\Column(length: 1024)]
    public private(set) string $remoteId;

    #[ORM\Column(length: 120)]
    public string $name = '' {
        set {
            $trimmed = trim($value);

            $this->name = mb_substr('' === $trimmed ? 'Contacts' : $trimmed, 0, 120);
        }
    }

    /** The server's answer, re-read on every discovery — never a form's. */
    #[ORM\Column(options: ['default' => false])]
    public bool $isReadOnly = false;

    /**
     * Where the last pull stopped: an RFC 6578 sync-token, or a ctag on a
     * server without sync-collection. Opaque, and null means "read everything".
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $syncToken = null;

    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $lastSyncedAt = null;

    /** Written for a person, never carrying a credential, null when healthy. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $lastSyncError = null;

    public function __construct(User $usr, Integration $integration, string $remoteId, string $name = '')
    {
        $this->usr         = $usr;
        $this->integration = $integration;
        $this->remoteId    = $remoteId;
        $this->name        = $name;
    }

    public function recordSyncSuccess(): void
    {
        $this->lastSyncedAt  = new DateTimeImmutable();
        $this->lastSyncError = null;
    }

    /**
     * Note the failure for the settings list.
     *
     * lastSyncedAt moves on failure too. It is what the stale sweep reads, and
     * leaving it behind would make a broken book look due on every run instead
     * of every fifteen minutes. SyncAddressBookHandler compares the message
     * with the one stored before the run, so the same failure is logged once
     * rather than ninety-six times a day.
     */
    public function recordSyncFailure(string $reason): void
    {
        $this->lastSyncedAt  = new DateTimeImmutable();
        $this->lastSyncError = mb_substr($reason, 0, 500);
    }

    public function isHealthy(): bool
    {
        return null === $this->lastSyncError;
    }
}
