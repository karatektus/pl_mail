<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Infrastructure\Messaging\Message\RegisterCalendarPushMessage;
use App\Repository\Calendar\CalendarRepository;
use App\Service\Calendar\Push\CalendarPushRegistry;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Opens a push channel for one freshly mirrored calendar, off the request that
 * mirrored it.
 *
 * The body is the body of `app:calendar:push`'s loop with the reporting taken
 * out, and it is written out here rather than extracted into a service both
 * call. The shared thing already exists and both go through it — the manager
 * behind CalendarPushRegistry, which owns every decision that can differ by
 * provider. What is left is four guards over a registry and a column, and a
 * helper wrapping those would be a class whose only content is the order they
 * are asked in.
 *
 * ── Three deliberate differences from the sweep ────────────────────────────
 *
 * **No isConfigured() check.** The command asks it first, so an install with no
 * public address does no work at all rather than one refused registration per
 * calendar — the right trade over hundreds of rows every hour. Here there is one
 * calendar and one click, so the manager is allowed to answer instead, and the
 * warning it logs names the calendar and the missing address. That line is the
 * only place a self-hoster learns why the calendar they just ticked is polling,
 * and it costs one log entry per subscribe rather than one per hour.
 *
 * **needsRenewal() is still asked**, although a calendar this message was
 * dispatched for has no channel by construction. Messenger redelivers — a worker
 * killed between the registration and the ack runs the envelope again — and
 * without this that redelivery would open a second channel and stop the first,
 * for nothing.
 *
 * **Nothing is caught and nothing is logged here.** Both managers swallow every
 * provider failure and answer false, and both log what happened with the
 * calendar id in it, so a second line saying the same thing would only make the
 * first harder to find. What is left to throw is a database failing under the
 * flush inside subscribe(), and that is a genuine fault the transport should
 * retry — the user is long gone by then either way.
 */
#[AsMessageHandler]
final readonly class RegisterCalendarPushHandler
{
    public function __construct(
        private CalendarRepository   $calendars,
        private CalendarPushRegistry $registry,
    ) {
    }

    public function __invoke(RegisterCalendarPushMessage $message): void
    {
        $calendar = $this->calendars->find($message->calendarId);

        if (null === $calendar) {
            // Unsubscribed between the dispatch and the run. Normal: the two
            // happen on different processes and a user can untick faster than a
            // worker picks up.
            return;
        }

        if (false === $calendar->isSynced()) {
            // Still there, no longer bound to a remote. There is nothing to
            // watch, and asking would be asking about an id that is gone.
            return;
        }

        $manager = $this->registry->resolve($calendar);

        if (null === $manager) {
            // CalDAV, every local calendar, and — the case worth naming — a
            // calendar Google has already answered pushNotSupportedForRequested-
            // Resource for. GoogleCalendarPushManager::supports() reads that off
            // the settings bag, so a holiday or birthday feed is not offered
            // here any more than it is to the sweep, and this path cannot
            // re-ask a question that was answered permanently.
            return;
        }

        if (false === $manager->needsRenewal($calendar)) {
            return;
        }

        // The answer is deliberately dropped. False is "this calendar stays on
        // polling", which is a state, not a failure: the fifteen-minute sweep is
        // still syncing it and the hourly push sweep will try again the moment
        // whatever refused this stops refusing.
        $manager->renew($calendar);
    }
}
