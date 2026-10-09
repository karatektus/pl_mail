<?php

declare(strict_types=1);

namespace App\Repository\Template;

use App\Entity\Template\MailTemplate;
use App\Entity\Template\TemplateFolder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<MailTemplate>
 */
class MailTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailTemplate::class);
    }

    /**
     * Every template of one user, alphabetically — the same single read the
     * folders get, for the same reason.
     *
     * @return list<MailTemplate>
     */
    public function findForUser(UserInterface $user): array
    {
        return $this->findBy(['usr' => $user], ['name' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @param list<TemplateFolder> $folders
     *
     * @return list<MailTemplate>
     */
    public function findInFolders(array $folders): array
    {
        if ([] === $folders) {
            return [];
        }

        return $this->findBy(['folder' => $folders]);
    }
}
