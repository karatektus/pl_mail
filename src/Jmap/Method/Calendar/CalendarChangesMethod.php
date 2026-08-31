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
 * "Calendar/changes": what happened to the calendars themselves.
 *
 * The last of the fixed states to go. When CalendarEvent/changes landed this
 * one was left behind on the argument that the gap was small — a user has a
 * handful of calendars and Calendar/get returns all of them by default, so
 * re-running it is cheap. That is true about cost and beside the point about
 * correctness: a state that never moves is a claim that nothing changed, and a
 * client is entitled to believe it. Renaming a calendar left the old name in
 * the sidebar until something unrelated forced a reload.
 *
 * Scoped to the user, like every other calendar method, after
 * CalendarAccountResolver has proved the accountId is the one that serves them.
 *
 * The delta reports calendar ids, not event ids — the rows behind it are the
 * ones with no event, and the collapse keys them by the calendar they are
 * about. An event moving between calendars is not a change here: both
 * collections still exist and neither was renamed.
 */
final class CalendarChangesMethod implements JmapMethod
{
    public function __construct(
        private readonly CalendarAccountResolver $accountResolver,
        private readonly CalendarChangeReader $changes,
    ) {
    }

    public function name(): string
    {
        return 'Calendar/changes';
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
            $delta = $this->changes->collectionsSinceForUser(
                (int) $context->user->id,
                $sinceState,
                $maxChanges,
            );
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
