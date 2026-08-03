<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarRepository;

/**
 * Which calendar an extracted event belongs on.
 *
 * "The calendar the email came from, configurable" — the account's own
 * calendar by default, which CalendarProvisioner creates alongside the account
 * so the answer always exists, and an override in Account::$settings for the
 * user who wants everything in one place.
 *
 * The override is validated against the user rather than trusted: a setting is
 * a string in a jsonb bag, and a calendar id that has since been deleted, or
 * that belongs to somebody else, must fall back rather than throw or leak.
 */
final readonly class ExtractedEventCalendarResolver
{
    public function __construct(
        private CalendarRepository  $calendars,
        private CalendarProvisioner $provisioner,
    ) {
    }

    public function resolve(Account $account): ?Calendar
    {
        $user = $account->usr;

        if (false === $user instanceof User) {
            return null;
        }

        $configured = $account->getSetting(Account::SETTING_CALENDAR_TARGET);

        if (true === is_int($configured) || (is_string($configured) && '' !== $configured)) {
            $calendar = $this->calendars->findOneForUser($user, (int) $configured);

            if (null !== $calendar) {
                return $calendar;
            }
        }

        // Provisioned with the account, but resolve rather than assume: an
        // account added before calendars existed has not been backfilled until
        // someone runs the task, and an event arriving first should still land
        // somewhere.
        return $this->calendars->findForAccount($account)
            ?? $this->provisioner->forAccount($account)
            ?? $this->provisioner->defaultFor($user);
    }
}
