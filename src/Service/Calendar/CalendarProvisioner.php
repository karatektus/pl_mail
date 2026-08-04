<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarRepository;
use App\Service\User\UserTimezoneResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Makes sure a user always has somewhere to put an event.
 *
 * Two calendars are provisioned rather than left to the user: one personal
 * default, and one per mail account. The per-account one is what makes "the
 * calendar the email came from" a thing that exists — an extracted event has a
 * home the moment its account does, with nobody having to have set it up first.
 *
 * Idempotent throughout, because it is called from three places that can each
 * happen twice: account creation, the backfill task, and any page that finds a
 * user has no default. Every method find-or-creates.
 *
 * Does not flush. It joins the caller's unit of work, so AccountCreator's
 * create-and-probe stays one transaction.
 */
final readonly class CalendarProvisioner
{
    public function __construct(
        private CalendarRepository     $calendars,
        private EntityManagerInterface $em,
        private UserTimezoneResolver   $timezones,
    ) {
    }

    /**
     * The user's own calendar. Exactly one, and the fallback target for
     * anything that does not name a calendar.
     */
    public function defaultFor(User $user): Calendar
    {
        return $this->ensureDefault($user)[0];
    }

    /** @return array{Calendar, bool} the calendar, and whether it was created */
    private function ensureDefault(User $user): array
    {
        $existing = $this->calendars->findDefaultForUser($user);

        if (null !== $existing) {
            return [$existing, false];
        }

        $calendar             = new Calendar();
        $calendar->usr        = $user;
        $calendar->name       = 'Personal';
        $calendar->role       = CalendarRole::Default;
        $calendar->isDefault  = true;
        $calendar->color      = Calendar::COLORS[0];
        // The user's zone, not the process's: PHP's default is pinned to UTC
        // in this container, so seeding from it stamped every calendar ever
        // provisioned UTC regardless of who owned it — and a calendar's zone is
        // what an event with no zone of its own is read in.
        $calendar->timeZone   = $this->timezones->nameFor($user);
        $calendar->sortOrder  = 0;

        $this->em->persist($calendar);

        return [$calendar, true];
    }

    /**
     * The calendar mail from this account lands on.
     *
     * Named after the account's address rather than something generic, because
     * a user with four accounts is about to have four of these and "Calendar"
     * four times helps nobody.
     */
    public function forAccount(Account $account): ?Calendar
    {
        return $this->ensureAccount($account)[0];
    }

    /** @return array{Calendar|null, bool} */
    private function ensureAccount(Account $account): array
    {
        $user = $account->usr;

        if (false === $user instanceof User) {
            return [null, false];
        }

        $existing = $this->calendars->findForAccount($account);

        if (null !== $existing) {
            return [$existing, false];
        }

        $siblings = $this->calendars->findForUser($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->account   = $account;
        $calendar->name      = (string) $account->username;
        $calendar->role      = CalendarRole::Account;
        // Walks the palette so a second account is not the same blue as the
        // first — see Calendar::COLORS.
        $calendar->color     = Calendar::COLORS[count($siblings) % count(Calendar::COLORS)];
        // See ensureDefault() — same reason.
        $calendar->timeZone  = $this->timezones->nameFor($user);
        $calendar->sortOrder = count($siblings);

        $this->em->persist($calendar);

        return [$calendar, true];
    }

    /**
     * Everything a user should have. Safe to call repeatedly — the backfill
     * task does exactly that over every existing user.
     *
     * Returns how many were actually created, which is the only number worth
     * printing at the end of a re-runnable task: "processed 400 users" says
     * nothing about whether it did anything.
     *
     * @param iterable<Account> $accounts
     */
    public function provision(User $user, iterable $accounts): int
    {
        [, $createdDefault] = $this->ensureDefault($user);

        $created = true === $createdDefault ? 1 : 0;

        foreach ($accounts as $account) {
            [, $createdAccount] = $this->ensureAccount($account);

            if (true === $createdAccount) {
                $created++;
            }
        }

        return $created;
    }
}
