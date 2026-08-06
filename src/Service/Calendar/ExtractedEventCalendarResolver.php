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
 * **The user's default calendar**, with an override in Account::$settings for
 * anyone who wants a particular mailbox's bookings kept apart.
 *
 * It used to be the account's own calendar — the one CalendarProvisioner
 * creates alongside every account — on the reasoning that an event should land
 * where the mail that carried it lives. That reasoning is right about mail and
 * wrong about a calendar. A person has one diary; the fact that a flight
 * confirmation happened to arrive at the work address rather than the private
 * one is a property of the message, not of the flight, and filing by it splits
 * one day across as many calendars as the user has mailboxes. Worse, it is
 * silent: the per-account calendar is visible and coloured like any other, so
 * the event *is* on screen and simply not where the owner would look for it,
 * and every "where did my appointment go?" has the same invisible cause.
 *
 * The per-account calendars still exist and are still provisioned — they are
 * what SETTING_CALENDAR_TARGET points at when somebody does want the split, and
 * what a mirrored provider calendar attaches to. They are no longer the default
 * answer.
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

        // Provisioned with the user, but go through the provisioner rather than
        // the repository: a user created before calendars existed has not been
        // backfilled until someone runs the task, and an event arriving first
        // should still land somewhere. defaultFor() find-or-creates, so this
        // never answers null for a real user.
        return $this->provisioner->defaultFor($user);
    }
}
