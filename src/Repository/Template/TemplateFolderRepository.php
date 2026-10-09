<?php

declare(strict_types=1);

namespace App\Repository\Template;

use App\Entity\Template\TemplateFolder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<TemplateFolder>
 */
class TemplateFolderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TemplateFolder::class);
    }

    /**
     * Every folder of one user, alphabetically. The tree is assembled in PHP
     * from this one read rather than fetched level by level: a user has a
     * handful of folders, and a query per level is a query per click of depth.
     *
     * @return list<TemplateFolder>
     */
    public function findForUser(UserInterface $user): array
    {
        return $this->findBy(['usr' => $user], ['name' => 'ASC', 'id' => 'ASC']);
    }
}
