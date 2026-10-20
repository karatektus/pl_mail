<?php

declare(strict_types=1);

namespace App\Entity\Contacts;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An address-book entry owned by one user.
 */
#[ORM\Entity(repositoryClass: ContactRepository::class)]
#[ORM\Table(name: 'contacts_contact')]
#[ORM\Index(name: 'idx_contacts_contact_owner_updated', columns: ['usr_id', 'updated_at'])]
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

    #[ORM\Column(length: 255)]
    public string $displayName;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $givenName = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $familyName = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $nickname = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $company = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $jobTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $notes = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    public array $tags = [];

    /** Relative path under var/contacts, or null when no photo. */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $photoPath = null;

    /** manual | import:vcf | import:csv */
    #[ORM\Column(length: 32)]
    public string $source = 'manual';

    /** Soft delete; permanent purge after 90 days. */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $trashedAt = null;

    /** @var Collection<int, ContactEmail> */
    #[ORM\OneToMany(targetEntity: ContactEmail::class, mappedBy: 'contact', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public private(set) Collection $emails;

    /** @var Collection<int, ContactPhone> */
    #[ORM\OneToMany(targetEntity: ContactPhone::class, mappedBy: 'contact', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public private(set) Collection $phones;

    /** @var Collection<int, ContactAddress> */
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

        return $contact;
    }

    public function isTrashed(): bool
    {
        return null !== $this->trashedAt;
    }
}
