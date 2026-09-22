<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * One read of a user's calendars, shared by everything that renders in the
 * same request.
 *
 * ── The duplication this removes ────────────────────────────────────────────
 * A MAIL list page read the calendar table three times, which is two more than
 * a page with no calendar on it ought to. The topbar is the reason all three
 * are there: UpcomingEventIndicator lights the calendar dot, HappeningSoonReader
 * fills the "happening soon" trigger beside it, and AccountHealthGlobal asks
 * AccountHealthInspector whether anything is broken. The first two issued the
 * IDENTICAL `usr_id = ? AND is_visible = ?` statement — same user, same
 * columns, same order, different service — and the third issued its superset.
 * Nothing was wrong with any of them; they simply could not see each other.
 *
 * ── Why the superset is the one that gets read ──────────────────────────────
 * visible() is all() filtered on isVisible rather than a query of its own, and
 * the two are equivalent by construction: CalendarRepository::findVisibleForUser()
 * is findForUser() plus `isVisible = true` and orders by the SAME pair
 * (sortOrder, id), so dropping rows from the ordered superset leaves exactly
 * the ordered subset. `true === $calendar->isVisible` rather than a loose test
 * because the column is a non-null bool and `= true` in SQL matches nothing
 * else either.
 *
 * Reading the superset costs the hidden calendars, which is a handful of rows
 * on the largest install anyone has — far less than the round trip it saves,
 * and nothing here hydrates events.
 *
 * ── Per request, and only for readers ───────────────────────────────────────
 * Resettable for the reason InviteReader is: this holds entities, and under a
 * worker runtime a cache that outlives its request hands out objects belonging
 * to a closed entity manager — and, worse here, to the previous USER.
 *
 * The write paths keep going through the repository directly and must continue
 * to: CalendarProvisioner and CalendarSubscriber read their siblings in order
 * to decide what to create next, and a memo answering from before their own
 * insert is how you get two calendars named the same. This exists for the
 * render path, where the question is asked several times and the answer cannot
 * change between the asking.
 */
final class UserCalendars implements ResetInterface
{
    /**
     * user identifier => that user's calendars, in sidebar order.
     *
     * Keyed rather than single-slot because a console command or a worker can
     * legitimately walk several users inside one "request", and the identifier
     * rather than the object so two loads of the same user share the answer.
     *
     * @var array<string, list<Calendar>>
     */
    private array $byUser = [];

    public function __construct(
        private readonly CalendarRepository $calendars,
    ) {
    }

    public function reset(): void
    {
        $this->byUser = [];
    }

    /**
     * Every calendar the user owns, hidden ones included, in sidebar order.
     *
     * @return list<Calendar>
     */
    public function all(User $user): array
    {
        return $this->byUser[$user->getUserIdentifier()] ??= $this->calendars->findForUser($user);
    }

    /**
     * The ones a view should actually draw — the same set, same order, minus
     * the hidden ones.
     *
     * @return list<Calendar>
     */
    public function visible(User $user): array
    {
        return array_values(array_filter(
            $this->all($user),
            static fn (Calendar $calendar): bool => true === $calendar->isVisible,
        ));
    }
}
