<?php

declare(strict_types=1);

namespace App\Service\Calendar\Subscription;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Calendar\RemoteCalendar;
use App\Domain\DTO\Calendar\SubscriptionChange;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Exception\CalendarSyncException;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\RegisterCalendarPushMessage;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Repository\Calendar\CalendarEventRepository;
use App\Repository\Calendar\CalendarRepository;
use App\Service\Calendar\CalendarProvisioner;
use App\Service\Push\PushTeardown;
use App\Service\User\UserTimezoneResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Turning the subscribe screen's tick boxes into calendars, and back.
 *
 * The set of ticked ids is the whole instruction: what is ticked and not
 * mirrored gets a Calendar row, what is mirrored and not ticked stops being
 * mirrored, and everything else is left alone. Expressed as a target state
 * rather than as add/remove actions because that is what the form submits — a
 * checkbox list posts what is on, never what was turned off — and reconstructing
 * the actions from two lists in the controller is how one of them ends up
 * missing.
 *
 * **Nothing the browser posted describes a calendar.** Only the ids are read
 * from the request; the name, colour, zone and read-only flag come from a fresh
 * discover() on the way through. A form that carried them would let a crafted
 * POST mint a calendar claiming to be writable when the remote refuses writes,
 * which the engine trusts absolutely — CalendarSyncDriverInterface promises a
 * driver is never asked to push to a read-only calendar.
 *
 * There is a second way in, subscribeAll(), for the source that offers exactly
 * one calendar and can never offer a second — an ICS address, where the feed is
 * the calendar. It shares everything below this line, and the reason it exists
 * rather than being spelled as `apply($source, [the one id])` is written on the
 * method: apply() discovers, so composing that id list would mean reading the
 * whole feed twice to learn something already in hand.
 *
 * ── What a fresh subscription queues, and why it only queues ──────────────
 *
 * Two messages per new calendar and no work of either kind inline: a first sync,
 * so the calendar is not empty for fifteen minutes, and a push registration, so
 * it is not polled for an hour before changes start arriving on their own.
 *
 * **Neither can fail this method, and that is the reason both are messages.** A
 * first sync is a full calendar read; a registration is a call to Google or
 * Microsoft that fails for reasons belonging to the deployment rather than to
 * the click — no public HTTPS address yet, a Cloud project whose domain
 * verification is still pending. Doing either here would make "mirror this
 * calendar" wait on a provider and, worse, report a pending domain verification
 * as an error on a checkbox. Both are best-effort and both have a sweep behind
 * them (`app:calendar:sync --stale` every fifteen minutes, `app:calendar:push`
 * every hour), so the state this method leaves behind is complete whether or not
 * either message ever succeeds.
 *
 * ── What unsubscribing does to the events already pulled ──────────────────
 *
 * The calendar row goes, and with it every event **the remote gave us** — those
 * are copies, the provider still holds the originals, and re-ticking the
 * calendar pulls them back within the minute. Leaving them behind under a
 * calendar that no longer syncs is the orphan case: a list that never changes
 * again, quietly diverging from a calendar the user still edits elsewhere.
 *
 * Everything the remote never gave us is **moved to the user's default
 * calendar** first. That population is not hypothetical: a user may point
 * Account::SETTING_CALENDAR_TARGET at a mirrored calendar, and from then on
 * every booking extracted from that account's mail lands there with no remote
 * id — the only copy in existence. Deleting those with the subscription would
 * destroy a dinner reservation because somebody unticked a calendar, and moving
 * them is the only answer that is neither that nor an orphan.
 *
 * One case is a genuine loss and is not silent: an event carrying a remote id
 * *and* an unpushed local edit (SyncState::PendingUpdate) is deleted with the
 * rest, so the edit never reaches the provider. Pushing it first would mean
 * unsubscribing performing a network write that can fail, which is a worse
 * shape for a button labelled "stop mirroring this". The window is one sweep —
 * fifteen minutes — and it is logged.
 */
