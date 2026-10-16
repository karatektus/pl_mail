<?php

declare(strict_types=1);

namespace App\Entity\AddressBook;

use App\Domain\DTO\AddressBook\VCardFields;
use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Trait\TimestampableTrait;
use App\Repository\AddressBook\ContactCardRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One vCard in a mirrored address book.
 *
 * **The card itself is stored, not just what plMail reads out of it.** $vcard
 * is the text the server last gave us (or that we last sent), and the columns
 * beside it are a projection for listing and searching. An edit made here is
 * applied *to that text* by VCardConverter::write(), so the photo, the postal
 * address and every X- property a phone wrote survive a round trip through a
 * client that has no field for them. Rebuilding the card from the columns
 * would strip all of that from the user's phone the first time they fixed a
 * typo in a name here.
 *
 * Local edits ride the calendar's SyncState: written here first, marked
 * pending, and pushed by the next sync — the same lifecycle CalendarEvent has,
 * so "my edit has not reached the server yet" means the same thing in both.
 *
 * $searchText is lower-cased name, organisation and addresses in one column,
 * so the composer's autocomplete is one LIKE per keystroke rather than a JSON
 * walk over every card.
 */
#[ORM\Entity(repositoryClass: ContactCardRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'contact_card')]
#[ORM\Index(name: 'idx_contact_card_book_state', columns: ['address_book_id', 'sync_state'])]
#[ORM\UniqueConstraint(name: 'uniq_contact_card_book_uid', columns: ['address_book_id', 'uid'])]
class ContactCard
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /**
     * Fixed at construction. Moving a card to another book is a delete in one
     * server collection and a create in another, not a reassignment — see
     * ContactCardType.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) AddressBook $addressBook;

    /**
     * The vCard UID. RFC 6352 §5.1 makes it unique within an address book, and
     * it is what a card created here is named after on the server.
     */
    #[ORM\Column(length: 255)]
    public private(set) string $uid;

    /** Absolute URL of the resource; null until a created card is first pushed. */
    #[ORM\Column(length: 1024, nullable: true)]
    public ?string $href = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $etag = null;

    #[ORM\Column(type: Types::TEXT)]
    public string $vcard = '';

    #[ORM\Column(length: 255)]
    public private(set) string $fullName = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public private(set) array $emails = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public private(set) array $phones = [];

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $organization = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $note = null;

    #[ORM\Column(type: Types::TEXT)]
    public private(set) string $searchText = '';

    #[ORM\Column(length: 20, enumType: SyncState::class, options: ['default' => 'clean'])]
    public SyncState $syncState = SyncState::Clean;

    public function __construct(AddressBook $addressBook, string $uid)
    {
        $this->addressBook = $addressBook;
        $this->uid         = mb_substr($uid, 0, 255);
    }

    /**
     * Copy what was read out of the card into the columns.
     *
     * The uid is not taken from $fields: it is the row's identity within its
     * book, fixed at construction, and a card whose UID changed on the server
     * is a different card as far as the unique key is concerned.
     */
    public function fill(VCardFields $fields): void
    {
        $this->fullName     = mb_substr($fields->fullName, 0, 255);
        $this->emails       = $fields->emails;
        $this->phones       = $fields->phones;
        $this->organization = null === $fields->organization ? null : mb_substr($fields->organization, 0, 255);
        $this->note         = $fields->note;
        $this->searchText   = mb_strtolower(implode(' ', array_filter([
            $fields->fullName,
            $fields->organization,
            ...$fields->emails,
        ], static fn (?string $part): bool => null !== $part && '' !== $part)));
    }

    /** What the card is called on screen, with a fallback for a card with no name. */
    public function displayName(): string
    {
        if ('' !== $this->fullName) {
            return $this->fullName;
        }

        return $this->emails[0] ?? $this->organization ?? $this->phones[0] ?? '';
    }

    /** Two letters for the avatar chip, the way Contact::$initials makes them. */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->displayName())) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => '' !== $part));

        if (count($parts) >= 2) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        }

        return mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 2));
    }
}
