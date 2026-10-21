<?php

declare(strict_types=1);

namespace App\Entity\Contacts;

use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Trait\TimestampableTrait;
use App\Entity\AddressBook\AddressBook;
use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An address-book entry owned by one user.
 *
 * **Where it lives.** A contact with no address book is plMail's own: it
 * exists here and nowhere else. One with an address book is a mirror of a
 * contact on a server — a CardDAV card (Nextcloud, or any CardDAV server), a
 * Google contact, an Outlook contact — and every edit to it is sent back there
 * by AddressBookSync.
 *
 * **Rows and the raw card, both.** The page, JMAP and autocomplete read the
 * rows — names, emails, phones, addresses — so a synced contact and a local one
 * look and search the same. A synced contact also keeps the card exactly as
 * the server sent it in $vcard. A vCard can carry more than plMail has
 * fields for (a phone's own X- properties, a sound, a key), and
 * rebuilding the card from the rows on every edit would strip what plMail
 * does not model from the person's phone the first time they fixed a typo
 * here. So an edit patches
 * only what the rows model into the stored card (VCardMapper::write()), and the
 * rest goes back to the server as it came.
 *
 * Google and Outlook keep the same promise a different way: their APIs update
 * only the fields named in the request, so the server keeps what plMail does
 * not show, and there is no card to patch. What their drivers need to write
 * back (Google's source etags, say) is in $remoteResource, opaque, and $vcard
 * stays null — it only ever holds a vCard.
 *
 * $uid is the card's UID, and every contact has one, local or not: it is what
 * an export carries, and what keeps the contact the same card if it is ever
 * written to a server.
 */
#[ORM\Entity(repositoryClass: ContactRepository::class)]
#[ORM\Table(name: 'contacts_contact')]
#[ORM\Index(name: 'idx_contacts_contact_owner_updated', columns: ['usr_id', 'updated_at'])]
#[ORM\Index(name: 'idx_contacts_contact_book_state', columns: ['address_book_id', 'sync_state'])]
#[ORM\UniqueConstraint(name: 'uniq_contacts_contact_book_uid', columns: ['address_book_id', 'uid'])]
#[ORM\HasLifecycleCallbacks]
class Contact
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $usr;

    /** Null for plMail's own contacts; see the class comment. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'address_book_id', nullable: true, onDelete: 'CASCADE')]
    public ?AddressBook $addressBook = null;

    #[ORM\Column(length: 255)]
    public string $uid;

    /** Where the card lives on the server; null until it has been written there. */
    #[ORM\Column(length: 1024, nullable: true)]
    public ?string $href = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $etag = null;

    /** The card as the server last sent it, with this side's edits patched in. Null for a local contact. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $vcard = null;

    /**
     * For a book whose server is not CardDAV, whatever its driver kept from the
     * last read to make the next write — opaque here, like $href and $etag.
     * Null for CardDAV, whose card is $vcard, and for a local contact.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $remoteResource = null;

    /** Always Clean for a local contact: there is nowhere to push it. */
    #[ORM\Column(length: 20, enumType: SyncState::class, options: ['default' => 'clean'])]
    public SyncState $syncState = SyncState::Clean;

    #[ORM\Column(length: 255)]
    public string $displayName;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $givenName = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $familyName = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $namePrefix = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $middleName = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $nameSuffix = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $phoneticGivenName = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $phoneticMiddleName = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $phoneticFamilyName = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $nickname = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $company = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $phoneticCompany = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $department = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $jobTitle = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $officeLocation = null;

    /** `YYYY-MM-DD`, or `--MM-DD` for a birthday without a year; see ContactDate. */
    #[ORM\Column(length: 10, nullable: true)]
    public ?string $birthday = null;

    /*
     * The lists below are JSON, not rows: nothing searches them, and each is
     * read and written whole. Labels are free text — the known ones
     * (ContactFields) are translated, any other (a provider's custom label)
     * is shown as typed and goes back as it came.
     */

    /** @var list<array{label: string, date: string}> anniversaries and other dates; the birthday is $birthday */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $dates = [];

    /** @var list<array{label: string, name: string}> people by name (spouse, child, manager, …) */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $relations = [];

    /** @var list<array{label: string, url: string}> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $urls = [];

    /** @var list<array{service: string, handle: string, label: string}> chat and SIP handles */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $ims = [];

    /** @var list<array{label: string, value: string}> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $customFields = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $notes = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    public array $tags = [];

    /** Relative path under var/contacts, or null when no photo. */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $photoPath = null;

    /**
     * The photo was changed here (uploaded or removed) and the server does
     * not have it yet. Set by ContactSyncTracker, cleared by the push that
     * sends it; only ever true for a synced contact.
     */
    #[ORM\Column(options: ['default' => false])]
    public bool $photoDirty = false;

    /**
     * What the server's photo was when it was last read — a hash of a
     * vCard's bytes, Google's photo URL — so a pull that brings the same
     * photo does not store it again. Null when the server says nothing that
     * identifies it (Outlook) or there is none.
     */
    #[ORM\Column(length: 2048, nullable: true)]
    public ?string $photoVersion = null;

    /** manual | import:vcf | import:csv */
    #[ORM\Column(length: 32)]
    public string $source = 'manual';

    /** Soft delete; permanent purge after 90 days. */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $trashedAt = null;

    /*
     * The three below are read in the order they were added. Without an
     * ORDER BY, Postgres hands rows back in whatever order its heap has them,
     * and an UPDATE moves a row: edit a contact's first address and it came
     * back last. The page, the vCard and JMAP all show these as lists.
     */

    /** @var Collection<int, ContactEmail> */
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[ORM\OneToMany(targetEntity: ContactEmail::class, mappedBy: 'contact', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public private(set) Collection $emails;

    /** @var Collection<int, ContactPhone> */
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[ORM\OneToMany(targetEntity: ContactPhone::class, mappedBy: 'contact', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public private(set) Collection $phones;

    /** @var Collection<int, ContactAddress> */
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[ORM\OneToMany(targetEntity: ContactAddress::class, mappedBy: 'contact', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public private(set) Collection $addresses;

    public function __construct()
    {
        $this->emails = new ArrayCollection();
        $this->phones = new ArrayCollection();
        $this->addresses = new ArrayCollection();
    }

    public static function create(User $usr, string $displayName): self
    {
        $contact = new self();
        $contact->usr = $usr;
        $contact->displayName = $displayName;
        $contact->uid = Uuid::v4()->toRfc4122();

        return $contact;
    }

    public function isTrashed(): bool
    {
        return null !== $this->trashedAt;
    }

    /**
     * Whether this contact mirrors a card on a server.
     */
    public function isSynced(): bool
    {
        return null !== $this->addressBook;
    }

    /**
     * Whether a person may change or delete it here. A card in a read-only
     * book (a shared book, a global address list) is shown but never edited:
     * the server would refuse the write, and the next pull would put it back.
     */
    public function isEditable(): bool
    {
        return true !== $this->addressBook?->isReadOnly;
    }
}
