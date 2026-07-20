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
     * custom labels after, alphabetically. Ordering on LOWER(name) so the
     * database collation cannot produce byte-wise ordering (uppercase
     * before '[' before lowercase).
     *
     * @return Label[]
     */
    public function findForAccount(Account $account): array
    {
        return $this->createQueryBuilder('label')
            ->addSelect('LOWER(label.name) AS HIDDEN sortName')
            ->where('label.account = :account')
            ->setParameter('account', $account)
            ->orderBy('label.sortOrder', 'ASC')
            ->addOrderBy('sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Labels for an account in settings display order: system labels first
     * by sortOrder, then the custom label tree depth-first with each level
     * sorted case-insensitively.
     *
     * @return Label[]
     */
    public function findForAccountTreeOrdered(Account $account): array
    {
        $labels         = $this->findForAccount($account);
        $system         = [];
        $customByParent = [];

        foreach ($labels as $label) {
            if (true === $label->isSystem) {
                $system[] = $label;
                continue;
            }

            $parentId = null !== $label->parent ? (int) $label->parent->id : 0;

            $customByParent[$parentId][] = $label;
        }

        usort($system, function (Label $a, Label $b): int {
            return ($a->sortOrder ?? 0) <=> ($b->sortOrder ?? 0);
        });

        $ordered = $system;

        $walk = function (int $parentId) use (&$walk, &$ordered, $customByParent): void {
            $children = $customByParent[$parentId] ?? [];

            usort($children, function (Label $a, Label $b): int {
                return mb_strtolower((string) $a->name) <=> mb_strtolower((string) $b->name);
            });

            foreach ($children as $child) {
                $ordered[] = $child;
                $walk((int) $child->id);
            }
        };

        $walk(0);

        return $ordered;
    }

    /**
     * Visible labels across all active accounts of a user — the sidebar
     * query. Case-insensitive name ordering.
     *
     * @return Label[]
     */
    public function findVisibleForUser(UserInterface $user): array
    {
        return $this->createQueryBuilder('label')
            ->addSelect('LOWER(label.name) AS HIDDEN sortName')
            ->innerJoin('label.account', 'account')
            ->where('account.usr = :usr')
            ->andWhere('account.isActive = :isActive')
            ->andWhere('label.isVisible = :isVisible')
            ->setParameter('usr', $user)
            ->setParameter('isActive', true)
            ->setParameter('isVisible', true)
            ->orderBy('label.sortOrder', 'ASC')
            ->addOrderBy('sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Resolve a merged sidebar path ("Work/Invoices") to every visible
     * custom Label of the user matching that full path, across all active
     * accounts. Candidates are narrowed by leaf name in SQL, the full
     * parent chain is verified in PHP via Label::$fullName.
     *
     * @return Label[]
     */
    public function findByPathForUser(UserInterface $user, string $path): array
    {
        $segments = array_values(array_filter(
            explode('/', $path),
            function (string $segment): bool {
                return '' !== trim($segment);
            },
        ));

        if (count($segments) === 0) {
            return [];
        }

        $leafName = end($segments);
        $fullName = implode('/', $segments);

        $candidates = $this->createQueryBuilder('label')
            ->innerJoin('label.account', 'account')
            ->where('account.usr = :usr')
            ->andWhere('account.isActive = :isActive')
            ->andWhere('label.isVisible = :isVisible')
            ->andWhere('label.role IS NULL')
            ->andWhere('LOWER(label.name) = :name')
            ->setParameter('usr', $user)
            ->setParameter('isActive', true)
            ->setParameter('isVisible', true)
            ->setParameter('name', mb_strtolower($leafName))
            ->getQuery()
            ->getResult();

        $matches = [];

        foreach ($candidates as $candidate) {
            if (mb_strtolower((string) $candidate->fullName) === mb_strtolower($fullName)) {
                $matches[] = $candidate;
            }
        }

        return $matches;
    }
}
