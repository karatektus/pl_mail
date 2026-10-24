<?php

declare(strict_types=1);

namespace App\Repository\Mail;

use App\Entity\Mail\InboxCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<InboxCategory>
 */
class InboxCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxCategory::class);
    }

    /**
     * Every category the user made, oldest first. Where their tabs go among the
     * built-in ones is CategorySorting::$tabOrder, applied by InboxTabs.
     *
     * @return list<InboxCategory>
     */
    public function findForUserOrdered(UserInterface $user): array
    {
        /** @var list<InboxCategory> */
        return $this->findBy(['usr' => $user], ['id' => 'ASC']);
    }
}
