<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Enum\LabelRole;
use App\Entity\Account;
use App\Entity\Label;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Label>
 */
class LabelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Label::class);
    }

    public function findOneByRoleForAccount(LabelRole $role, Account $account): ?Label
    {
        return $this->findOneBy(['account' => $account, 'role' => $role]);
    }

    /**
     * Find a label by leaf name under a given parent (null parent = root
     * level). This is the uniqueness check for find-or-create, since name
     * uniqueness is enforced at the service layer.
     */
    public function findOneChildByName(Account $account, ?Label $parent, string $name): ?Label
    {
        $queryBuilder = $this->createQueryBuilder('label')
            ->where('label.account = :account')
            ->andWhere('label.name = :name')
            ->setParameter('account', $account)
            ->setParameter('name', $name)
            ->setMaxResults(1);

        if (null === $parent) {
            $queryBuilder->andWhere('label.parent IS NULL');
        } else {
            $queryBuilder
                ->andWhere('label.parent = :parent')
                ->setParameter('parent', $parent);
        }

        return $queryBuilder->getQuery()->getOneOrNullResult();
    }

    public function findOneByGmailLabelId(string $gmailLabelId, Account $account): ?Label
    {
        return $this->findOneBy(['account' => $account, 'gmailLabelId' => $gmailLabelId]);
    }

    /**
     * All labels for an account: system block first (fixed sortOrder),
     * custom labels after, alphabetically. Postgres sorts NULLS LAST on
     * ASC by default, so a single ORDER BY does the job.
     *
     * @return Label[]
     */
    public function findForAccount(Account $account): array
    {
        return $this->createQueryBuilder('label')
            ->where('label.account = :account')
            ->setParameter('account', $account)
            ->orderBy('label.sortOrder', 'ASC')
            ->addOrderBy('label.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Visible labels across all active accounts of a user — the sidebar
     * query for Phase 5.
     *
     * @return Label[]
     */
    public function findVisibleForUser(UserInterface $user): array
    {
        return $this->createQueryBuilder('label')
            ->innerJoin('label.account', 'account')
            ->where('account.usr = :usr')
            ->andWhere('account.isActive = :isActive')
            ->andWhere('label.isVisible = :isVisible')
            ->setParameter('usr', $user)
            ->setParameter('isActive', true)
            ->setParameter('isVisible', true)
            ->orderBy('label.sortOrder', 'ASC')
            ->addOrderBy('label.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
