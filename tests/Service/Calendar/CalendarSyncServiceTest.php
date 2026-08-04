<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\DTO\Calendar\CalendarChangeSet;
use App\Domain\DTO\Calendar\RemoteEvent;
use App\Domain\DTO\Calendar\RemoteWriteResult;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\CalendarNotifier;
use App\Service\Calendar\CalendarPuller;
use App\Service\Calendar\CalendarPusher;
use App\Service\Calendar\CalendarSyncDriverRegistry;
use App\Service\Calendar\CalendarSyncService;
use App\Service\Calendar\RecurrenceRuleConverter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Two-way calendar sync is only worth having if "changes sync back" is
 * trustworthy, and trust is entirely a question of what happens when both sides
 * moved.
 *
 * That is the claim here, and every case below is one of the rules written down
 * in CalendarSyncService's docblock. They are testable at all only because the
 * remote is behind an interface: every collaborator in Service/Calendar is
 * final, so none of them can be doubled, and FakeCalendarSyncDriver is the one
 * seam the engine was designed to have.
 *
 * The bugs each guards against are not hypothetical — they are the four ways
 * this feature classically goes wrong:
 *
 *   Pulling before pushing, so every local edit collides with its own echo and
 *   the user's change is discarded by the sync that was supposed to send it.
 *
 *   Writing on every pull regardless of the etag, so the pull that follows a
 *   push re-applies the remote's copy over whatever was typed in between, and
 *   the calendar view flickers with rewrites of rows nothing changed.
 *
 *   Storing the sync token before the window has applied, so a crash halfway
 *   through skips the rest of it permanently — which presents as "some events
 *   just never arrive" and is unfindable afterwards.
 *
 *   Discarding a local edit silently. The remote is meant to win; it is the
 *   absence of a record of the loss that makes it undebuggable, which is why
 *   there is a test asserting on a log line.
 *
 * Against a real container and a real database, because what is being pinned is
 * the state a later query will find — a row gone, a token stored, an etag
 * recorded. A set of mocks would assert those into existence rather than
 * observe them.
 */
