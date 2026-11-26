<?php

declare(strict_types=1);

namespace App\Repository\Mail\UnifiedInbox;

use App\Entity\Mail\UnifiedInbox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<UnifiedInbox>
 */
class UnifiedInboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnifiedInbox::class);
    }

    /**
     * The reader's inboxes in sidebar order. The id breaks a tie, so two rows
     * that somehow share a position still come back in one order every time.
     *
     * @return list<UnifiedInbox>
     */
    public function findForUserOrdered(UserInterface $user): array
    {
        /** @var list<UnifiedInbox> */
        return $this->findBy(['usr' => $user], ['position' => 'ASC', 'id' => 'ASC']);
    }
}
