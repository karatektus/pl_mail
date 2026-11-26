<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\DTO\Calendar\CalendarSet;
use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use App\Repository\Calendar\Calendar\CalendarRepository;

/**
 * A user's calendar sets, kept in their settings bag.
 *
 * A set does not have a visibility of its own. Applying one writes
 * Calendar::$isVisible, the flag every view already reads, so the grid, the
 * agenda, the topbar's "happening soon" and the JMAP view all agree on what is
 * showing. The set that is "on" is whichever one matches what is visible, so a
 * calendar ticked by hand afterwards reads as no set rather than a set that no
 * longer describes the screen.
 *
 * The default calendar is always visible (CalendarSettingsController refuses
 * to hide it), so it counts as part of every set.
 */
final readonly class CalendarSets
{
    public const string SETTING_SETS = 'calendar.sets';

    /** The set last picked or saved, which "Update" writes back to. */
    public const string SETTING_LAST = 'calendar.last_set';

    public const int MAX_NAME_LENGTH = 60;

    public function __construct(
        private CalendarRepository $calendars,
    ) {
    }

    /** @return list<CalendarSet> */
    public function all(User $user): array
    {
        $rows = $user->getSetting(self::SETTING_SETS, []);

        if (false === is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(CalendarSet::fromSetting(...), $rows)));
    }

    public function find(User $user, string $id): ?CalendarSet
    {
        foreach ($this->all($user) as $set) {
            if ($set->id === $id) {
                return $set;
            }
        }

        return null;
    }

    /**
     * The set whose calendars are exactly the visible ones, if any. The set
     * last picked wins when two sets hold the same calendars.
     *
     * @param list<Calendar> $calendars every calendar the user owns
     */
    public function active(User $user, array $calendars): ?CalendarSet
    {
        $visible = $this->ids(array_filter($calendars, static fn (Calendar $c): bool => true === $c->isVisible));
        $last    = $this->last($user);

        foreach (null === $last ? $this->all($user) : [$last, ...$this->all($user)] as $set) {
            if ($this->effectiveIds($set, $calendars) === $visible) {
                return $set;
            }
        }

        return null;
    }

    /**
     * How many calendars applying the set would show.
     *
     * @param list<Calendar> $calendars every calendar the user owns
     */
    public function size(CalendarSet $set, array $calendars): int
    {
        return count($this->effectiveIds($set, $calendars));
    }

    public function last(User $user): ?CalendarSet
    {
        $id = $user->getSetting(self::SETTING_LAST);

        return true === is_string($id) ? $this->find($user, $id) : null;
    }

    /** Shows the set's calendars and hides every other one. */
    public function apply(User $user, CalendarSet $set): void
    {
        $members = array_flip($set->calendarIds);

        foreach ($this->calendars->findForUser($user) as $calendar) {
            $calendar->isVisible = true === $calendar->isDefault || true === isset($members[(int) $calendar->id]);
        }

        $user->setSetting(self::SETTING_LAST, $set->id);
    }

    /** A new set of the calendars visible now. */
    public function saveVisible(User $user, string $name): CalendarSet
    {
        $set = new CalendarSet(bin2hex(random_bytes(6)), $this->cleanName($name), $this->visibleIds($user));

        $this->write($user, [...$this->all($user), $set]);
        $user->setSetting(self::SETTING_LAST, $set->id);

        return $set;
    }

    /** Makes the set hold the calendars visible now. */
    public function updateToVisible(User $user, CalendarSet $set): void
    {
        $this->replace($user, new CalendarSet($set->id, $set->name, $this->visibleIds($user)));
        $user->setSetting(self::SETTING_LAST, $set->id);
    }

    public function rename(User $user, CalendarSet $set, string $name): void
    {
        $this->replace($user, new CalendarSet($set->id, $this->cleanName($name), $set->calendarIds));
    }

    public function delete(User $user, CalendarSet $set): void
    {
        $this->write($user, array_values(array_filter(
            $this->all($user),
            static fn (CalendarSet $each): bool => $each->id !== $set->id,
        )));
    }

    private function replace(User $user, CalendarSet $set): void
    {
        $this->write($user, array_map(
            static fn (CalendarSet $each): CalendarSet => $each->id === $set->id ? $set : $each,
            $this->all($user),
        ));
    }

    /** @param list<CalendarSet> $sets */
    private function write(User $user, array $sets): void
    {
        $user->setSetting(self::SETTING_SETS, array_map(
            static fn (CalendarSet $set): array => $set->toSetting(),
            $sets,
        ));
    }

    /** @return list<int> */
    private function visibleIds(User $user): array
    {
        return $this->ids($this->calendars->findVisibleForUser($user));
    }

    /**
     * What applying the set would show: its calendars that still exist, plus
     * the default.
     *
     * @param list<Calendar> $calendars
     *
     * @return list<int>
     */
    private function effectiveIds(CalendarSet $set, array $calendars): array
    {
        $members = array_flip($set->calendarIds);

        return $this->ids(array_filter(
            $calendars,
            static fn (Calendar $c): bool => true === $c->isDefault || true === isset($members[(int) $c->id]),
        ));
    }

    /**
     * @param array<Calendar> $calendars
     *
     * @return list<int>
     */
    private function ids(array $calendars): array
    {
        $ids = array_map(static fn (Calendar $c): int => (int) $c->id, array_values($calendars));
        sort($ids);

        return $ids;
    }

    private function cleanName(string $name): string
    {
        return mb_substr(trim($name), 0, self::MAX_NAME_LENGTH);
    }
}
