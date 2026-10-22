<?php

declare(strict_types=1);

namespace App\Entity\Contacts;

use App\Domain\Trait\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * A postal address belonging to a Contact.
 */
#[ORM\Entity]
#[ORM\Table(name: 'contacts_contact_address')]
#[ORM\HasLifecycleCallbacks]
class ContactAddress
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'addresses')]
    #[ORM\JoinColumn(name: 'contact_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) Contact $contact;

    /** home | work | other */
    #[ORM\Column(length: 32)]
    public string $label = 'other';

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $street = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $city = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $region = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $postcode = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $country = null;

    public static function create(Contact $contact, string $label = 'other'): self
    {
        $address = new self();
        $address->contact = $contact;
        $address->label = $label;
        $contact->addresses->add($address);

        return $address;
    }

    /**
     * Re-parents this row; the only writer of $contact outside create(), because the property is private(set).
     *
     * Both contacts' collections are kept in step. Moving only the owning side
     * left the row in the old contact's collection, and ContactMerger removes
     * that contact next — cascade remove and orphanRemoval then deleted the
     * row that had just been moved, so a merge silently lost the merged
     * contact's addresses.
     */
    public function moveTo(Contact $contact): void
    {
        if ($this->contact === $contact) {
            return;
        }

        $this->contact->addresses->removeElement($this);
        $this->contact = $contact;

        if (false === $contact->addresses->contains($this)) {
            $contact->addresses->add($this);
        }
    }
}
