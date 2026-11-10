<?php

declare(strict_types=1);

namespace App\Repository\Contacts\ContactRelation;

use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactRelation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Relations between contacts, from either end.
 *
 * @extends ServiceEntityRepository<ContactRelation>
 */
class ContactRelationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactRelation::class);
    }

    /**
     * The relations this contact owns: "her manager is …".
     *
     * @return list<ContactRelation>
     */
    public function findOwnedBy(Contact $contact): array
    {
        return $this->findBy(['ownerContact' => $contact]);
    }

    /**
     * The relations other contacts hold that name this one.
     *
     * @return list<ContactRelation>
     */
    public function findPointingAt(Contact $contact): array
    {
        return $this->findBy(['relatedContact' => $contact]);
    }

    /** The relation between two contacts of one kind, if there is one. */
    public function findBetween(Contact $owner, Contact $related, string $relType): ?ContactRelation
    {
        return $this->findOneBy([
            'ownerContact'   => $owner,
            'relatedContact' => $related,
            'relType'        => $relType,
        ]);
    }
}