final class CalendarSyncServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventRepository $events;
    private FakeCalendarSyncDriver $driver;
    private RecordingLogger $logger;
    private CalendarSyncService $sync;
    private CalendarPusher $pusher;
    private CalendarPuller $puller;
    private CalendarEventWriter $writer;
    private User $user;
    private Calendar $calendar;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->events     = $container->get(CalendarEventRepository::class);
        $this->driver     = new FakeCalendarSyncDriver();
        $this->logger     = new RecordingLogger();

        $this->writer = $container->get(CalendarEventWriter::class);

        // Assembled by hand rather than pulled from the container, for the two
        // things a test has to control: which driver answers, and where the log
        // lines go. Everything else is the real service.
        $this->pusher = new CalendarPusher($this->events, $this->em, $this->logger);
        $this->puller = new CalendarPuller(
            $this->events,
            $this->writer,
            new RecurrenceRuleConverter(),
            $this->em,
            $this->logger,
        );

        $this->sync = new CalendarSyncService(
            new CalendarSyncDriverRegistry([$this->driver]),
            $this->pusher,
            $this->puller,
            $this->events,
            $container->get(CalendarNotifier::class),
            $this->em,
            $this->logger,
        );

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

    // ── Pull ──────────────────────────────────────────────────────────────

    public function testAPulledEventBecomesALocalRow(): void
    {
        $this->driver->changeSets = [
            new CalendarChangeSet([$this->remoteEvent('r-1', 'uid-1', 'Standup', 'etag-a')], 'token-1'),
        ];

        self::assertSame(1, $this->sync->sync($this->calendar));

        $event = $this->events->findOneByRemoteId($this->calendar, 'r-1');

        self::assertNotNull($event, 'the pulled event should exist locally');
        self::assertSame('Standup', $event->title);
        self::assertSame('uid-1', $event->uid, 'the remote UID is the identity, not a locally minted one');
        self::assertSame('etag-a', $event->remoteEtag);
        self::assertSame(SyncState::Clean, $event->syncState);
        self::assertNotNull($event->syncedAt);
    }

    public function testAPulledChangeOverwritesTheLocalRow(): void
    {
        $this->localEvent('r-2', 'uid-2', 'Old title', etag: 'etag-a');

        $this->driver->changeSets = [
            new CalendarChangeSet([$this->remoteEvent('r-2', 'uid-2', 'New title', 'etag-b')], 'token-1'),
        ];

        $this->sync->sync($this->calendar);
        $this->em->clear();

        $event = $this->events->findOneByRemoteId($this->reloadCalendar(), 'r-2');

        self::assertNotNull($event);
        self::assertSame('New title', $event->title);
        self::assertSame('etag-b', $event->remoteEtag);
    }

    public function testARemoteDeletionRemovesTheLocalRow(): void
    {
        $event = $this->localEvent('r-3', 'uid-3', 'Doomed', etag: 'etag-a');
        $id    = $event->id;

        $this->driver->changeSets = [
            new CalendarChangeSet([RemoteEvent::deleted('r-3', 'uid-3')], 'token-1'),
        ];

        $this->sync->sync($this->calendar);
        $this->em->clear();

        self::assertNull(
            $this->em->getRepository(CalendarEvent::class)->find($id),
            'a remote deletion removes the row, it does not merely mark it',
        );
    }

    /**
     * The rule that stops a pull immediately after a push from re-applying the
     * remote's echo over whatever the user typed in between — and, in the
     * ordinary case, the reason a poll that found nothing costs no writes at
     * all.
     */
    public function testAnUnchangedEtagIsNotWritten(): void
    {
        $event = $this->localEvent('r-4', 'uid-4', 'Whatever the user typed', etag: 'etag-a');

        $this->driver->changeSets = [
            new CalendarChangeSet([$this->remoteEvent('r-4', 'uid-4', 'The remote copy', 'etag-a')], 'token-1'),
        ];

        self::assertSame(0, $this->sync->sync($this->calendar), 'nothing changed, so nothing was written');

        $this->em->refresh($event);

        self::assertSame('Whatever the user typed', $event->title);
    }

    /**
     * An invitation arriving by mail creates a row carrying the organiser's
     * UID. The same meeting on the connected calendar carries that UID and a
     * remote id plMail has never seen — matched on the id alone, accepting an
     * invite puts the meeting on the calendar twice.
     */
    public function testAnEventIsMatchedByUidWhenItsRemoteIdIsNew(): void
    {
        $this->localEvent(null, 'uid-invite@example.test', 'Kickoff', etag: null);

        $this->driver->changeSets = [
            new CalendarChangeSet(
                [$this->remoteEvent('r-5', 'uid-invite@example.test', 'Kickoff', 'etag-a')],
                'token-1',
            ),
        ];

        $this->sync->sync($this->calendar);

        self::assertCount(
            1,
            $this->events->findBy(['calendar' => $this->calendar]),
            'the invite and the synced meeting are one event, not two',
        );
    }

    // ── The sync token ────────────────────────────────────────────────────

    public function testTheSyncTokenIsStoredAndPresentedOnTheNextPull(): void
    {
        $this->driver->changeSets = [new CalendarChangeSet([], 'token-1')];

        $this->sync->sync($this->calendar);

        self::assertSame('token-1', $this->calendar->syncToken);

        $this->sync->sync($this->calendar);

        self::assertSame(
            [null, 'token-1'],
            $this->driver->pulledWith,
            'the second pull must resume from the token the first was given',
        );
    }

    public function testAResyncClearsTheTokenAndReadsTheCalendarFromScratch(): void
    {
        $this->calendar->syncToken = 'stale-token';
        $this->em->flush();

        $this->driver->changeSets = [
            CalendarChangeSet::resyncRequired(),
            new CalendarChangeSet([$this->remoteEvent('r-6', 'uid-6', 'Rediscovered', 'etag-a')], 'token-2'),
        ];

        $this->sync->sync($this->calendar);

        self::assertSame(
            ['stale-token', null],
            $this->driver->pulledWith,
            'the dead token must be forgotten before the second pull, not presented again',
        );
        self::assertSame('token-2', $this->calendar->syncToken);
        self::assertNotNull($this->events->findOneByRemoteId($this->calendar, 'r-6'));
    }

    /**
     * A driver that answers requiresFullResync to a null token has a bug, and
     * the loop would otherwise hammer the provider forever.
     */
    public function testADriverThatKeepsDemandingAResyncIsGivenUpOn(): void
    {
        $this->driver->changeSets = [CalendarChangeSet::resyncRequired()];

        try {
            $this->sync->sync($this->calendar);
            self::fail('a driver that never accepts a full read has to be given up on');
        } catch (CalendarSyncPermanentException) {
            // The count is the claim. The exception alone would still be thrown
            // after a hundred round trips, and "eventually stops" is not what
            // this rule says.
            self::assertCount(
                2,
                $this->driver->pulledWith,
                'one pull, one full read, then stop — anything more is hammering the provider',
            );
        }
    }

    /**
     * A full read carries no tombstones, so a deletion that happened while the
     * token was dead is knowable only by absence.
     */
    public function testAFullReadRemovesRowsItDidNotMention(): void
    {
        $survivor = $this->localEvent('r-7', 'uid-7', 'Still there', etag: 'etag-old');
        $vanished = $this->localEvent('r-8', 'uid-8', 'Gone at the remote', etag: 'etag-old');
        $local    = $this->localEvent(null, 'uid-9', 'Never left this machine', etag: null);

        $survivorId = $survivor->id;
        $vanishedId = $vanished->id;
        $localId    = $local->id;

        $this->driver->changeSets = [
            new CalendarChangeSet([$this->remoteEvent('r-7', 'uid-7', 'Still there', 'etag-new')], 'token-1'),
        ];

        $this->sync->sync($this->calendar);
        $this->em->clear();

        $repository = $this->em->getRepository(CalendarEvent::class);

        self::assertNotNull($repository->find($survivorId));
        self::assertNull($repository->find($vanishedId), 'the full read did not list it, so it is gone');
        self::assertNotNull(
            $repository->find($localId),
            'a row that never reached the remote is not evidence of anything the remote says',
        );
    }

    // ── Push ──────────────────────────────────────────────────────────────

    public function testAPushStoresTheRemoteIdAndEtagItWasGiven(): void
    {
        $event = $this->localEvent(null, 'uid-10', 'Made here', etag: null, state: SyncState::PendingCreate);

        $this->driver->writeResult = new RemoteWriteResult('r-10', 'etag-fresh');

        $this->sync->sync($this->calendar);

        self::assertSame('r-10', $event->remoteId);
        self::assertSame('etag-fresh', $event->remoteEtag);
        self::assertSame(SyncState::Clean, $event->syncState);
        self::assertNotNull($event->syncedAt);
    }

    public function testALocalEditIsPushedBeforeTheRemoteIsRead(): void
    {
        $this->localEvent('r-11', 'uid-11', 'Edited here', etag: 'etag-a', state: SyncState::PendingUpdate);

        $this->sync->sync($this->calendar);

        self::assertSame(
            ['push', 'pull'],
            $this->driver->calls,
            'pulling first makes every local edit collide with its own echo',
        );
    }

    public function testALocalDeleteReachesTheRemoteBeforeTheRowGoes(): void
    {
        $event = $this->localEvent('r-12', 'uid-12', 'Deleted here', etag: 'etag-a', state: SyncState::PendingDelete);
        $id    = $event->id;

        $this->sync->sync($this->calendar);
        $this->em->clear();

        self::assertCount(1, $this->driver->deleted, 'the remote must be told before the row is final');
        self::assertNull($this->em->getRepository(CalendarEvent::class)->find($id));
    }

    public function testAReadOnlyCalendarRefusesAPush(): void
    {
        $this->calendar->isReadOnly = true;
        $this->em->flush();

        $this->expectException(LogicException::class);

        $this->pusher->push($this->calendar, $this->driver);
    }

    public function testAReadOnlyCalendarIsPulledButNeverPushedTo(): void
    {
        $this->calendar->isReadOnly = true;
        $this->localEvent('r-13', 'uid-13', 'Edited here anyway', etag: 'etag-a', state: SyncState::PendingUpdate);

        $this->sync->sync($this->calendar);

        self::assertSame(['pull'], $this->driver->calls);
        self::assertNotSame(
            [],
            $this->logger->matching('warning', 'read-only calendar is holding local edits'),
            'an edit that cannot be sent is reported rather than silently dropped',
        );
    }

    // ── Conflict ──────────────────────────────────────────────────────────

    /**
     * A row changed on both sides. Rule 1 keeps this rare — the push goes first
     * — so the live route into it is a calendar that is not pushed to at all: a
     * read-only one holding an edit the UI should never have allowed, or a row
     * whose push failed on an earlier run.
     *
     * Asserted against CalendarPuller rather than through sync(), because the
     * rule is the puller's and reaching it through the whole engine means
     * arranging a push failure whose own handling would answer the same claim.
     */
    public function testARowChangedOnBothSidesLosesItsLocalChangeAndSaysSo(): void
    {
        $event = $this->localEvent('r-14', 'uid-14', 'What the user typed', etag: 'etag-a', state: SyncState::PendingUpdate);

        $this->puller->apply($this->calendar, new CalendarChangeSet(
            [$this->remoteEvent('r-14', 'uid-14', 'What the remote holds', 'etag-b')],
            'token-1',
        ));
        $this->em->flush();

        self::assertSame('What the remote holds', $event->title, 'the remote wins');

        $discarded = $this->logger->matching('warning', 'a local edit was discarded');

        self::assertCount(1, $discarded);
        self::assertSame(
            'What the user typed',
            $discarded[0]['context']['discarded']['title'] ?? null,
            'the log line has to carry what was lost, or it answers no question anybody asks',
        );
    }

    /** A remote deletion discards a local edit too, and it is recorded the same way. */
    public function testARemoteDeletionOfALocallyEditedRowIsAlsoRecorded(): void
    {
        $event = $this->localEvent('r-15', 'uid-15', 'Edited then deleted elsewhere', etag: 'etag-a', state: SyncState::PendingUpdate);
        $id    = $event->id;

        $this->puller->apply($this->calendar, new CalendarChangeSet(
            [RemoteEvent::deleted('r-15', 'uid-15')],
            'token-1',
        ));
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->em->getRepository(CalendarEvent::class)->find($id));
        self::assertNotSame([], $this->logger->matching('warning', 'a local edit was discarded'));
    }

    /** A row nobody had edited is overwritten in silence — a warning per event would mean nothing. */
    public function testAnUneditedRowIsOverwrittenWithoutAWarning(): void
    {
        $this->localEvent('r-14b', 'uid-14b', 'The old copy', etag: 'etag-a');

        $this->puller->apply($this->calendar, new CalendarChangeSet(
            [$this->remoteEvent('r-14b', 'uid-14b', 'The new copy', 'etag-b')],
            'token-1',
        ));

        self::assertSame([], $this->logger->matching('warning', 'a local edit was discarded'));
    }

    /**
     * An event the remote will never accept must not be re-offered on every
     * sweep forever — a queue that retries something impossible eventually
     * retries nothing else. Giving up is also the moment the local edit stops
     * being one that will ever leave, so it is the moment to write it down.
     */
    public function testAnEventTheRemotePermanentlyRefusesIsGivenUpOnRatherThanRetriedForever(): void
    {
        $event = $this->localEvent('r-16', 'uid-16', 'Unacceptable', etag: 'etag-a', state: SyncState::PendingUpdate);

        $this->driver->pushThrows = new CalendarSyncPermanentException('Invalid recurrence rule.');

        $this->sync->sync($this->calendar);

        self::assertSame(SyncState::Clean, $event->syncState);

        $abandoned = $this->logger->matching('error', 'refused an event permanently');

        self::assertCount(1, $abandoned);
        self::assertSame(
            'Unacceptable',
            $abandoned[0]['context']['discarded']['title'] ?? null,
            'the abandoned edit has to be recoverable from the log; nothing else will hold it',
        );
    }

    // ── Bookkeeping ───────────────────────────────────────────────────────

    public function testASuccessfulSyncIsRecordedAndClearsTheLastError(): void
    {
        $this->calendar->lastSyncError = 'something went wrong last time';
        $this->em->flush();

        $this->sync->sync($this->calendar);

        self::assertNotNull($this->calendar->lastSyncedAt);
        self::assertNull($this->calendar->lastSyncError);
    }

    public function testAFailedSyncLeavesItsReasonOnTheCalendar(): void
    {
        $this->localEvent('r-17', 'uid-17', 'Doomed push', etag: 'etag-a', state: SyncState::PendingUpdate);

        $this->driver->pushThrows = new \RuntimeException('The calendar service is unreachable.');

        try {
            $this->sync->sync($this->calendar);
            self::fail('the sync should have propagated the failure');
        } catch (\RuntimeException $e) {
            self::assertSame('The calendar service is unreachable.', $e->getMessage());
        }

        $this->em->clear();

        self::assertSame(
            'The calendar service is unreachable.',
            $this->reloadCalendar()->lastSyncError,
            'a calendar that has quietly stopped working has to say so somewhere a user looks',
        );
    }

    public function testACalendarConnectedToNothingIsNotSyncable(): void
    {
        $orphan            = new Calendar();
        $orphan->usr       = $this->user;
        $orphan->name      = 'Just a list';
        $orphan->role      = CalendarRole::Custom;
        $this->em->persist($orphan);
        $this->em->flush();

        $this->expectException(CalendarSyncPermanentException::class);

        $this->sync->sync($orphan);
    }

    // ── Marking a local change ────────────────────────────────────────────

    /**
     * The entry point to the whole push half, and the one that fails silently:
     * a local write that forgets to mark the row is an edit that never leaves,
     * with nothing anywhere saying so.
     */
    public function testAnEditToASyncedEventIsMarkedForPushing(): void
    {
        $event = $this->localEvent('r-20', 'uid-20', 'Before', etag: 'etag-a');

        $this->writer->markLocallyChanged($event);

        self::assertSame(SyncState::PendingUpdate, $event->syncState);
    }

    public function testAnEditToACalendarThatMirrorsNothingIsNotMarked(): void
    {
        $local            = new Calendar();
        $local->usr       = $this->user;
        $local->name      = 'Just a list';
        $local->role      = CalendarRole::Custom;
        $this->em->persist($local);
        $this->em->flush();

        $event           = $this->localEvent(null, 'uid-21', 'Private note', etag: null);
        $event->calendar = $local;

        $this->writer->markLocallyChanged($event);

        self::assertSame(
            SyncState::Clean,
            $event->syncState,
            'a calendar with no remote has nothing to owe anybody a write',
        );
    }

    /**
     * A synced delete cannot remove the row — the row is the only record that
     * the remote still holds a copy. Dropping the occurrences is what makes it
     * disappear from every view anyway, since views read occurrences and never
     * events.
     */
    public function testDeletingASyncedEventKeepsTheRowAndTakesItOutOfEveryView(): void
    {
        $event = $this->materialisedEvent('uid-22', 'Meeting');
        $id    = $event->id;

        self::assertFalse(
            $this->writer->markLocallyDeleted($event),
            'the caller must not remove a row the remote has not been told about',
        );

        $this->em->flush();
        $this->em->clear();

        $stored = $this->em->getRepository(CalendarEvent::class)->find($id);

        self::assertNotNull($stored);
        self::assertSame(SyncState::PendingDelete, $stored->syncState);
        self::assertCount(0, $stored->occurrences, 'nothing should still draw it');
    }

    public function testDeletingAnEventTheRemoteNeverSawIsTheCallersToRemove(): void
    {
        $event = $this->localEvent(null, 'uid-23', 'Never pushed', etag: null, state: SyncState::PendingCreate);

        self::assertTrue($this->writer->markLocallyDeleted($event));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function remoteEvent(string $remoteId, string $uid, string $title, ?string $etag): RemoteEvent
    {
        $utc = new DateTimeZone('UTC');

        return new RemoteEvent(
            remoteId:   $remoteId,
            etag:       $etag,
            uid:        $uid,
            isDeleted:  false,
            jscalendar: [
                '@type'    => 'Event',
                'uid'      => $uid,
                'title'    => $title,
                'start'    => '2026-06-01T09:00:00',
                'timeZone' => 'UTC',
                'duration' => 'PT1H',
                'status'   => 'confirmed',
            ],
            startsAt:   new DateTimeImmutable('2026-06-01 09:00', $utc),
            endsAt:     new DateTimeImmutable('2026-06-01 10:00', $utc),
        );
    }

    private function localEvent(
        ?string   $remoteId,
        string    $uid,
        string    $title,
        ?string   $etag,
        SyncState $state = SyncState::Clean,
    ): CalendarEvent {
        $utc = new DateTimeZone('UTC');

        $event             = new CalendarEvent();
        $event->calendar   = $this->calendar;
        $event->usr        = $this->user;
        $event->uid        = $uid;
        $event->title      = $title;
        $event->startsAt   = new DateTimeImmutable('2026-06-01 09:00', $utc);
        $event->endsAt     = new DateTimeImmutable('2026-06-01 10:00', $utc);
        $event->remoteId   = $remoteId;
        $event->remoteEtag = $etag;
        $event->syncState  = $state;
        $event->jscalendar = ['@type' => 'Event', 'uid' => $uid, 'title' => $title];

        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    /** Written through the real writer, so it has the occurrences a view reads. */
    private function materialisedEvent(string $uid, string $title): CalendarEvent
    {
        $utc   = new DateTimeZone('UTC');
        $event = new CalendarEvent();

        $event->uid = $uid;

        $this->writer->write(
            event:    $event,
            calendar: $this->calendar,
            user:     $this->user,
            title:    $title,
            startsAt: new DateTimeImmutable('2026-06-01 09:00', $utc),
            endsAt:   new DateTimeImmutable('2026-06-01 10:00', $utc),
            timeZone: 'UTC',
        );

        $event->remoteId = 'r-' . $uid;
        $this->em->flush();

        return $event;
    }

    /** After an em->clear(), the fixture handles are detached. */
    private function reloadCalendar(): Calendar
    {
        $calendar = $this->em->getRepository(Calendar::class)->find($this->calendar->id);

        self::assertInstanceOf(Calendar::class, $calendar);

        return $calendar;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'calsync-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Cal';
        $user->nameLast  = 'Sync';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'calsync-fixture@example.test';
        $account->username       = 'calsync-fixture@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'oauth2';
        $account->oauthProvider  = 'google';
        $account->isActive       = true;
        $this->em->persist($account);

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->account  = $account;
        $calendar->name     = 'Work';
        $calendar->role     = CalendarRole::Remote;
        $calendar->remoteId = 'remote-calendar-1';
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;
    }
}
