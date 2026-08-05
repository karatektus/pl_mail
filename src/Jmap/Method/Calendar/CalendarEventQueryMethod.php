<?php

declare(strict_types=1);

namespace App\Jmap\Method\Calendar;

use App\Jmap\Account\CalendarAccountResolver;
use App\Jmap\Calendar\CalendarState;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\Query\CalendarEventQueryRunner;

/**
 * "CalendarEvent/query": the ids of the events with an occurrence inside a
 * window, in the order those occurrences start, windowed by position/limit.
 * Clients pair it with a CalendarEvent/get on "#ids" in the same request,
 * exactly as they do for mail.
 *
 * The filter is `inCalendar`, `after` and `before`, and the last two are not
 * optional — see CalendarEventQueryRunner, which owns the read and the reason.
 *
 * Sorting is fixed. There is one useful order for a calendar and it is the one
 * the index already returns; anything else would be a sort in PHP over a window
 * the client has not seen the whole of, which is how a "sorted" list ends up
 * sorted only within a page. A `sort` argument is refused with unsupportedSort
 * rather than ignored.
 */
final class CalendarEventQueryMethod implements JmapMethod
{
    private const int MAX_LIMIT = 500;

    public function __construct(
        private readonly CalendarAccountResolver $accountResolver,
        private readonly CalendarEventQueryRunner $runner,
    ) {
    }

    public function name(): string
    {
        return 'CalendarEvent/query';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);

        $filter = $arguments['filter'] ?? null;

        if (null !== $filter && false === is_array($filter)) {
            throw new MethodException('invalidArguments', '"filter" must be an object or null.');
        }

        if (null !== ($arguments['sort'] ?? null)) {
            throw new MethodException('unsupportedSort', 'CalendarEvent/query always sorts by the first occurrence in the window.');
        }

        if (null !== ($arguments['anchor'] ?? null)) {
            throw new MethodException('unsupportedFilter', 'Anchor-based paging is not supported; use "position".');
        }

        $position = $arguments['position'] ?? 0;

        if (false === is_int($position) || $position < 0) {
            throw new MethodException('invalidArguments', '"position" must be a non-negative integer.');
        }

        $limit = $arguments['limit'] ?? null;

        if (null !== $limit && (false === is_int($limit) || $limit < 0)) {
            throw new MethodException('invalidArguments', '"limit" must be a non-negative integer or null.');
        }

        if (null === $limit || $limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }

        $result = $this->runner->run($context->user, $filter, $position, $limit);

        return [
            'accountId' => (string) $account->id,
            'queryState' => CalendarState::FIXED,
            // No CalendarEvent/queryChanges, and no /changes for it to diff
            // against — CalendarState explains why there is no state to hold a
            // delta from. Clients re-run the query, which is spec-legal and is
            // what Email/query already asks for.
            'canCalculateChanges' => false,
            'position' => $result->position,
            'ids' => $result->ids,
            'total' => $result->total,
            'limit' => $limit,
        ];
    }
}