final readonly class CalendarSubscriber
{
    public function __construct(
        private CalendarDiscoverer      $discoverer,
        private CalendarRepository      $calendars,
        private CalendarEventRepository $events,
        private CalendarProvisioner     $provisioner,
        private UserTimezoneResolver    $timezones,
        private EntityManagerInterface  $em,
        private MessageBusInterface     $bus,
        private LoggerInterface         $logger,
        private PushTeardown            $pushTeardown,
    ) {
    }

    /**
     * Bring the mirrored set into line with what was ticked.
     *
     * @param list<string> $wantedRemoteIds
     *
     * @throws CalendarSyncException when the remote cannot be listed — nothing
     *                               is written in that case, because a partial
     *                               listing would read as "these calendars are
     *                               gone" and unsubscribe from them
     */
    public function apply(CalendarSource $source, array $wantedRemoteIds): SubscriptionChange
    {
        $user = $source->user();

        if (false === $user instanceof User) {
            return new SubscriptionChange();
        }

        $wanted       = array_flip($wantedRemoteIds);
        $subscribed   = 0;
        $unsubscribed = 0;
        $kept         = 0;
        $fresh        = [];

        foreach ($this->discoverer->discover($source) as $subscription) {
            $isWanted = true === array_key_exists($subscription->remote->remoteId, $wanted);

            if (true === $isWanted && false === $subscription->isSubscribed()) {
                $fresh[] = $this->subscribe($source, $user, $subscription->remote);
                ++$subscribed;

                continue;
            }

            // The default is where a new event lands, so unsubscribing it would
            // make the next thing the user creates vanish on save — the same
            // rule CalendarSettingsController::delete() enforces, and the
            // subscribe screen renders its box ticked and disabled for it. This
            // is the server half, not a second opinion.
            if (false === $isWanted && null !== $subscription->local && false === $subscription->local->isDefault) {
                $kept += $this->unsubscribe($subscription->local, $user);
                ++$unsubscribed;
            }
        }

        $this->em->flush();

        $this->queue($fresh);

        return new SubscriptionChange($subscribed, $unsubscribed, $kept);
    }

    /**
     * Mirror everything the source offers, without asking which.
     *
     * For a source that offers exactly one calendar and could never offer a
     * second — a subscribed ICS address, where the feed *is* the calendar.
     * Putting a tick-box list of one in front of somebody who has just typed
     * that address is a screen that asks no question, and the two clicks it
     * costs are two chances to close the dialog before the calendar exists.
     *
     * Deliberately not apply($source, [every id it just discovered]), which is
     * the same instruction and would be the obvious way to write it: apply()
     * discovers, so building the id list would mean discovering twice. That is
     * a second network read of the whole feed for an answer already in hand —
     * the same double-work unsubscribeAll() avoids at the other end, for a
     * different reason.
     *
     * **It only ever adds.** Nothing is unsubscribed here whatever the source
     * stops offering, because "subscribe to this" is not an instruction about
     * the calendars a user already has. apply() remains the only path that can
     * take one away, and it is reached from the screen where the user can see
     * what they are unticking.
     *
     * @throws CalendarSyncException when the source cannot be listed — nothing
     *                               is written in that case
     */
    public function subscribeAll(CalendarSource $source): SubscriptionChange
    {
        $user = $source->user();

        if (false === $user instanceof User) {
            return new SubscriptionChange();
        }

        $fresh = [];

        foreach ($this->discoverer->discover($source) as $subscription) {
            if (true === $subscription->isSubscribed()) {
                continue;
            }

            $fresh[] = $this->subscribe($source, $user, $subscription->remote);
        }

        $this->em->flush();

        $this->queue($fresh);

        return new SubscriptionChange(count($fresh));
    }

    /**
     * Stop mirroring everything from one connection, without asking it
     * anything.
     *
     * Deliberately not apply($source, []) — which is the same instruction and
     * would be the obvious way to write it. That path discovers first, and the
     * connection a person most wants to disconnect is precisely the one that
     * can no longer be listed: a server that moved, a password revoked at the
     * other end. Routing "disconnect" through discovery would make a broken
     * connection permanently undeletable.
     *
     * The events are treated exactly as an untick treats them, and that is the
     * point of sharing unsubscribe(): "what happens to my events" must not have
     * two answers depending on which button was pressed.
     */
    public function unsubscribeAll(Integration $integration): SubscriptionChange
    {
        $user = $integration->usr;
        $kept = 0;
        $gone = 0;

        foreach ($this->calendars->findMirroredForIntegration($integration) as $calendar) {
            $kept += $this->unsubscribe($calendar, $user);
            ++$gone;
        }

        $this->em->flush();

        return new SubscriptionChange(0, $gone, $kept);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The two messages every fresh subscription owes, dispatched together.
     *
     * After the caller's flush, so every calendar has an id — a message carries
     * ids only — and after the whole set rather than per row, so a subscription
     * that fails to persist queues nothing.
     *
     * Both are best-effort and neither can fail the subscribe; see the class
     * docblock for why that is the reason both are messages. A source with no
     * push mechanism at all — an ICS address is a file, there is nothing to
     * register with — is not a special case here: CalendarPushRegistry answers
     * null for a calendar no manager claims and the handler skips quietly,
     * which is what keeps this loop free of a "does this provider have push?"
     * question that would then exist in two places.
     *
     * @param list<Calendar> $calendars
     */
    private function queue(array $calendars): void
    {
        foreach ($calendars as $calendar) {
            if (null !== $calendar->id) {
                $this->bus->dispatch(new SyncCalendarMessage($calendar->id));
                $this->bus->dispatch(new RegisterCalendarPushMessage($calendar->id));
            }
        }
    }

    private function subscribe(CalendarSource $source, User $user, RemoteCalendar $remote): Calendar
    {
        $siblings = $this->calendars->findForUser($user);

        $calendar              = new Calendar();
        $calendar->usr         = $user;
        $calendar->account     = $source->account;
        $calendar->integration = $source->integration;
        $calendar->role        = CalendarRole::Remote;
        $calendar->remoteId    = $remote->remoteId;
        // The remote's answer, never the browser's — see the class docblock.
        // This is what stops the engine ever pushing to a holiday feed.
        $calendar->isReadOnly  = $remote->isReadOnly;
        $calendar->name        = $this->nameOf($remote);
        $calendar->color       = $this->colorOf($remote, count($siblings));
        $calendar->timeZone    = $remote->timeZone ?? $this->timezones->nameFor($user);
        $calendar->sortOrder   = count($siblings);

        $this->em->persist($calendar);

        return $calendar;
    }

    /**
     * Stop mirroring one calendar, rescuing what only exists here.
     *
     * @return int how many events were moved rather than deleted
     */
    private function unsubscribe(Calendar $calendar, User $user): int
    {
        $rescued = $this->rescueLocalEvents($calendar, $user);

        $this->warnAboutUnsentEdits($calendar);

        // Hand the registration back before the row carrying its channel id
        // goes. Nothing called this on any calendar-removal path before, so
        // every untick and every disconnect left Google or Microsoft pushing at
        // an endpoint that could no longer identify what was arriving, until
        // the registration expired days later. Best-effort by contract — see
        // PushTeardown — so a provider that cannot be reached cannot block the
        // unsubscribe the user asked for.
        $this->pushTeardown->forCalendar($calendar);

        // The events the remote gave us go with the row, by the ON DELETE
        // CASCADE on calendar_event.calendar_id. Removing them one by one first
        // would be the same deletion in several hundred statements.
        $this->em->remove($calendar);

        return $rescued;
    }

    /**
     * Move everything the remote never gave us onto the user's default
     * calendar, before the row it sits on is removed.
     *
     * Flushed here rather than left to the caller's flush: the UPDATE has to
     * reach the database before the DELETE that cascades, and relying on
     * Doctrine's insert-update-delete ordering to guarantee that would be
     * relying on it for data nobody can get back.
     */
    private function rescueLocalEvents(Calendar $calendar, User $user): int
    {
        $orphans = $this->events->findRowsTheRemoteNeverGave($calendar);

        if ([] === $orphans) {
            return 0;
        }

        // Never this calendar: apply() refuses to unsubscribe the default one,
        // which is the only way defaultFor() could answer with it.
        $home  = $this->provisioner->defaultFor($user);
        $moved = 0;

        foreach ($orphans as $event) {
            // uniq_calendar_event_calendar_uid: the same UID already in the
            // destination is the same meeting — an invitation filed twice — so
            // the copy already there wins and this one goes with the calendar.
            // Moving it anyway is a constraint violation, which would turn
            // unsubscribing into a 500.
            if (null !== $this->events->findOneByUid($home, $event->uid)) {
                continue;
            }

            $event->calendar = $home;
            ++$moved;
        }

        $this->em->flush();

        return $moved;
    }

    /**
     * A local edit that will never leave, recorded before it is deleted.
     *
     * Nothing else holds it: the row is about to go, and the remote still has
     * the copy the user edited away from. See the class docblock for why
     * unsubscribing does not push first.
     */
    private function warnAboutUnsentEdits(Calendar $calendar): void
    {
        $pending = $this->events->findPendingSync($calendar);

        if ([] === $pending) {
            return;
        }

        $this->logger->warning('Calendar: unsubscribing discarded unsent local edits', [
            'calendar' => $calendar->id,
            'events'   => count($pending),
            'titles'   => array_map(static fn (CalendarEvent $event): ?string => $event->title, $pending),
        ]);
    }

    /** The column is 120; a provider's name is not bounded by anything of ours. */
    private function nameOf(RemoteCalendar $remote): string
    {
        $name = trim($remote->name);

        return '' === $name ? 'Calendar' : mb_substr($name, 0, 120);
    }

    /**
     * The remote's colour when it gave one that is genuinely a colour, and the
     * next palette entry otherwise.
     *
     * The pattern check is not defensive tidiness: this value is rendered into
     * a `style="background-color: …"` attribute on the settings list and in the
     * calendar grid. Twig's HTML escaping stops it breaking out of the
     * attribute, and stops nothing at all happening *inside* it — a remote that
     * answers with a name containing CSS would be styling somebody else's page.
     */
    private function colorOf(RemoteCalendar $remote, int $siblingCount): string
    {
        if (null !== $remote->color && 1 === preg_match('/^#[0-9a-fA-F]{6}$/', $remote->color)) {
            return strtolower($remote->color);
        }

        return Calendar::COLORS[$siblingCount % count(Calendar::COLORS)];
    }
}
