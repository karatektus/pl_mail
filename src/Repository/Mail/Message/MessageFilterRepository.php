<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Jmap\Query\CompiledFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Which messages a compiled filter matches, for rules applied to stored mail.
 */
class MessageFilterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Which of these messages a compiled filter matches.
     *
     * This is the single place a rule's conditions are ever evaluated. Letting
     * Postgres answer means there is exactly one implementation of what a
     * filter means — an in-memory twin was tried and deleted, because two
     * implementations can drift and the symptom is mail quietly filed in the
     * wrong place. It also makes `text` usable in rules: search_vector is
     * built by Postgres as the row is written, so full-text works here and
     * could never be reproduced faithfully in PHP.
     *
     * The messages must already be flushed — they are, since rules run after
     * the id-granting flush in every sync path.
     *
     * @param list<int> $messageIds
     *
     * @return list<int> ids that match, in the order given
     */
    public function matchingIds(array $messageIds, CompiledFilter $filter): array
    {
        if (0 === count($messageIds)) {
            return [];
        }

        $sql = sprintf(
            'SELECT m.id FROM message m WHERE m.id IN (:ruleMessageIds) AND (%s)',
            $filter->sql,
        );

        $parameters = $filter->parameters;
        $parameters['ruleMessageIds'] = $messageIds;

        $types = $filter->parameterTypes();
        $types['ruleMessageIds'] = ArrayParameterType::INTEGER;

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, $parameters, $types)
            ->fetchFirstColumn();

        return array_map('intval', $rows);
    }

    /**
     * How many of a user's messages a compiled filter matches.
     *
     * Powers the live "matches N messages" readout while a rule is being
     * written. Capped, because the only question being answered is "is this
     * filter roughly right" — an exact count over a large mailbox costs a full
     * scan to tell the author something they do not need.
     *
     * @return array{count: int, capped: bool}
     */
    public function countMatchingForUser(
        User $user,
        CompiledFilter $filter,
        int $cap = 500,
        ?Account $account = null,
    ): array {
        // Null account is the rule's own "every account", so the absence of
        // the clause is the scope rather than a missing one.
        $scope = null === $account ? '' : ' AND a.id = :ruleAccountId';

        $sql = sprintf(
            'SELECT COUNT(*) FROM (
                 SELECT 1 FROM message m
                 JOIN account a ON a.id = m.account_id
                 WHERE a.usr_id = :ruleUserId%s AND (%s)
                 LIMIT :ruleCap
             ) probe',
            $scope,
            $filter->sql,
        );

        $parameters = $filter->parameters;
        $parameters['ruleUserId'] = $user->id;
        $parameters['ruleCap'] = $cap + 1;

        if (null !== $account) {
            $parameters['ruleAccountId'] = $account->id;
        }

        $count = (int) $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, $parameters, $filter->parameterTypes())
            ->fetchOne();

        return [
            'count' => min($count, $cap),
            'capped' => $count > $cap,
        ];
    }

    /**
     * One page of a user's messages matching a filter, for applying a rule to
     * mail that arrived before it existed.
     *
     * Keyset pagination rather than a cap: applying a rule must reach every
     * matching message, and a mailbox can hold more of them than fits in
     * memory. Paging by id also survives the run's own writes — an OFFSET walk
     * would skip messages as the rows it already acted on stop matching.
     *
     * @return list<int> ascending, empty when the walk is finished
     */
    public function findIdsMatchingForUser(User $user, CompiledFilter $filter, int $afterId = 0, int $batchSize = 200): array
    {
        $sql = sprintf(
            'SELECT m.id FROM message m
             JOIN account a ON a.id = m.account_id
             WHERE a.usr_id = :ruleUserId AND m.id > :ruleAfterId AND (%s)
             ORDER BY m.id ASC
             LIMIT :ruleBatchSize',
            $filter->sql,
        );

        $parameters = $filter->parameters;
        $parameters['ruleUserId'] = $user->id;
        $parameters['ruleAfterId'] = $afterId;
        $parameters['ruleBatchSize'] = $batchSize;

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, $parameters, $filter->parameterTypes())
            ->fetchFirstColumn();

        return array_map('intval', $rows);
    }
}
