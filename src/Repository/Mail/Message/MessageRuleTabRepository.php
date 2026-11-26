<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The built-in tab a filter filed mail into (#762), from the message table's
 * side: letting go of it when the filter is deleted or changed, and loading
 * that mail again to sort it.
 */
class MessageRuleTabRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Clears the built-in tab this filter chose, on every message it chose it
     * for — except the ones in $keep, the tab the filter still files into, so
     * that renaming a filter or adding a condition does not undo its work.
     *
     * One statement, and the ids it touched come back: after it, nothing in
     * the database says which messages these were, and they still need
     * sorting again (RecategoriseMessagesHandler).
     *
     * @return list<int>
     */
    public function releaseFromRule(int $ruleId, ?MessageCategory $keep): array
    {
        $sql = 'UPDATE message SET rule_builtin_category = NULL, rule_builtin_rule_id = NULL
                WHERE rule_builtin_rule_id = :rule';
        $params = ['rule' => $ruleId];

        if (null !== $keep) {
            $sql .= ' AND rule_builtin_category IS DISTINCT FROM :keep';
            $params['keep'] = $keep->value;
        }

        return array_map(
            intval(...),
            $this->getEntityManager()->getConnection()->fetchFirstColumn($sql . ' RETURNING id', $params),
        );
    }

    /**
     * These messages, with their threads, for sorting again.
     *
     * @param list<int> $ids
     *
     * @return list<Message>
     */
    public function findForResort(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Message> */
        return $this->createQueryBuilder('m')
            ->addSelect('t')
            ->leftJoin('m.thread', 't')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();
    }
}
