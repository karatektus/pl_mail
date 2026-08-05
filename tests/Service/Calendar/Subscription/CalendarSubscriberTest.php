<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Subscription;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\RegisterCalendarPushMessage;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Repository\Calendar\CalendarRepository;
use App\Service\Calendar\Subscription\CalendarDiscoverer;
use App\Service\Calendar\Subscription\CalendarSubscriber;
use App\Tests\Support\Calendar\ScriptedCalendarSyncDriver;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Unsubscribing is not a delete, and subscribing is not a form submission.
 *
 * Two claims, and both name a way this feature goes wrong quietly.
 *
 * A mirrored calendar can hold events that never came from the remote — an
 * extracted booking, when the user has pointed Account::SETTING_CALENDAR_TARGET
 * at it, or an event they made there and that has not been pushed yet. Deleting
 * the calendar with them in it destroys the only copy of a dinner reservation
 * because somebody unticked a checkbox, and nothing anywhere would say so. The
 * remote's own events go, because the remote still has them; everything else
 * moves to the user's default calendar.
 *
 * And the properties of a subscribed calendar come from a fresh discover(), not
 * from the request. isReadOnly in particular: the engine treats it as absolute
 * — CalendarSyncDriverInterface promises a driver is never asked to push to a
 * read-only calendar — so a value a browser could set is a value a browser
 * could use to make plMail write to a calendar the account may not write to.
 *
 * A third claim joined them with push, and it is asserted about a queue rather
 * than about a calendar. Subscribing asks for a push channel immediately, so the
 * feature's first hour does not look exactly like it not working — but the ask
 * is a dispatch and never a call. An unrouted Messenger message is handled in
 * the process that dispatched it, so "it landed on the maintenance transport" is
 * precisely the statement "no provider was contacted inside the subscribe", and
 * that is what makes a pending Google domain verification incapable of surfacing
 * as an error on a checkbox.
 *
 * Against a real container and a real database. Every collaborator here is
 * final, so none can be doubled, and the seam the engine was built with is the
 * driver interface: ScriptedCalendarSyncDriver is registered in the test
 * container and reads its answers off the connection, which is what lets a test
 * say "the remote offers these two calendars" without a network.
 */
