<?php

declare(strict_types=1);

namespace App\Entity\Contacts;

use App\Domain\Trait\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * A typed link between two contacts (assistant, manager, partner, ...).
 */
#[ORM\Entity]
#[ORM\Table(name: 'contacts_contact_relation')]
#[ORM\UniqueConstraint(name: 'uniq_contacts_relation_triple', columns: ['owner_contact_id', 'related_contact_id', 'rel_type'])]
#[ORM\HasLifecycleCallbacks]
class ContactRelation
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'owner_contact_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) Contact $ownerContact;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'related_contact_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) Contact $relatedContact;

    /** related | assistant | manager | partner */
    #[ORM\Column(name: 'rel_type', length: 32)]
    public string $relType = 'related';

    public static function create(Contact $ownerContact, Contact $relatedContact, string $relType = 'related'): self
    {
        $relation = new self();
        $relation->ownerContact = $ownerContact;
        $relation->relatedContact = $relatedContact;
        $relation->relType = $relType;

        return $relation;
    }

    /** Points the relation at different contacts; the properties are private(set). */
    public function repoint(Contact $ownerContact, Contact $relatedContact): void
    {
        $this->ownerContact = $ownerContact;
        $this->relatedContact = $relatedContact;
    }
}
