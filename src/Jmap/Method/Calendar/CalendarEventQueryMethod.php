<?php

declare(strict_types=1);

namespace App\Jmap\Method\Calendar;

use App\Jmap\Account\CalendarAccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Service\Calendar\Change\CalendarChangeReader;
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
 *
 * **`expandRecurrences` switches the unit from the series to the occurrence**,
 * as draft-ietf-jmap-calendars defines it, and it exists because the collapsed
 * answer does not say which days a series lands on. A client that has to find
 * out was making one query per day of the visible month — up to 31 round trips
 * to place one weekly meeting — because a one-day window is the only question
 * this method could answer about membership. Expanded, the month is one query,
 * `position`/`limit`/`total` count occurrences, and each id names one instance
 * (OccurrenceId). Everything else about the method is unchanged, and with the
 * argument absent or false the response is byte-for-byte what it was.
 */
final class CalendarEventQueryMethod implements JmapMethod
{
    private const int MAX_LIMIT = 500;

    public function __construct(
        private readonly CalendarAccountResolver $accountResolver,
        private readonly CalendarEventQueryRunner $runner,
        private readonly CalendarChangeReader $changes,
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

        $expandRecurrences = $arguments['expandRecurrences'] ?? false;

        if (false === is_bool($expandRecurrences)) {
            throw new MethodException('invalidArguments', '"expandRecurrences" must be a boolean.');
        }

        // The draft pairs `timeZone` with expansion, to have the server convert
        // instance times for a client that would rather not. This server does
        // not, and says so rather than answering as though it had: every time
        // here is UTC, and a client told nothing would draw a whole month of
        // occurrences in the wrong zone with no way to notice. Refused only
        // alongside expansion, because it is only the expanded answer that
        // carries times a zone could apply to.
        if (true === $expandRecurrences && null !== ($arguments['timeZone'] ?? null)) {
            throw new MethodException('invalidArguments', '"timeZone" is not supported: "after" and "before" are UTCDates and an expanded occurrence is named by its UTC recurrence id.');
        }

        $result = $this->runner->run($context->user, $filter, $position, $limit, $expandRecurrences);

        return [
            'accountId' => (string) $account->id,
            'queryState' => $this->changes->stateForUser((int) $context->user->id),
            // A real token now — calendar_change_log moves it — but still no
            // CalendarEvent/queryChanges, which is a different promise: a query
            // delta has to account for rows entering and leaving the *filter*,
            // and a log of what changed cannot say whether a change moved an
            // event across the window a filter describes. Clients re-run the
            // query, which is spec-legal and what Email/query already asks for.
            'canCalculateChanges' => false,
            'position' => $result->position,
            'ids' => $result->ids,
            'total' => $result->total,
            'limit' => $limit,
        ];
    }
}