final class CalendarSubscriberTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarSubscriber $subscriber;
    private CalendarDiscoverer $discoverer;
    private CalendarRepository $calendars;
    private User $user;
    private Integration $integration;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->subscriber = $container->get(CalendarSubscriber::class);
        $this->discoverer = $container->get(CalendarDiscoverer::class);
        $this->calendars  = $container->get(CalendarRepository::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── Subscribing ───────────────────────────────────────────────────────

    public function testTickingACalendarStartsMirroringIt(): void
    {
        $this->offer([
            ['remoteId' => 'work', 'name' => 'Work', 'color' => '#16a34a'],
        ]);

        $change = $this->subscriber->apply($this->source(), ['work']);

        self::assertSame(1, $change->subscribed);

        $calendar = $this->mirrored('work');

        self::assertNotNull($calendar, 'ticking a calendar has to produce one');
        self::assertSame('Work', $calendar->name);
        self::assertSame(CalendarRole::Remote, $calendar->role);
        self::assertSame($this->integration, $calendar->integration);
        self::assertSame('#16a34a', $calendar->color);
    }

    /**
     * The property the engine trusts absolutely. A calendar the account cannot
     * write to must arrive read-only from the remote's own answer, because
     * CalendarPusher asserts on this flag and never re-asks the provider.
     */
    public function testAReadOnlyRemoteBecomesAReadOnlyCalendar(): void
    {
        $this->offer([
            ['remoteId' => 'holidays', 'name' => 'Public holidays', 'readOnly' => true],
        ]);

        $this->subscriber->apply($this->source(), ['holidays']);

        $calendar = $this->mirrored('holidays');

        self::assertNotNull($calendar);
        self::assertTrue($calendar->isReadOnly, 'the engine will push to it otherwise');
    }

    /**
     * A remote id that was not offered is not a calendar. Accepting one would
     * mean a crafted POST minting a writable Calendar bound to an id nobody
     * discovered, which then fails on every sweep forever.
     */
    public function testAnIdTheRemoteDidNotOfferSubscribesToNothing(): void
    {
        $this->offer([
            ['remoteId' => 'work', 'name' => 'Work'],
        ]);

        $change = $this->subscriber->apply($this->source(), ['work', 'somebody-elses-calendar']);

        self::assertSame(1, $change->subscribed);
        self::assertNull($this->mirrored('somebody-elses-calendar'));
    }

    public function testTickingSomethingAlreadyMirroredChangesNothing(): void
    {
        $this->offer([['remoteId' => 'work', 'name' => 'Work']]);
        $this->subscriber->apply($this->source(), ['work']);

        $change = $this->subscriber->apply($this->source(), ['work']);

        self::assertTrue($change->isEmpty(), 'a second save of the same list is not a change');
        self::assertCount(1, $this->calendars->findMirroredForIntegration($this->integration));
    }

    /**
     * A colour is rendered straight into a `style` attribute. A remote that
     * answers with something that is not a colour must not be the thing
     * deciding what goes in there.
     */
    public function testAColourTheRemoteMadeUpIsNotRenderedIntoTheStyleAttribute(): void
    {
        $this->offer([
            ['remoteId' => 'nasty', 'name' => 'Nasty', 'color' => 'red; background-image: url(//evil.test)'],
        ]);

        $this->subscriber->apply($this->source(), ['nasty']);

        $calendar = $this->mirrored('nasty');

        self::assertNotNull($calendar);
        self::assertContains($calendar->color, Calendar::COLORS, 'an unparseable colour falls back to the palette');
    }

    // ── What a fresh subscription starts ──────────────────────────────────

    /**
     * Before this, `app:calendar:push` was the only thing that opened a
     * channel, so a calendar ticked at 09:21 polled until 10:20. The feature
     * worked and its first hour was indistinguishable from it not working —
     * which is the hour somebody is watching to find out whether it does.
     */
    public function testANewlyMirroredCalendarIsAskedToPushWithoutWaitingForTheHourlySweep(): void
    {
        $this->offer([['remoteId' => 'work', 'name' => 'Work']]);

        $this->subscriber->apply($this->source(), ['work']);

        $calendar = $this->mirrored('work');
        self::assertNotNull($calendar);

        $registrations = $this->sent('maintenance', RegisterCalendarPushMessage::class);

        self::assertCount(1, $registrations, 'nothing else opens a channel inside the hour');
        self::assertSame($calendar->id, $registrations[0]->calendarId);
    }

    /**
     * The dispatch is the whole mechanism, and the transport it lands on is the
     * proof. Registered inline, a subscribe would hold the HTTP response open on
     * a call to Google and would fail — visibly, on a checkbox — for a Cloud
     * project whose domain verification is still pending, which is a fact about
     * the deployment and not about the click.
     */
    public function testTheRegistrationIsQueuedRatherThanAttemptedInsideTheSubscribe(): void
    {
        $this->offer([['remoteId' => 'work', 'name' => 'Work']]);

        $this->subscriber->apply($this->source(), ['work']);

        self::assertCount(1, $this->sent('maintenance', RegisterCalendarPushMessage::class));

        // And deliberately not beside the first sync. That one is a full
        // calendar read; a registration queued behind it would open the channel
        // minutes late on exactly the large calendars where push earns its keep.
        self::assertSame([], $this->sent('ingest', RegisterCalendarPushMessage::class));
    }

    public function testTheFirstSyncIsStillQueuedBesideTheRegistration(): void
    {
        $this->offer([['remoteId' => 'work', 'name' => 'Work']]);

        $this->subscriber->apply($this->source(), ['work']);

        self::assertCount(
            1,
            $this->sent('ingest', SyncCalendarMessage::class),
            'push is an addition to the sweep, never a replacement for it',
        );
    }

    /**
     * An install with no publicly reachable HTTPS address can never register
     * anything, and the subscribe must not be able to notice. Guards the natural
     * wrong fix — asking isConfigured() before dispatching — which would tie a
     * calendar's mirroring to a deployment fact and, worse, mean the install
     * that later gains an address never registers the calendars it already has.
     */
    public function testSubscribingDoesNotDependOnTheDeploymentBeingReachable(): void
    {
        $_SERVER['APP_PUBLIC_URL'] = 'http://localhost:8000';

        try {
            $this->offer([['remoteId' => 'work', 'name' => 'Work']]);

            $change = $this->subscriber->apply($this->source(), ['work']);

            self::assertSame(1, $change->subscribed, 'an unreachable install must still be able to mirror a calendar');
            self::assertNotNull($this->mirrored('work'));
            self::assertCount(1, $this->sent('maintenance', RegisterCalendarPushMessage::class));
        } finally {
            unset($_SERVER['APP_PUBLIC_URL']);
        }
    }

    /**
     * Per new calendar, not per visit to the screen. A message on every save
     * would re-register a live channel each time somebody opened the list, and
     * on Google a re-registration stops the old channel and opens a new one.
     */
    public function testSavingTheSameListAgainAsksForNothing(): void
    {
        $this->offer([['remoteId' => 'work', 'name' => 'Work']]);
        $this->subscriber->apply($this->source(), ['work']);

        $this->subscriber->apply($this->source(), ['work']);

        self::assertCount(1, $this->sent('maintenance', RegisterCalendarPushMessage::class));
    }

    // ── Unsubscribing ─────────────────────────────────────────────────────

    public function testUnticklingACalendarStopsMirroringIt(): void
    {
        $this->offer([['remoteId' => 'work', 'name' => 'Work']]);
        $this->subscriber->apply($this->source(), ['work']);

        $change = $this->subscriber->apply($this->source(), []);

        self::assertSame(1, $change->unsubscribed);
        self::assertNull($this->mirrored('work'), 'the mirror should be gone, not merely hidden');
    }

    /**
     * The events the remote gave us are copies and the provider still holds
     * them, so they go with the calendar — leaving them behind is the orphan
     * case: a list that never updates again, quietly diverging from a calendar
     * the user still edits elsewhere.
     */
    public function testTheEventsTheRemoteGaveUsGoWithTheSubscription(): void
    {
        $this->offer([['remoteId' => 'work', 'name' => 'Work']]);
        $this->subscriber->apply($this->source(), ['work']);

        $calendar = $this->mirrored('work');
        self::assertNotNull($calendar);

        $pulled = $this->event($calendar, 'pulled@example.test', 'Standup', remoteId: 'r-1');
        $id     = $pulled->id;

        $this->subscriber->apply($this->source(), []);
        $this->em->clear();

        self::assertNull($this->em->getRepository(CalendarEvent::class)->find($id));
    }

    /**
     * The claim this whole class exists for. An extracted booking on a mirrored
     * calendar has no remote id and no second copy anywhere; unticking the
     * calendar must not be the thing that deletes it.
     */
    public function testAnEventTheRemoteNeverGaveUsIsMovedRatherThanDeleted(): void
    {
        $this->offer([['remoteId' => 'work', 'name' => 'Work']]);
        $this->subscriber->apply($this->source(), ['work']);

        $calendar = $this->mirrored('work');
        self::assertNotNull($calendar);

        $extracted = $this->event($calendar, 'dinner@example.test', 'Dinner at the Ivy');
        $id        = $extracted->id;

        $change = $this->subscriber->apply($this->source(), []);

        self::assertSame(1, $change->kept, 'the count is what the toast tells the user');

        $this->em->clear();

        $stored = $this->em->getRepository(CalendarEvent::class)->find($id);

        self::assertNotNull($stored, 'the only copy of this event must survive');
        self::assertNotNull($stored->calendar);
        self::assertTrue($stored->calendar->isDefault, 'it lands on the calendar the user actually looks at');
    }

    /**
     * uniq_calendar_event_calendar_uid. The same UID already on the
     * destination is the same meeting — an invitation filed twice — and moving
     * it anyway is a constraint violation, which turns unsubscribing into a
     * 500 rather than a rescue.
     */
    public function testAnEventTheDefaultCalendarAlreadyHoldsDoesNotCollideOnTheWayOut(): void
    {
        $this->offer([['remoteId' => 'work', 'name' => 'Work']]);
        $this->subscriber->apply($this->source(), ['work']);

        $calendar = $this->mirrored('work');
        self::assertNotNull($calendar);

        $home = $this->calendars->findDefaultForUser($this->user);
        self::assertNotNull($home);

        $this->event($home, 'both@example.test', 'The one already filed');
        $this->event($calendar, 'both@example.test', 'The duplicate');

        $change = $this->subscriber->apply($this->source(), []);

        self::assertSame(0, $change->kept, 'the copy already on the destination wins');
        self::assertSame(1, $change->unsubscribed);
    }

    /**
     * Where a new event lands, so unsubscribing it would make the next thing
     * the user creates vanish on save. The same rule the delete button obeys.
     */
    public function testTheDefaultCalendarCannotBeUnsubscribed(): void
    {
        $this->offer([['remoteId' => 'work', 'name' => 'Work']]);
        $this->subscriber->apply($this->source(), ['work']);

        $calendar = $this->mirrored('work');
        self::assertNotNull($calendar);

        foreach ($this->calendars->findForUser($this->user) as $sibling) {
            $sibling->isDefault = $sibling === $calendar;
        }
        $this->em->flush();

        $change = $this->subscriber->apply($this->source(), []);

        self::assertSame(0, $change->unsubscribed);
        self::assertNotNull($this->mirrored('work'), 'the default has to survive an untick');
    }

    // ── Discovery failing ─────────────────────────────────────────────────

    /**
     * A listing that failed halfway would read as "these calendars are gone"
     * and unsubscribe from every one of them. Nothing is written at all.
     */
    public function testARefusedListingUnsubscribesFromNothing(): void
    {
        $this->offer([['remoteId' => 'work', 'name' => 'Work']]);
        $this->subscriber->apply($this->source(), ['work']);

        $this->integration->setSetting(
            ScriptedCalendarSyncDriver::SETTINGS_ERROR,
            'Reconnect the account and allow calendar access.',
        );
        $this->em->flush();

        try {
            $this->subscriber->apply($this->source(), []);
            self::fail('a refusal has to reach the caller, not be swallowed into an empty list');
        } catch (CalendarSyncPermanentException $e) {
            self::assertSame('Reconnect the account and allow calendar access.', $e->getMessage());
        }

        self::assertNotNull($this->mirrored('work'), 'nothing may be unsubscribed on a failed listing');
    }

    public function testTheDiscoverListMarksWhatIsAlreadyMirrored(): void
    {
        $this->offer([
            ['remoteId' => 'work', 'name' => 'Work'],
            ['remoteId' => 'holidays', 'name' => 'Public holidays', 'readOnly' => true],
        ]);

        $this->subscriber->apply($this->source(), ['work']);

        $subscriptions = $this->discoverer->discover($this->source());

        self::assertCount(2, $subscriptions);
        self::assertTrue($subscriptions[0]->isSubscribed());
        self::assertFalse($subscriptions[1]->isSubscribed());
        self::assertTrue($subscriptions[1]->remote->isReadOnly);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * What landed on one named transport, of one type.
     *
     * By transport name rather than "whatever was dispatched", because which
     * queue a message goes to is behaviour here and not plumbing. in-memory://
     * in the test environment, and asserted rather than cast — against a real
     * transport every count above would be vacuously zero.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return list<T>
     */
    private function sent(string $transport, string $type): array
    {
        $queue = self::getContainer()->get('messenger.transport.' . $transport);

        self::assertInstanceOf(InMemoryTransport::class, $queue);

        $messages = [];

        foreach ($queue->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof $type) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function source(): CalendarSource
    {
        return CalendarSource::ofIntegration($this->integration);
    }

    /** @param list<array<string,bool|string>> $calendars */
    private function offer(array $calendars): void
    {
        $this->integration->setSetting(ScriptedCalendarSyncDriver::SETTINGS_CALENDARS, $calendars);
        $this->em->flush();
    }

    private function mirrored(string $remoteId): ?Calendar
    {
        foreach ($this->calendars->findMirroredForIntegration($this->integration) as $calendar) {
            if ($remoteId === $calendar->remoteId) {
                return $calendar;
            }
        }

        return null;
    }

    private function event(Calendar $calendar, string $uid, string $title, ?string $remoteId = null): CalendarEvent
    {
        $utc = new DateTimeZone('UTC');

        $event             = new CalendarEvent();
        $event->calendar   = $calendar;
        $event->usr        = $this->user;
        $event->uid        = $uid;
        $event->title      = $title;
        $event->startsAt   = new DateTimeImmutable('2026-09-01 09:00', $utc);
        $event->endsAt     = new DateTimeImmutable('2026-09-01 10:00', $utc);
        $event->remoteId   = $remoteId;
        $event->syncState  = SyncState::Clean;
        $event->jscalendar = ['@type' => 'Event', 'uid' => $uid, 'title' => $title];

        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'subscribe-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Sub';
        $user->nameLast  = 'Scribe';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $personal            = new Calendar();
        $personal->usr       = $user;
        $personal->name      = 'Personal';
        $personal->role      = CalendarRole::Default;
        $personal->isDefault = true;
        $personal->timeZone  = 'UTC';
        $this->em->persist($personal);

        $integration = new Integration($user, Provider::CalDav, 'Scripted server');
        $integration->baseUrl = 'https://caldav.example.invalid/dav';
        $integration->setSetting(ScriptedCalendarSyncDriver::SETTINGS_KEY, true);
        $this->em->persist($integration);

        $this->em->flush();

        $this->user        = $user;
        $this->integration = $integration;
    }
}
