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

    /**
     * Rewrite execution order from a list of ids.
     *
     * Takes the whole order rather than "move rule X to position N": the client
     * already knows the arrangement it is showing, and sending it entire makes
     * the write idempotent — a retried or duplicated request lands on the same
     * result instead of shifting things twice.
     *
     * Ids that are not the user's are ignored rather than rejected, and rules
     * the caller omitted keep their relative order at the end, so a stale page
     * cannot silently drop a rule out of the sequence.
     *
     * @param list<int> $orderedIds
     */
    public function applyOrder(UserInterface $user, array $orderedIds): void
    {
        $rules = $this->findForUserOrdered($user);

        /** @var array<int, MailRule> $byId */
        $byId = [];

        foreach ($rules as $rule) {
            $byId[(int) $rule->id] = $rule;
        }

        $position = 0;
        $placed = [];

        foreach ($orderedIds as $id) {
            $rule = $byId[$id] ?? null;

            if (null === $rule) {
                continue;
            }

            $rule->sortOrder = $position++;
            $placed[$id] = true;
        }

        foreach ($rules as $rule) {
            if (false === isset($placed[(int) $rule->id])) {
                $rule->sortOrder = $position++;
            }
        }
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
