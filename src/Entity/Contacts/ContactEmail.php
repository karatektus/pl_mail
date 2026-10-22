<?php

declare(strict_types=1);

namespace App\Entity\Contacts;

use App\Domain\Trait\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * An email address belonging to a Contact. Stored normalized lowercase.
 */
#[ORM\Entity]
#[ORM\Table(name: 'contacts_contact_email')]
#[ORM\UniqueConstraint(name: 'uniq_contacts_email_contact_email', columns: ['contact_id', 'email'])]
#[ORM\HasLifecycleCallbacks]
class ContactEmail
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'emails')]
    #[ORM\JoinColumn(name: 'contact_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) Contact $contact;

    /** Normalized lowercase. */
    #[ORM\Column(length: 320)]
    public string $email;

    /** home | work | other */
    #[ORM\Column(length: 32)]
    public string $label = 'other';

    #[ORM\Column(name: 'is_primary')]
    public bool $isPrimary = false;

    public static function create(Contact $contact, string $email, string $label = 'other', bool $isPrimary = false): self
    {
        $emailEntity = new self();
        $emailEntity->contact = $contact;
        $emailEntity->email = mb_strtolower(trim($email));
        $emailEntity->label = $label;
        $emailEntity->isPrimary = $isPrimary;
        // See ContactPhone::create(): keep $contact->emails in step with the new row.
        $contact->emails->add($emailEntity);

        return $emailEntity;
    }

    /**
     * Re-parents this row; the only writer of $contact outside create(), because the property is private(set).
     *
     * Both contacts' collections are kept in step. Moving only the owning side
     * left the row in the old contact's collection, and ContactMerger removes
     * that contact next — cascade remove and orphanRemoval then deleted the
     * row that had just been moved, so a merge silently lost the merged
     * contact's emails.
     */
    public function moveTo(Contact $contact): void
    {
        if ($this->contact === $contact) {
            return;
        }

        $this->contact->emails->removeElement($this);
        $this->contact = $contact;

        if (false === $contact->emails->contains($this)) {
            $contact->emails->add($this);
        }
    }
}
