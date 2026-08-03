<?php

declare(strict_types=1);

namespace App\Jmap\Query;

use App\Jmap\Protocol\Exception\MethodException;
use App\Repository\Mail\MessageRepository;

/**
 * Runs a compiled Email/query: filter -> sort -> optional thread collapse ->
 * window. Returns ids only, which is all RFC 8621 §4.4 asks for.
 *
 * What this owns is the JMAP half — which sort properties exist, what an
 * unsupported one costs the client, and where "position" and "total" are
 * measured. The read is MessageRepository::findIdsForQuery().
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
        private readonly MessageRepository $messages,
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
        $rows = $this->messages->findIdsForQuery(
            $accountId,
            null === $filter ? null : $this->filterCompiler->compile($filter),
            $this->orderBy($sort),
        );

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
