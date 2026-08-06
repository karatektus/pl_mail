<?php

declare(strict_types=1);

namespace App\Jmap\Method\Calendar;

use App\Jmap\Account\CalendarAccountResolver;
use App\Jmap\Calendar\CalendarState;
use App\Jmap\Calendar\OccurrenceId;
use App\Jmap\Mapper\CalendarEventMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Calendar\CalendarEventOccurrenceRepository;
use App\Repository\Calendar\CalendarEventRepository;

/**
 * "CalendarEvent/get".
 *
 * ids is REQUIRED, like Email/get and for the same reason: a user's events are
 * unbounded, so they are reached through CalendarEvent/query and a null ids is
 * requestTooLarge rather than "return everything".
 *
 * An id names a series (see CalendarEventMapper), so what comes back is one
 * JSCalendar Event object per id — rule and overrides included — rather than one
 * object per dated instance.
 *
 * **Unless the id names one instance**, which is the one shape a client cannot
 * have invented: `CalendarEvent/query` mints those, and only when it was asked
 * to expand recurrences (OccurrenceId). Resolving them here is not an extra —
 * it is what makes the expanded query usable at all, because a client pairs
 * `/query` with a `/get` on `#ids` in the same request, and ids the getter
 * refused would make the whole feature a list of strings nothing accepts. The
 * object that comes back is the instance with its override applied and the
 * series' recurrence properties nulled, as the JMAP-calendars draft requires.
 *
 * Each id is resolved through CalendarEventRepository::findOneForUser(), which
 * scopes on the owner. That makes somebody else's event indistinguishable from
 * one that does not exist, which is the answer that leaks nothing; it also means
 * one query per id, and the cap below is lower than mail's 500 because of it.
 * The batch method this wants — findByIdsForUser() — does not exist in
 * CalendarEventRepository, and that repository is not this directory's to
 * extend.
 */
final class CalendarEventGetMethod implements JmapMethod
{
    /**
     * Ceiling on ids per call, matching maxEventsInGet in the Session's
     * calendars capability.
     */
    private const int MAX_OBJECTS = 100;

    public function __construct(
        private readonly CalendarAccountResolver $accountResolver,
        private readonly CalendarEventRepository $events,
        private readonly CalendarEventOccurrenceRepository $occurrences,
        private readonly CalendarEventMapper $mapper,
    ) {
    }

    public function name(): string
    {
        return 'CalendarEvent/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);

        $requestedIds = $arguments['ids'] ?? null;

        if (null === $requestedIds) {
            throw new MethodException('requestTooLarge', '"ids" is required for CalendarEvent/get; use CalendarEvent/query to select them.');
        }

        if (false === is_array($requestedIds)) {
            throw new MethodException('invalidArguments', '"ids" must be an array.');
        }

        if (count($requestedIds) > self::MAX_OBJECTS) {
            throw new MethodException('requestTooLarge', sprintf('At most %d ids per CalendarEvent/get.', self::MAX_OBJECTS));
        }

        $properties = $arguments['properties'] ?? null;

        if (null !== $properties && false === is_array($properties)) {
            throw new MethodException('invalidArguments', '"properties" must be an array or null.');
        }

        $list = [];
        $notFound = [];

        foreach ($requestedIds as $id) {
            // "#creationId" refers to an event created earlier in this same
            // request; an unknown one stays as-is and falls out as notFound.
            $id = $context->resolveId((string) $id) ?? (string) $id;

            $instance = OccurrenceId::parse($id);

            if (null !== $instance) {
                $object = $this->instance($context, $instance, $properties);

                if (null === $object) {
                    $notFound[] = $id;

                    continue;
                }

                $list[] = $object;

                continue;
            }

            $event = false === ctype_digit($id)
                ? null
                : $this->events->findOneForUser($context->user, (int) $id);

            if (null === $event) {
                $notFound[] = $id;

                continue;
            }

            $list[] = $this->mapper->toJmap($event, $properties);
        }

        return [
            'accountId' => (string) $account->id,
            'state' => CalendarState::FIXED,
            'list' => $list,
            'notFound' => $notFound,
        ];
    }

    /**
     * One dated instance, or null when nothing answers to that id.
     *
     * Two lookups, both scoped: the series through the same owner-scoped finder
     * every other id uses, then the occurrence by its recurrence id within that
     * series. Looked up by recurrence id and not by start, for the reason
     * CalendarEventOccurrenceRepository gives: once an instance has been moved
     * those are different columns, and the id carries the first.
     *
     * Null covers three cases a client cannot tell apart and should not:
     * somebody else's event, a series that no longer recurs, and an instance
     * whose row is gone because the rule stopped drawing it — an
     * `{"excluded": true}` override removes the row outright, so an id from a
     * query run before the cancellation is notFound afterwards, which is the
     * correct answer to "is this instance still there?".
     *
     * @param list<string>|null $properties
     *
     * @return array<string,mixed>|null
     */
    private function instance(JmapContext $context, OccurrenceId $instance, ?array $properties): ?array
    {
        $event = $this->events->findOneForUser($context->user, $instance->eventId);

        if (null === $event) {
            return null;
        }

        $occurrence = $this->occurrences->findOneByRecurrence($event, $instance->recurrenceId);

        if (null === $occurrence) {
            return null;
        }

        return $this->mapper->toJmapInstance($occurrence, $properties);
    }
}
