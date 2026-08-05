<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The recurring jobs that keep a deployment healthy.
 *
 * Consumed by `messenger:consume scheduler_default` — see the scheduler
 * service in compose. Nothing runs these otherwise: without that worker the
 * commands below simply never fire, which is the state this project was in
 * before, with logs and orphaned blobs growing without bound.
 *
 * Stateful, so a worker that was down over a scheduled run catches up when it
 * comes back rather than silently skipping the day. Only the last missed run
 * is replayed — these are all idempotent sweeps, so running yesterday's
 * backlog five times over would be pure waste.
 *
 * Times are spread across the small hours rather than stacked on midnight:
 * they share a single worker, and a long prune should not hold up a sync.
 */
#[AsSchedule]
final class MaintenanceSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(
                // Neither Gmail push nor Graph subscriptions guarantee
                // delivery, and IDLE connections drop. Polling is the backstop
                // that notices what the push paths missed.
                RecurringMessage::cron('*/15 * * * *', new RunCommandMessage('app:mail:sync')),

                // Gmail watches last 7 days, Graph subscriptions ~3. Daily
                // renewal with the command's own thresholds leaves ample
                // headroom; --repair also re-registers accounts whose push has
                // gone degraded.
                RecurringMessage::cron('0 4 * * *', new RunCommandMessage('app:push:renew --repair')),

                // Snooze is only a snooze if something brings the thread
                // back. Every minute, because a minute is the unit people
                // pick a wake time in, and the sweep costs one indexed query
                // when nothing is due — which is almost always.
                RecurringMessage::cron('* * * * *', new RunCommandMessage('app:mail:wake-snoozed')),

                // Polling is the mechanism for CalDAV and the backstop for the
                // other two. Two of the three providers DO offer push and this
                // comment used to say none did: Google Calendar has watch
                // channels on events (a plain webhook, not the Pub/Sub path
                // Gmail uses) and Graph has subscriptions on a calendar's
                // events, which is the same mechanism GraphSubscriptionManager
                // already runs for mail. Both need a publicly reachable HTTPS
                // callback, which a self-hosted install cannot be assumed to
                // have, so neither can replace this sweep — they only make it
                // arrive late rather than first.
                //
                // Offset off the quarter hour so it does not stack on the mail
                // sweep above; they share one worker, and a full calendar read
                // should not hold up mail.
                RecurringMessage::cron('7-59/15 * * * *', new RunCommandMessage('app:calendar:sync --stale')),

                // What makes the two providers above actually push. Hourly
                // rather than daily like app:push:renew, and not because
                // anything expires that fast — a Google channel lasts a week
                // and a Graph calendar subscription just under three days.
                // Subscribing to a calendar now tries to open a channel there
                // and then, so this is the retry rather than the only way in.
                // The two are not redundant: registration fails for deployment
                // reasons (no public HTTPS address yet, a Cloud project whose
                // domain verification is pending) that have nothing to do with
                // the click that connected the calendar, and a subscribe that
                // failed for one of those must not leave the calendar polling
                // forever. Hourly means such an install starts pushing within
                // the hour of being fixed; daily means tomorrow. It costs
                // nothing when there is nothing to do — an install with no
                // public address stops before its first HTTP request, and a
                // live channel is a column read.
                RecurringMessage::cron('20 * * * *', new RunCommandMessage('app:calendar:push')),

                // An alert is only an alert if something fires it. Every
                // minute, for the same reason app:mail:wake-snoozed is: a
                // minute is the unit people set a reminder in, and the interval
                // is the bound on how late one can be — at five minutes a "five
                // minutes before" alert could arrive after the meeting started.
                // Cheap when nothing is due: the candidate query is bounded on
                // starts_at and asks for a jsonb key most events do not have.
                RecurringMessage::cron('* * * * *', new RunCommandMessage('app:calendar:alerts')),

                // The sweep two docblocks already claimed was running. An
                // event's occurrences are drawn when it is saved and the window
                // never moved afterwards, so a weekly standup created today ran
                // out of dates in two years and took its reminders with it —
                // silently, because the event still exists and still says it
                // repeats. Nightly is ample for a window measured in years, and
                // re-drawing is idempotent, so a missed night costs nothing.
                RecurringMessage::cron('50 3 * * *', new RunCommandMessage('app:calendar:materialise')),

                // Log entries and dead heartbeats.
                RecurringMessage::cron('30 4 * * *', new RunCommandMessage('app:monitoring:prune')),

                // Expired JMAP uploads and files orphaned by deleted rows.
                // Weekly: it walks three directory trees, and a week of
                // orphans is a rounding error on disk.
                RecurringMessage::cron('0 5 * * 0', new RunCommandMessage('app:prune:blobs')),
            );
    }
}
