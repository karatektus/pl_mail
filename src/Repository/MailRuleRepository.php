<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MailRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<MailRule>
 */
class MailRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailRule::class);
    }

    /**
     * @return list<MailRule>
     */
    public function findForUserOrdered(UserInterface $user): array
    {
        return $this->createQueryBuilder('rule')
            ->where('rule.usr = :usr')
            ->setParameter('usr', $user)
            ->orderBy('rule.sortOrder', 'ASC')
            ->addOrderBy('rule.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Enabled rules for a user, in execution order. Joins the account so the
     * engine's appliesTo() check does not fire a query per rule per message.
     *
     * @return list<MailRule>
     */
    public function findEnabledForUserOrdered(UserInterface $user): array
    {
        return $this->createQueryBuilder('rule')
            ->addSelect('account')
            ->leftJoin('rule.account', 'account')
            ->where('rule.usr = :usr')
            ->andWhere('rule.isEnabled = :enabled')
            ->setParameter('usr', $user)
            ->setParameter('enabled', true)
            ->orderBy('rule.sortOrder', 'ASC')
            ->addOrderBy('rule.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function nextSortOrder(UserInterface $user): int
    {
        $max = $this->createQueryBuilder('rule')
            ->select('MAX(rule.sortOrder)')
            ->where('rule.usr = :usr')
            ->setParameter('usr', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : ((int) $max) + 1;
    }
}
