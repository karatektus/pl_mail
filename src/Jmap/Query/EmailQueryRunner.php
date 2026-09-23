<?php

declare(strict_types=1);

namespace App\Jmap\Query;

use App\Jmap\Protocol\Exception\MethodException;
use App\Repository\Mail\MessageRepository;

/**
 * Runs a compiled Email/query: filter -> sort -> optional thread collapse ->
 * window, all of it in SQL. Returns ids only, which is all RFC 8621 §4.4 asks
 * for.
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
     * $calculateTotal false skips the COUNT, which RFC 8621 lets a client
     * decline. Anything else — including leaving it out — still counts,
     * because plMail has always answered `total` on Email/query and clients
     * were told they could rely on it.
     *
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
        bool $calculateTotal = true,
    ): EmailQueryResult {
        $compiled = null === $filter ? null : $this->filterCompiler->compile($filter);

        $ids = $this->messages->findIdsForQuery(
            $accountId,
            $compiled,
            $this->orderBy($sort),
            $collapseThreads,
            $position,
            $limit,
        );

        return new EmailQueryResult(
            $ids,
            true === $calculateTotal ? $this->messages->countForQuery($accountId, $compiled, $collapseThreads) : null,
            $position,
        );
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
