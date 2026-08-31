<?php

declare(strict_types=1);

namespace App\Jmap\Method\Calendar;

use App\Jmap\Account\CalendarAccountResolver;
use App\Jmap\Mapper\CalendarMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Service\Calendar\Change\CalendarChangeReader;
use App\Repository\Calendar\CalendarRepository;

/**
 * "Calendar/get". ids = null returns every calendar the user owns, in sidebar
 * order; otherwise the named ones, with anything missing listed in notFound.
 *
 * Shaped like Mailbox/get, including the "null means all of them" default: a
 * user has a handful of calendars, not an unbounded mailbox, so there is
 * nothing here for a /query to page through and none is implemented.
 *
 * Scoped to the user rather than to the account, which is what a plMail
 * Calendar is — see CalendarAccountResolver for why one account nevertheless
 * serves them. Another user's calendar is not in the answer and is reported as
 * notFound, never as an error: "there is no calendar 41 for you" and "calendar
 * 41 is somebody else's" have to be the same sentence.
 */
final class CalendarGetMethod implements JmapMethod
{
    public function __construct(
        private readonly CalendarAccountResolver $accountResolver,
        private readonly CalendarRepository $calendars,
        private readonly CalendarMapper $mapper,
        private readonly CalendarChangeReader $changes,
    ) {
    }

    public function name(): string
    {
        return 'Calendar/get';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);

        $properties = $arguments['properties'] ?? null;

        if (null !== $properties && false === is_array($properties)) {
            throw new MethodException('invalidArguments', '"properties" must be an array or null.');
        }

        $requestedIds = $arguments['ids'] ?? null;
        $notFound = [];

        if (null === $requestedIds) {
            $calendars = $this->calendars->findForUser($context->user);
        } else {
            if (false === is_array($requestedIds)) {
                throw new MethodException('invalidArguments', '"ids" must be an array or null.');
            }

            $calendars = [];

            foreach ($requestedIds as $id) {
                $id = $context->resolveId((string) $id) ?? (string) $id;
                $calendar = false === ctype_digit($id)
                    ? null
                    : $this->calendars->findOneForUser($context->user, (int) $id);

                if (null === $calendar) {
                    $notFound[] = $id;

                    continue;
                }

                $calendars[] = $calendar;
            }
        }

        $list = [];

        foreach ($calendars as $calendar) {
            $list[] = $this->mapper->toJmap($calendar, $properties);
        }

        return [
            'accountId' => (string) $account->id,
            'state' => $this->changes->stateForUserCollections((int) $context->user->id),
            'list' => $list,
            'notFound' => $notFound,
        ];
    }
}
