<?php

declare(strict_types=1);

namespace App\Repository\Label;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Entity\Label\Label;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Labels are user-scoped. Queries that used to narrow by account either
 * narrow by user instead, or go through LabelBinding when the caller really
 * does mean "on this account" — see LabelBindingRepository.
 *
 * @extends ServiceEntityRepository<Label>
 */
class LabelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Label::class);
    }

    public function findOneByRoleForUser(LabelRole $role, UserInterface $user): ?Label
    {
        return $this->findOneBy(['usr' => $user, 'role' => $role]);
    }

    /**
     * Find a label by leaf name under a given parent (null parent = root
     * level). This is the uniqueness check for find-or-create.
     */
    public function findOneChildByName(UserInterface $user, ?Label $parent, string $name): ?Label
    {
        $queryBuilder = $this->createQueryBuilder('label')
            ->where('label.usr = :usr')
            ->andWhere('label.name = :name')
            ->setParameter('usr', $user)
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

    /**
     * All labels of a user: system block first (fixed sortOrder), custom
     * labels after, alphabetically. Ordering on LOWER(name) so the database
     * collation cannot produce byte-wise ordering (uppercase before '['
     * before lowercase).
     *
     * @return Label[]
     */
    public function findForUser(UserInterface $user): array
    {
        return $this->createQueryBuilder('label')
            ->addSelect('LOWER(label.name) AS HIDDEN sortName')
            ->where('label.usr = :usr')
            ->setParameter('usr', $user)
            ->orderBy('label.sortOrder', 'ASC')
            ->addOrderBy('sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Labels in settings display order: system labels first by sortOrder,
     * then the custom label tree depth-first with each level sorted
     * case-insensitively.
     *
     * @return Label[]
     */
    public function findForUserTreeOrdered(UserInterface $user): array
    {
        return $this->treeOrder($this->findForUser($user));
    }

    /**
     * Visible labels of a user — the sidebar query.
     *
     * @return Label[]
     */
    public function findVisibleForUser(UserInterface $user): array
    {
        return $this->createQueryBuilder('label')
            ->addSelect('LOWER(label.name) AS HIDDEN sortName')
            ->where('label.usr = :usr')
            ->andWhere('label.isVisible = :isVisible')
            ->setParameter('usr', $user)
            ->setParameter('isVisible', true)
            ->orderBy('label.sortOrder', 'ASC')
            ->addOrderBy('sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Visible custom labels of a user in sidebar tree order.
     *
     * @return Label[]
     */
    public function findVisibleTreeForUser(UserInterface $user): array
    {
        return $this->treeOrder($this->findVisibleForUser($user));
    }

    /**
     * Resolve a sidebar path ("Work/Invoices") to the user's custom Label at
     * that path. Candidates are narrowed by leaf name in SQL, the full parent
     * chain is verified in PHP via Label::$fullName.
     *
     * Returns at most one label now that labels are user-scoped — previously
     * this returned one per account and callers had to fan out.
     */
    public function findOneByPathForUser(UserInterface $user, string $path): ?Label
    {
        $segments = array_values(array_filter(
            explode('/', $path),
            function (string $segment): bool {
                return '' !== trim($segment);
            },
        ));

        if (count($segments) === 0) {
            return null;
        }

        $leafName = end($segments);
        $fullName = implode('/', $segments);

        $candidates = $this->createQueryBuilder('label')
            ->where('label.usr = :usr')
            ->andWhere('label.isVisible = :isVisible')
            ->andWhere('label.role IS NULL')
            ->andWhere('LOWER(label.name) = :name')
            ->setParameter('usr', $user)
            ->setParameter('isVisible', true)
            ->setParameter('name', mb_strtolower($leafName))
            ->getQuery()
            ->getResult();

        foreach ($candidates as $candidate) {
            if (mb_strtolower((string) $candidate->fullName) === mb_strtolower($fullName)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The label a Gmail label id maps to on an account, via its binding.
     */
    public function findOneByGmailLabelId(string $gmailLabelId, Account $account): ?Label
    {
        return $this->createQueryBuilder('label')
            ->innerJoin('label.bindings', 'binding')
            ->where('binding.account = :account')
            ->andWhere('binding.gmailLabelId = :gmailLabelId')
            ->setParameter('account', $account)
            ->setParameter('gmailLabelId', $gmailLabelId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByGraphFolderId(string $graphFolderId, Account $account): ?Label
    {
        return $this->createQueryBuilder('label')
            ->innerJoin('label.bindings', 'binding')
            ->where('binding.account = :account')
            ->andWhere('binding.graphFolderId = :graphFolderId')
            ->setParameter('account', $account)
            ->setParameter('graphFolderId', $graphFolderId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Labels bound to an account, i.e. materialized there at least once.
     *
     * @return list<Label>
     */
    public function findBoundToAccount(Account $account): array
    {
        return $this->createQueryBuilder('label')
            ->addSelect('LOWER(label.name) AS HIDDEN sortName')
            ->innerJoin('label.bindings', 'binding')
            ->where('binding.account = :account')
            ->setParameter('account', $account)
            ->orderBy('label.sortOrder', 'ASC')
            ->addOrderBy('sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByFullNameForUser(string $fullName, UserInterface $user): ?Label
    {
        return $this->findOneByPathForUser($user, $fullName);
    }

    /**
     * System labels first by sortOrder, then the custom tree depth-first with
     * each level sorted case-insensitively.
     *
     * @param Label[] $labels
     *
     * @return Label[]
     */
    private function treeOrder(array $labels): array
    {
        $system         = [];
        $customByParent = [];
        $known          = [];

        foreach ($labels as $label) {
            $known[(int) $label->id] = true;
        }

        foreach ($labels as $label) {
            if (true === $label->isSystem) {
                $system[] = $label;
                continue;
            }

            $parent = $label->parent;

            // A child whose parent was filtered out (hidden, say) would
            // otherwise vanish — hang it off the root instead.
            if (null !== $parent && false === isset($known[(int) $parent->id])) {
                $parent = null;
            }

            $parentId = null !== $parent ? (int) $parent->id : 0;

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
}
