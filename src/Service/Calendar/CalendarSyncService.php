<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\DTO\Calendar\CalendarChangeSet;
use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Calendar\RemoteCalendar;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Interface\CalendarSyncDriverInterface;
use App\Entity\Calendar\Calendar;
use App\Repository\Calendar\CalendarEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Bringing one calendar and its remote back into agreement.
 *
 * The engine is three classes rather than one because they answer to different
 * questions and only this one is about *sequence*. CalendarPusher knows how a
 * local row becomes a remote write; CalendarPuller knows what one remote change
 * means against one local row; this owns the order they happen in, what a dead
 * sync token costs, and what the calendar records afterwards. Folding them
 * together would put the conflict rules, the paging recovery and the
 * per-event mapping in one class of four hundred lines whose test would have to
 * set up a remote to assert anything about a rule.
 *
 * ── The conflict rules ────────────────────────────────────────────────────
 *
 * Two-way sync is only worth having if "changes sync back" is trustworthy, and
 * trust here is entirely a matter of what happens when both sides moved. These
 * are the rules, in the order they apply. They are written down because the
 * next person to touch this will otherwise reconstruct them from the code and
 * get the third one wrong.
 *
 *   **1. Push before pull, always.** Every locally pending row goes out first.
 *   The pull that follows therefore asks "did the remote change too?" of a
 *   remote that has already been told, so the common case — the user edited
 *   here and nobody else touched it — resolves to no conflict at all. The
 *   reverse order makes every local edit collide with its own echo.
 *
 *   **2. A matching etag is not a change.** If the etag the remote reports
 *   equals the one stored, nothing is written: no row, no occurrences, no
 *   timestamp. This is what stops the pull immediately after a push from
 *   re-applying the remote's copy of the edit over anything the user typed in
 *   between.
 *
 *   **3. A changed etag means the remote wins.** Not last-write-wins. There is
 *   no clock the two sides share — a provider's modified timestamp is the
 *   provider's idea of now, ours is a container's, and comparing them is
 *   comparing two guesses. The remote is picked because it is the one copy
 *   other people can also see: losing an edit the user made on their phone is
 *   recoverable by making it again, while diverging from what an organiser and
 *   four attendees are looking at is not.
 *
 *   **4. A row that changed on both sides loses its local change, loudly.**
 *   Rule 1 means this only happens when the push for that row failed — the
 *   remote refused it, or the connection died between the push and the pull. It
 *   is still resolved remote-wins, and the discarded JSCalendar object is
 *   logged in full at warning level *before* it is overwritten. That log line
 *   is the rule: silently discarding a user's edit with no trace is the one
 *   outcome nobody can debug afterwards, and it is the difference between a
 *   sync people trust and one they stop putting things into.
 *
 *   **5. A read-only calendar is never pushed to.** Asserted rather than
 *   assumed, in CalendarPusher, which throws if it is ever called for one.
 *   Pending rows on such a calendar are left alone and reported once per run:
 *   the user's edit stays local, which is the only honest outcome for an edit
 *   to something we are not allowed to change.
 *
 *   **6. A dead token costs a full read, once.** requiresFullResync clears
 *   Calendar::$syncToken and re-pulls from scratch. Exactly once — a driver
 *   that answers requiresFullResync to a null token has a bug, and looping on
 *   it would hammer a provider forever, so the second one is a permanent
 *   failure with a name that says whose bug it is.
 *
 *   **7. A full read is authoritative about deletions.** With no token there
 *   are no tombstones, so a removal that happened while the token was dead is
 *   knowable only by absence — and local rows carrying a remote id the full
 *   read did not list are removed. See CalendarPuller.
 *
 * ── Failure ───────────────────────────────────────────────────────────────
 *
 * The outcome is recorded on the calendar either way: lastSyncedAt on success,
 * lastSyncError on failure, in the shape Integration uses so a connection that
 * has quietly stopped working says so wherever it is listed. The recording
 * itself is flushed separately from the events, because the interesting case is
 * the one where the sync threw — Doctrine has usually closed the manager by
 * then, and an error nobody could write down is an error nobody sees.
 *
 * Does flush, unlike the rest of Service/Calendar, and deliberately: this is
 * the top of a Messenger handler's unit of work rather than a step inside
 * somebody else's, and the token, the events and the bookkeeping have to land
 * together or the next run re-reads a window it already applied.
 */
