<?php

declare(strict_types=1);

namespace App\Service\Layout;

use App\Domain\DTO\Layout\Workspace;
use App\Domain\DTO\Layout\Workspaces;
use App\Domain\Enum\Layout\PaneKind;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEvent\CalendarEventRepository;
use App\Repository\Mail\MessageThread\MessageThreadBadgeRepository;
use App\Repository\Task\TaskRepository;
use DateTimeImmutable;
use App\Repository\Mail\Account\AccountRepository;
use App\Service\Calendar\CalendarTimeResolver;
use Symfony\Component\Clock\ClockInterface;

/**
 * What is new in each workspace since the user last looked at it (#783): the
 * count on its tab, and whether something in it is about to happen.
 *
 * ── New, not unread ──────────────────────────────────────────────────────
 *
 * The count is what arrived since the workspace was last on screen, and
 * visiting it is what clears it — reading nothing. Unread is a different
 * question the sidebar already answers. What counts depends on what the
 * workspace shows: inbox mail if it has a mail list, new events if it has a
 * calendar, new tasks if it has tasks, each within its accounts. One mail can
 * count in two workspaces; visiting one clears that one.
 *
 * "Soon" is an event starting or a task falling due within the hour, in a
 * workspace with a calendar or tasks, that was not yet within the hour at the
 * last visit — news too, so a visit clears it like the count. The Happening
 * soon button's dot is the other half: everything within the hour, in every
 * account, seen or not (pulse()).
 *
 * ── Seen ─────────────────────────────────────────────────────────────────
 *
 * Stored per user (User::SETTING_WORKSPACES_SEEN), so a visit on the phone
 * clears the desktop's tab too. The workspace on screen is seen whenever a
 * page draws it and when it is left. A workspace with no time yet starts at
 * now: everything before the feature is not news.
 */
final readonly class WorkspaceNews
{
    /** How far ahead "soon" looks. */
    public const string SOON = '+1 hour';

    /**
     * A page drawn within this many seconds of the last write does not
     * write again: every list click would otherwise be a settings write.
     */
    private const int TOUCH_EVERY = 30;

    public function __construct(
        private WorkspaceScope               $scope,
        private MessageThreadBadgeRepository $threads,
        private CalendarEventRepository      $events,
        private TaskRepository               $tasks,
        private ClockInterface               $clock,
        private CalendarTimeResolver         $time,
        private AccountRepository            $accounts,
    ) {
    }

    /**
     * Every workspace but the one on screen, by id.
     *
     * @return array<string, array{count: int, soon: bool}>
     */
    public function forTabs(User $user, Workspaces $workspaces): array
    {
        $seen   = $this->seen($user);
        $now    = $this->clock->now();
        $active = $workspaces->active()->id;
        $out    = [];

        foreach ($workspaces->all as $workspace) {
            if ($workspace->id === $active) {
                continue;
            }

            $since = isset($seen[$workspace->id]) ? (new DateTimeImmutable())->setTimestamp($seen[$workspace->id]) : $now;

            $out[$workspace->id] = $this->of($user, $workspace, $since, $now, $since->modify(self::SOON));
        }

        return $out;
    }

    /**
     * Whether anything at all starts or falls due within the hour — the dot on
     * Happening soon — and the next moment the counts can change by the clock
     * alone: an event or a task coming within the hour, or a new day for
     * date-only tasks. The page asks again then (#783); everything else that
     * changes them comes with a Mercure nudge.
     *
     * Not narrowed to the workspace on screen: a user is one person, and the
     * dot is there to say they have somewhere to be. Two reads, one for the
     * calendars and one for the tasks, each answering both questions.
     *
     * @return array{soon: bool, next: DateTimeImmutable}
     */
    public function pulse(User $user): array
    {
        $now    = $this->clock->now();
        $until  = $now->modify(self::SOON);
        $zone   = $this->time->zoneFor($user);
        $events = $this->events->soonAndNext($user, $now, $until);
        $tasks  = $this->tasks->soonAndNext($user, $now, $until, $this->today($user, $now));

        $next = [new DateTimeImmutable('tomorrow', $zone)];

        foreach ([$events['next'], $tasks['next']] as $at) {
            if (null !== $at) {
                $next[] = $at->modify('-1 hour');
            }
        }

        return ['soon' => $events['soon'] + $tasks['soon'] > 0, 'next' => min($next)];
    }

    /**
     * Mark workspaces as looked at now. Writes the setting; the caller flushes.
     * Returns whether anything changed, so a caller can skip the flush.
     */
    public function touch(User $user, string ...$ids): bool
    {
        $seen    = $this->seen($user);
        $now     = $this->clock->now()->getTimestamp();
        $changed = false;

        foreach ($ids as $id) {
            if (($seen[$id] ?? 0) <= $now - self::TOUCH_EVERY) {
                $seen[$id] = $now;
                $changed   = true;
            }
        }

        if ($changed) {
            $user->setSetting(User::SETTING_WORKSPACES_SEEN, $seen);
        }

        return $changed;
    }

    /**
     * Forget workspaces that no longer exist, so the setting does not grow
     * with every workspace ever made.
     */
    public function prune(User $user, Workspaces $workspaces): void
    {
        $ids  = array_map(static fn (Workspace $workspace): string => $workspace->id, $workspaces->all);
        $seen = array_intersect_key($this->seen($user), array_flip($ids));

        if ($seen !== $this->seen($user)) {
            $user->setSetting(User::SETTING_WORKSPACES_SEEN, $seen);
        }
    }

    /**
     * @param DateTimeImmutable|null $soonAfter only what reached the window after this; null is all of it
     *
     * @return array{count: int, soon: bool}
     */
    private function of(User $user, Workspace $workspace, DateTimeImmutable $since, DateTimeImmutable $now, ?DateTimeImmutable $soonAfter): array
    {
        $accounts = $this->scope->of($user, $workspace);
        $until    = $now->modify(self::SOON);
        $count    = 0;
        $soon     = 0;

        if ($workspace->has(PaneKind::List) && $since < $now) {
            $count += $this->threads->countInboxArrivedSince($accounts ?? $this->accounts->findActiveIdsForUser($user), $since);
        }

        if ($workspace->has(PaneKind::Calendar)) {
            $events = $this->events->countNewsForWorkspace($user, $this->scope->calendarIds($user, $workspace), $since, $now, $until, $soonAfter);
            $count += $since < $now ? $events['added'] : 0;
            $soon  += $events['soon'];
        }

        if ($workspace->has(PaneKind::Tasks)) {
            // A task due on a day, with no time, is soon on that day — news to
            // a workspace last looked at before the day began.
            $today  = $this->today($user, $now);
            $tasks  = $this->tasks->countNewsForWorkspace($user, $this->scope->taskListIds($user, $workspace), $since, $now, $until, $soonAfter, $today, null === $soonAfter || $since < new DateTimeImmutable($today . ' 00:00', $this->time->zoneFor($user)));
            $count += $since < $now ? $tasks['added'] : 0;
            $soon  += $tasks['soon'];
        }

        return ['count' => $count, 'soon' => $soon > 0];
    }

    /** Today's date where the user is, which a date-only due date is in. */
    private function today(User $user, DateTimeImmutable $now): string
    {
        return $now->setTimezone($this->time->zoneFor($user))->format('Y-m-d');
    }

    /** @return array<string, int> */
    private function seen(User $user): array
    {
        $stored = $user->getSetting(User::SETTING_WORKSPACES_SEEN);

        return is_array($stored) ? array_filter($stored, 'is_int') : [];
    }
}
