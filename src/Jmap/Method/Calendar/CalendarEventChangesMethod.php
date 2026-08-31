<?php

declare(strict_types=1);

namespace App\Jmap\Method\Calendar;

use App\Domain\Exception\CalendarStateTokenException;
use App\Jmap\Account\CalendarAccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Service\Calendar\Change\CalendarChangeReader;

/**
 * "CalendarEvent/changes". The method that could not exist while calendars had
 * no change log.
 *
 * It could not, because there was no log: events are written from the sync
 * engine, from extraction, from the web editor and from CalendarEvent/set, and
 * a token that only one of them moved would have been a number a client could
 * not trust. calendar_change_log closed that — it is written by a Doctrine
 * listener, so a change is recorded because Doctrine saw it, not because a
 * caller remembered to say so.
 *
 * Scoped to the user, not the account. CalendarAccountResolver has already
 * proved the accountId is the one that serves calendars, and past that point
 * the objects belong to the user; a delta filtered by account would answer for
 * a set the ids do not come from.
 *
 * Every refusal is "cannotCalculateChanges", which is JMAP's way of saying
 * "start over" and is what a token this server cannot place deserves — whether
 * it is malformed, ahead of the log, or older than what pruning kept. The
 * client re-runs its query and is correct again; there is no partial answer
 * worth inventing.
 */
final class CalendarEventChangesMethod implements JmapMethod
{
    public function __construct(
        private readonly CalendarAccountResolver $accountResolver,
        private readonly CalendarChangeReader $changes,
    ) {
    }

    public function name(): string
    {
        return 'CalendarEvent/changes';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);

        $sinceState = $arguments['sinceState'] ?? null;

        if (false === is_string($sinceState)) {
            throw new MethodException('invalidArguments', '"sinceState" is required.');
        }

        $maxChanges = $arguments['maxChanges'] ?? null;

        if (null !== $maxChanges && false === is_int($maxChanges)) {
            throw new MethodException('invalidArguments', '"maxChanges" must be a number.');
        }

        try {
            $delta = $this->changes->sinceForUser((int) $context->user->id, $sinceState, $maxChanges);
        } catch (CalendarStateTokenException $e) {
            throw new MethodException('cannotCalculateChanges', $e->getMessage());
        }

        return [
            'accountId'      => (string) $account->id,
            'oldState'       => $delta->oldState,
            'newState'       => $delta->newState,
            'hasMoreChanges' => $delta->hasMoreChanges,
            'created'        => $delta->createdIds(),
            'updated'        => $delta->updatedIds(),
            'destroyed'      => $delta->destroyedIds(),
        ];
    }
}
