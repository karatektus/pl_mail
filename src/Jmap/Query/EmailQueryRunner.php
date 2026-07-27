<?php

declare(strict_types=1);

namespace App\Jmap\Query;

use App\Jmap\Protocol\Exception\MethodException;
use Doctrine\DBAL\Connection;

/**
 * Runs a compiled Email/query: filter -> sort -> optional thread collapse ->
 * window. Returns ids only, which is all RFC 8621 §4.4 asks for.
 */
final class EmailQueryRunner
{
    /**
     * JMAP sort property -> SQL expression. Anything absent raises
     * unsupportedSort, per RFC 8621 §5.5.
     */
    private const array SORTABLE = [
        'receivedAt' => 'm.received_at',
        'sentAt' => 'm.sent_at',
        'size' => 'm.size',
        'subject' => 'm.subject',
        'from' => 'm.from_address',
        'to' => 'm.to_addresses::text',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly EmailFilterCompiler $filterCompiler,
    ) {
    }

    /**
     * @param array<string,mixed>|null $filter
     * @param list<mixed>|null         $sort
     */
    public function run(
        int $accountId,
        ?array $filter,
        ?array $sort,
        bool $collapseThreads,
        int $position,
        ?int $limit,
    ): EmailQueryResult {
        $parameters = ['accountId' => $accountId];
        $types = [];
        $where = 'm.account_id = :accountId';

        if (null !== $filter) {
            $compiled = $this->filterCompiler->compile($filter);
            $where .= ' AND '.$compiled->sql;
            $parameters = array_merge($parameters, $compiled->parameters);
            $types = $compiled->parameterTypes();
        }

        $sql = sprintf(
            'SELECT m.id, m.thread_id FROM message m WHERE %s ORDER BY %s',
            $where,
            $this->orderBy($sort),
        );

        $rows = $this->connection->executeQuery($sql, $parameters, $types)->fetchAllAssociative();

        $ids = $this->collect($rows, $collapseThreads);
        $total = count($ids);

        return new EmailQueryResult(
            $this->window($ids, $position, $limit),
            $total,
            $position,
        );
    }

    /**
     * Thread collapse happens here rather than in SQL (DISTINCT ON) because the
     * spec's "position" and "total" are defined over the collapsed list. Only
     * two integer columns per matching row are read, so the full result set is
     * cheap to hold even for a large mailbox.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<string>
     */
    private function collect(array $rows, bool $collapseThreads): array
    {
        $ids = [];
        $seenThreads = [];

        foreach ($rows as $row) {
            if (true === $collapseThreads) {
                $threadId = $row['thread_id'];

                // Messages with no thread can never collapse into one another.
                if (null !== $threadId) {
                    if (true === array_key_exists((int) $threadId, $seenThreads)) {
                        continue;
                    }

                    $seenThreads[(int) $threadId] = true;
                }
            }

            $ids[] = (string) $row['id'];
        }

        return $ids;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function window(array $ids, int $position, ?int $limit): array
    {
        if (null === $limit) {
            return array_values(array_slice($ids, $position));
        }

        return array_values(array_slice($ids, $position, $limit));
    }

    /**
     * @param list<mixed>|null $sort
     */
    private function orderBy(?array $sort): string
    {
        if (null === $sort || 0 === count($sort)) {
            // The spec has no default sort; newest-first is what every mail
            // client wants and what plMail's own list view uses.
            return 'm.received_at DESC NULLS LAST, m.id DESC';
        }

        $parts = [];

        foreach ($sort as $comparator) {
            if (false === is_array($comparator)) {
                throw new MethodException('invalidArguments', 'Each "sort" entry must be a Comparator object.');
            }

            $property = $comparator['property'] ?? null;

            if (false === is_string($property)) {
                throw new MethodException('invalidArguments', 'A Comparator requires a string "property".');
            }

            $column = self::SORTABLE[$property] ?? null;

            if (null === $column) {
                throw new MethodException('unsupportedSort', sprintf('Cannot sort on "%s".', $property));
            }

            $ascending = $comparator['isAscending'] ?? true;

            if (false === is_bool($ascending)) {
                throw new MethodException('invalidArguments', '"isAscending" must be a boolean.');
            }

            $parts[] = sprintf('%s %s NULLS LAST', $column, true === $ascending ? 'ASC' : 'DESC');
        }

        // A total order keeps paging stable when the sort keys tie.
        $parts[] = 'm.id DESC';

        return implode(', ', $parts);
    }
}
