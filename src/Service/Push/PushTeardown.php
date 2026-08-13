<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Service\Calendar\Push\CalendarPushRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hand back the provider-side registrations before the local rows that describe
 * them are destroyed.
 *
 * ── The bug this closes ──────────────────────────────────────────────────────
 * A push registration lives in two places: a row here, and a channel or
 * subscription the provider is holding on our behalf. Deleting only our half
 * does not stop the provider — Google goes on POSTing to /google/calendar/push
 * for a channel it still has, Microsoft goes on POSTing for a subscription it
 * still has — and every one of those arrives at an endpoint that can no longer
 * find what it refers to. On a live install that produced a steady trickle of
 * `GoogleCalendarPush: notification for an unknown channel` and `GraphNotif-
 * ication: unknown subscription` warnings that nothing could stop, because the
 * only thing that would have stopped them was a revocation call that was never
 * made and could no longer be made: the tokens needed to make it went with the
 * rows.
 *
 * Account deletion already did this properly. Everything else that destroys the
 * same state — a reset, a calendar being deleted or unticked, a connection
 * disconnected — did not, and this is the piece they were each missing.
 *
 * ── Best-effort, and never a veto ────────────────────────────────────────────
 * Every method here swallows what it throws, and that is a decision rather than
 * laziness. The tokens may already be dead; the provider may 404 a channel that
 * has expired anyway; the network may be down. None of those is a reason to
 * refuse the deletion the user asked for — a reset that will not run because
 * Google is unreachable is a far worse failure than a channel left to lapse on
 * its own, which is what an un-revoked registration does within a week
 * regardless.
 *
 * So the ordering is: revoke first, log what did not work, then delete either
 * way. Revoking first is the part that matters — after the truncate there is no
 * token left to revoke with, which is exactly how the install that prompted
 * this ended up with live channels it could no longer call off.
 */
final readonly class PushTeardown
{
    public function __construct(
        private PushSubscriptionRegistry $accountRegistry,
        private CalendarPushRegistry     $calendarRegistry,
        private LoggerInterface          $logger,
    ) {
    }

    /**
     * Revoke mail push for each account, as far as each one gets.
     *
     * @param iterable<Account> $accounts
     *
     * @return int how many were revoked without complaint
     */
    public function forAccounts(iterable $accounts): int
    {
        $revoked = 0;

        foreach ($accounts as $account) {
            $manager = $this->accountRegistry->resolve($account);

            if (null === $manager) {
                // An IMAP account has no push to hand back. Not a failure.
                continue;
            }

            try {
                $manager->unsubscribe($account);
                ++$revoked;
            } catch (Throwable $e) {
                $this->logger->warning('PushTeardown: could not revoke mail push, continuing', [
                    'accountId' => $account->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $revoked;
    }

    /**
     * Revoke calendar push for each calendar, as far as each one gets.
     *
     * @param iterable<Calendar> $calendars
     *
     * @return int how many were revoked without complaint
     */
    public function forCalendars(iterable $calendars): int
    {
        $revoked = 0;

        foreach ($calendars as $calendar) {
            $manager = $this->calendarRegistry->resolve($calendar);

            if (null === $manager) {
                // A CalDAV mirror or a hand-made local calendar. Nothing is
                // registered anywhere, so there is nothing to hand back.
                continue;
            }

            try {
                $manager->unsubscribe($calendar);
                ++$revoked;
            } catch (Throwable $e) {
                $this->logger->warning('PushTeardown: could not revoke calendar push, continuing', [
                    'calendarId' => $calendar->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $revoked;
    }

    /**
     * The single-calendar case, for the deletion paths that remove one row.
     *
     * A named method rather than callers wrapping one calendar in an array,
     * because those callers sit in the middle of a delete and the thing worth
     * making obvious at the call site is that revocation happens BEFORE the
     * remove() below it.
     */
    public function forCalendar(Calendar $calendar): void
    {
        $this->forCalendars([$calendar]);
    }
}