final readonly class CalendarSyncService
{
    /**
     * How many times a dead sync token may be recovered from in one run. One:
     * see rule 6.
     */
    private const int MAX_RESYNCS = 1;

    public function __construct(
        private CalendarSyncDriverRegistry $drivers,
        private CalendarPusher             $pusher,
        private CalendarPuller             $puller,
        private CalendarEventRepository    $events,
        private CalendarNotifier           $notifier,
        private EntityManagerInterface     $em,
        private LoggerInterface            $logger,
    ) {
    }

    /**
     * Everything the source can see, for the subscribe screen.
     *
     * Here rather than on the controller so the one caller that matters — the
     * settings page — and any later one (an onboarding step, a command) ask the
     * same question of the same registry.
     *
     * @return list<RemoteCalendar>
     *
     * @throws CalendarSyncException
     */
    public function discover(CalendarSource $source): array
    {
        return $this->drivers->for($source)->discover($source);
    }

    /**
     * @return int how many events this run created, changed or removed
     *
     * @throws CalendarSyncException
     */
    public function sync(Calendar $calendar): int
    {
        $driver = $this->drivers->forCalendar($calendar);

        try {
            $touched = $this->run($calendar, $driver);
        } catch (\Throwable $e) {
            $this->recordFailure($calendar, $e);

            throw $e;
        }

        $calendar->recordSyncSuccess();

        $this->em->flush();

        if (0 < $touched) {
            // After the flush, so a page told to look again sees the events
            // rather than the calendar it already had.
            $user = $calendar->usr;

            if (null !== $user) {
                $this->notifier->publishCalendarChanged($user);
            }
        }

        // Unconditional, where the one above is not, and the difference is the
        // audience. "Something moved" is worth nothing to a page with nothing to
        // redraw, so it is sent only when events actually changed; "this run
        // finished and it worked" is the answer the health card has been waiting
        // for since the user pressed the button, and a repaired calendar that
        // happened to pull zero events is exactly the case where it is most
        // wanted — nothing changed BECAUSE the calendar is fine again.
        $this->notifier->publishCalendarSyncFinished($calendar, true);

        return $touched;
    }

    private function run(Calendar $calendar, CalendarSyncDriverInterface $driver): int
    {
        $touched = 0;

        if (false === $calendar->isReadOnly) {
            $touched += $this->pusher->push($calendar, $driver);
        } else {
            $this->reportUnpushableEdits($calendar);
        }

        $token   = $calendar->syncToken;
        $resyncs = 0;

        while (true) {
            $changes = $this->pullOnce($driver, $calendar, $token);

            if (false === $changes->requiresFullResync) {
                return $touched + $this->puller->apply($calendar, $changes, null === $token);
            }

            if (self::MAX_RESYNCS <= $resyncs) {
                throw new CalendarSyncPermanentException(
                    'The calendar service keeps asking for a full resync and never accepts the result.',
                );
            }

            ++$resyncs;

            // Cleared on the entity as well as in the local variable: the
            // second pull is a full read, and if it throws, the next run must
            // also start from scratch rather than presenting the token the
            // remote has already rejected.
            $calendar->syncToken = null;
            $token               = null;

            $this->logger->info('CalendarSync: sync token expired, reading the calendar from scratch', [
                'calendarId' => $calendar->id,
            ]);
        }
    }

    /**
     * A driver may report a dead token either way — see
     * CalendarSyncDriverInterface::pull(). Normalised to the flag here so the
     * loop above has one thing to look at.
     */
    private function pullOnce(
        CalendarSyncDriverInterface $driver,
        Calendar                    $calendar,
        ?string                     $token,
    ): CalendarChangeSet {
        try {
            return $driver->pull($calendar, $token);
        } catch (CalendarResyncRequiredException) {
            return CalendarChangeSet::resyncRequired();
        }
    }

    /**
     * Says once per run that a read-only calendar is holding edits it cannot
     * send.
     *
     * Nothing is discarded and nothing is retried. The alternative — clearing
     * the pending state — would make the edit vanish on the next pull with no
     * record that it was ever made, and the alternative to *that* — pushing
     * anyway — is what isReadOnly exists to prevent.
     */
    private function reportUnpushableEdits(Calendar $calendar): void
    {
        // Asked of the repository rather than counted over $calendar->events:
        // that collection hydrates every event on the calendar, and a year of
        // a busy calendar is thousands of rows loaded to answer a question one
        // indexed count already answers.
        $pending = $this->events->findPendingSync($calendar);

        if ([] === $pending) {
            return;
        }

        $this->logger->warning('CalendarSync: a read-only calendar is holding local edits that cannot be sent', [
            'calendarId' => $calendar->id,
            'pending'    => count($pending),
        ]);
    }

    /**
     * The failure, written down where a user will see it.
     *
     * Without this, lastSyncError is lost exactly when it matters: the settings
     * page shows a calendar that has simply stopped filling in, with nothing on
     * screen to explain it.
     *
     * The manager may already be closed — a flush that failed is one of the
     * ways to get here — and there is no writing anything through a closed one.
     * That case is logged rather than papered over with a second manager: the
     * envelope is about to fail anyway, and a log line naming the calendar is
     * enough to find it.
     *
     * This flush also commits whatever the failed run had already done, and
     * that is wanted rather than tolerated. An event the pusher got out really
     * did go out, and recording it as Clean is the truth; the sync token is
     * written only after a whole window has applied, so anything half-applied
     * on the pull side is simply read again on the next run.
     */
    private function recordFailure(Calendar $calendar, \Throwable $e): void
    {
        // recordSyncFailure() also answers "is this worth saying out loud",
        // and leaves that answer on the calendar as $syncFailureWasNews for
        // SyncCalendarHandler to read. It is not returned up the stack because
        // this is called from a catch block whose job is to rethrow.
        $calendar->recordSyncFailure($e->getMessage());

        if (false === $this->em->isOpen()) {
            $this->logger->error('CalendarSync: sync failed and the error could not be recorded', [
                'calendarId' => $calendar->id,
                'error'      => $e->getMessage(),
            ]);

            // Announced even here. The row could not be written, so a page
            // loading again would learn nothing new — which makes the live
            // message the ONLY way the person who pressed the repair finds out
            // it did not work, rather than watching "started" forever.
            $this->notifier->publishCalendarSyncFinished($calendar, false);

            return;
        }

        try {
            $this->em->flush();
        } catch (\Throwable $inner) {
            $this->logger->error('CalendarSync: could not record the sync failure', [
                'calendarId' => $calendar->id,
                'error'      => $inner->getMessage(),
            ]);
        }

        // After the flush, so the card and a reload of the page agree about
        // what went wrong. Published whether or not that flush worked: the run
        // failed either way, and that is the fact the waiting card needs.
        $this->notifier->publishCalendarSyncFinished($calendar, false);
    }
}
