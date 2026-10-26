<?php

declare(strict_types=1);

namespace App\Entity\Contacts;

use App\Domain\Trait\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * A phone number belonging to a Contact. Digits plus an optional leading +,
 * spaces/dashes/parens stripped by the service layer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'contacts_contact_phone')]
#[ORM\UniqueConstraint(name: 'uniq_contacts_phone_contact_phone', columns: ['contact_id', 'phone'])]
#[ORM\HasLifecycleCallbacks]
class ContactPhone
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'phones')]
    #[ORM\JoinColumn(name: 'contact_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) Contact $contact;

    #[ORM\Column(length: 64)]
    public string $phone;

    /** home | work | mobile | other */
    #[ORM\Column(length: 32)]
    public string $label = 'other';

    #[ORM\Column(name: 'is_primary')]
    public bool $isPrimary = false;

    public static function create(Contact $contact, string $phone, string $label = 'other', bool $isPrimary = false): self
    {
        $phoneEntity = new self();
        $phoneEntity->contact = $contact;
        $phoneEntity->phone = $phone;
        $phoneEntity->label = $label;
        $phoneEntity->isPrimary = $isPrimary;
        // Keep the in-memory collection in step with the new row: callers
        // de-duplicate by looking at $contact->phones, and the collection of
        // a freshly created contact is never reloaded from the database.
        $contact->phones->add($phoneEntity);

        return $phoneEntity;
    }

    /**
     * Re-parents this row; the only writer of $contact outside create(), because the property is private(set).
     *
     * Both contacts' collections are kept in step. Moving only the owning side
     * left the row in the old contact's collection, and ContactMerger removes
     * that contact next — cascade remove and orphanRemoval then deleted the
     * row that had just been moved, so a merge silently lost the merged
     * contact's phones.
     */
    public function moveTo(Contact $contact): void
    {
        if ($this->contact === $contact) {
            return;
        }

        $this->contact->phones->removeElement($this);
        $this->contact = $contact;

        if (false === $contact->phones->contains($this)) {
            $contact->phones->add($this);
        }
    }
}
