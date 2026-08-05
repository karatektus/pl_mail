<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\DTO\Calendar\OccurrenceCluster;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\CalendarView;
use App\Domain\Enum\Calendar\SyncState;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Repository\Calendar\CalendarEventRepository;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\CalendarRangeReader;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Editing a meeting across the calendars it is on, and the ones it is not: one
 * editor, a checkbox per calendar, and N ordinary writes.
 *
 * One editor is the whole claim. Two copies of a meeting are two remote objects
 * with their own remoteId, etag and sync state — merging them in the model would
 * break sync — so what the user is given is one dialog that knows about all of
 * them, and the write fans out. The list is every calendar the user owns rather
 * than only the ones already holding a copy, so the same fan-out is how a
 * meeting gets put on a calendar it has never been on. Everything that can go
 * wrong here is invisible until somebody notices a meeting is in two places or
 * in none:
 *
 *   An edit that reaches only the copy whose chip was clicked leaves the other
 *   saying the old thing, and the calendar quietly shows two meetings again
 *   with no explanation of which is right.
 *
 *   An edit that honours a posted destination moves every copy onto one
 *   calendar, collapsing rows the sync engine still owes writes for.
 *
 *   A write to a read-only mirror is a write nothing will ever accept, and
 *   queuing a push for it is a sync that fails forever.
 *
 *   A copy written without being marked leaves a synced calendar holding the
 *   old times with nothing to tell it otherwise.
 *
 *   And a copy created with a UID of its own is a second meeting: same title,
 *   same hour, two chips for ever, with no later edit able to merge them.
 *
 * Written as requests rather than against the controller directly, because half
 * of what is checked is not in the method body: the CSRF token, the checkboxes
 * the editor renders, and what the next render of the calendar then draws.
 */
final class DuplicateMeetingEditorTest extends WebTestCase
{
    /** The organiser's UID, shared by both copies — the identity the merge rests on. */
    private const string SHARED_UID = 'duplicate-meeting@organiser.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventWriter $writer;
    private CalendarEventRepository $events;
    private CalendarRangeReader $reader;
    private User $user;
    private Calendar $account;
    private Calendar $mirror;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The list is the honest statement of where the meeting can be, so a
     * calendar that cannot be written is shown rather than hidden — disabled and
     * unticked, with the lock the calendar settings list already uses.
     */
    public function testTheEditorListsEveryCalendarAndDisablesTheOneNothingMayWrite(): void
    {
        $client = $this->signIn();

        $this->mirror->isReadOnly = true;
        $this->em->flush();

        [$account] = $this->twoCopies();

        $crawler = $client->request('GET', '/calendar/event/' . $account->id . '/edit');

        self::assertResponseIsSuccessful();

        self::assertCount(2, $crawler->filter('input[name="calendars[]"]'), 'every calendar is offered');

        self::assertCount(
            1,
            $crawler->filter(sprintf('input[name="calendars[]"][value="%d"][checked]', $this->account->id)),
            'editing the meeting means the meeting, so a calendar it is on starts ticked',
        );

        self::assertCount(
            1,
            $crawler->filter(sprintf('input[name="calendars[]"][value="%d"][disabled]', $this->mirror->id)),
            'a mirror that accepts no writes back is offered as unwritable, not offered as writable',
        );

        self::assertCount(
            0,
            $crawler->filter(sprintf('input[name="calendars[]"][value="%d"][checked]', $this->mirror->id)),
            'a calendar nothing may write must not be ticked',
        );

        self::assertCount(
            0,
            $crawler->filter('select[name="calendarId"]'),
            'the dropdown is gone, not shown beside a control that contradicts it',
        );
    }

    /**
     * The feature, from the editor's side: an event on one calendar is offered
     * every other one, unticked, because "also put this on my work calendar" is
     * the thing the old list could not say.
     */
    public function testAnEventThatExistsOnceIsStillOfferedEveryOtherCalendar(): void
    {
        $client = $this->signIn();
        $lone   = $this->copy('lone@plmail', $this->account);

        $crawler = $client->request('GET', '/calendar/event/' . $lone->id . '/edit');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('select[name="calendarId"]'));
        self::assertCount(2, $crawler->filter('input[name="calendars[]"]'));

        self::assertCount(
            1,
            $crawler->filter(sprintf('input[name="calendars[]"][value="%d"][checked]', $this->account->id)),
            'the calendar it is on is ticked',
        );

        self::assertCount(
            0,
            $crawler->filter(sprintf('input[name="calendars[]"][value="%d"][checked]', $this->mirror->id)),
            'a calendar it is not on is offered, and offered unticked',
        );
    }

    public function testAnEditWithEveryCopyTickedReachesEveryCopy(): void
    {
        $client             = $this->signIn();
        [$account, $mirror] = $this->twoCopies();

        $this->save($client, $account, $this->bothCalendars(), ['title' => 'Sync — new agenda']);

        self::assertResponseRedirects();

        self::assertSame('Sync — new agenda', $this->reload($account)->title);
        self::assertSame(
            'Sync — new agenda',
            $this->reload($mirror)->title,
            'editing the meeting means the meeting, not the row whose chip was clicked',
        );
    }

    /**
     * The point of the checkboxes, and the outcome the help text promises: the
     * copy left alone now disagrees, and a disagreement is drawn rather than
     * hidden — two chips where there was one.
     */
    public function testAnEditWithOneCopyUntickedLeavesItAloneAndTheCalendarThenDrawsTwoChips(): void
    {
        $client             = $this->signIn();
        [$account, $mirror] = $this->twoCopies();

        self::assertCount(1, $this->chipsOnTheDay(), 'one meeting, one chip, before anything is edited');

        $this->save($client, $account, [$this->account->id], ['title' => 'Sync — new agenda']);

        self::assertSame('Sync — new agenda', $this->reload($account)->title);
        self::assertSame('Sync', $this->reload($mirror)->title, 'an unticked copy is not written');

        self::assertCount(
            2,
            $this->chipsOnTheDay(),
            'copies that disagree are two meetings on screen, which is the point of leaving one out',
        );
    }

    /**
     * The dropdown is gone precisely so this cannot happen: honouring a
     * destination would put both rows on one calendar, and two rows on one
     * calendar is what uniq_calendar_event_calendar_uid refuses.
     *
     * The crafted `calendarId` is the point of the test rather than a leftover.
     * The field is not rendered by anything any more, so the only way it reaches
     * the save is a hand-written post — and the save must go on writing each
     * copy where it already is rather than reading a destination out of it.
     */
    public function testEachCopyIsWrittenBackToItsOwnCalendar(): void
    {
        $client             = $this->signIn();
        [$account, $mirror] = $this->twoCopies();

        $this->save($client, $account, $this->bothCalendars(), ['calendarId' => (string) $this->account->id]);

        // The redirect is half the assertion. Honouring the destination puts
        // two rows carrying one UID on one calendar, which is exactly what
        // uniq_calendar_event_calendar_uid refuses — so the failure mode is a
        // 500 on save, and a test that only read the rows back would miss it.
        self::assertResponseRedirects();

        self::assertSame($this->account->id, $this->reload($account)->calendar?->id);
        self::assertSame($this->mirror->id, $this->reload($mirror)->calendar?->id);
    }

    public function testASyncedCopyIsMarkedForPushAndAReadOnlyOneIsNeverWrittenAtAll(): void
    {
        $client = $this->signIn();

        // Mirrored and bound to a remote, which is what isSynced() means — and
        // read-only, which is what a public holiday calendar is.
        $this->mirror->role     = CalendarRole::Remote;
        $this->mirror->remoteId = 'remote-' . uniqid('', true);
        $this->em->flush();

        [$account, $mirror] = $this->twoCopies();

        self::assertSame(SyncState::Clean, $mirror->syncState);

        $this->save($client, $account, $this->bothCalendars(), ['title' => 'Sync — new agenda']);

        self::assertSame(
            SyncState::PendingUpdate,
            $this->reload($mirror)->syncState,
            'a copy on a calendar that mirrors something owes that remote a write',
        );

        self::assertSame(
            SyncState::Clean,
            $this->reload($account)->syncState,
            'a calendar that mirrors nothing has nobody to tell',
        );
    }

    public function testAReadOnlyCopyIsRefusedEvenWhenTheRequestNamesIt(): void
    {
        $client = $this->signIn();

        $this->mirror->isReadOnly = true;
        $this->mirror->role       = CalendarRole::Remote;
        $this->mirror->remoteId   = 'remote-' . uniqid('', true);
        $this->em->flush();

        [$account, $mirror] = $this->twoCopies();

        $this->save($client, $account, $this->bothCalendars(), ['title' => 'Sync — new agenda']);

        self::assertSame('Sync', $this->reload($mirror)->title, 'a disabled checkbox is not a guarantee to a server');
        self::assertSame(
            SyncState::Clean,
            $this->reload($mirror)->syncState,
            'a mirror that accepts no writes must never be queued for one',
        );
    }

    public function testADeleteRemovesTheTickedCopiesAndLeavesTheRest(): void
    {
        $client             = $this->signIn();
        [$account, $mirror] = $this->twoCopies();

        $this->delete($client, $account, [$this->mirror->id]);

        self::assertResponseRedirects();

        self::assertNotNull($this->events->find($account->id), 'the copy that was not ticked stays');
        self::assertNull($this->events->find($mirror->id), 'the copy that was ticked goes');
    }

    public function testADeleteWithEveryCopyTickedTakesTheMeetingOffEveryCalendar(): void
    {
        $client             = $this->signIn();
        [$account, $mirror] = $this->twoCopies();

        $this->delete($client, $account, $this->bothCalendars());

        self::assertNull($this->events->find($account->id));
        self::assertNull($this->events->find($mirror->id));
    }

    /**
     * An edit with every box cleared has nowhere to go. Refused rather than
     * performed silently: a save that redraws the calendar unchanged reads as a
     * save that did not work, and the user tries again.
     */
    public function testAnEditThatNamesNoCopyIsRefusedRatherThanQuietlyDoingNothing(): void
    {
        $client             = $this->signIn();
        [$account, $mirror] = $this->twoCopies();

        $this->save($client, $account, [], ['title' => 'Sync — new agenda']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Sync', $this->reload($account)->title);
        self::assertSame('Sync', $this->reload($mirror)->title);
    }

    // ── Putting the meeting somewhere it has never been ────────────────────

    /**
     * The feature: a calendar the meeting is not on can be ticked, and what
     * lands there is the same meeting rather than a second one.
     *
     * The UID is the whole assertion. A copy that minted its own would be a
     * different meeting to EventClusterer — same title, same hour, two chips,
     * for ever, with no edit able to merge them again — so "one chip" is the
     * only check that distinguishes a copy from a duplicate.
     */
    public function testTickingACalendarTheMeetingIsNotOnPutsACopyThereUnderTheSameUid(): void
    {
        $client = $this->signIn();
        $lone   = $this->copy(self::SHARED_UID, $this->account);

        self::assertCount(1, $this->chipsOnTheDay());

        $this->save($client, $lone, $this->bothCalendars(), ['title' => 'Sync']);

        self::assertResponseRedirects();

        $mirrored = $this->events->findOneBy(['calendar' => $this->mirror, 'uid' => self::SHARED_UID]);

        self::assertNotNull($mirrored, 'ticking a calendar it was not on puts the meeting there');
        self::assertSame('Sync', $mirrored->title);

        self::assertCount(
            1,
            $this->chipsOnTheDay(),
            'a copy carries the meeting\'s UID, so the two are still one chip and not two',
        );
    }

    /**
     * A copy that reaches the database and never reaches the provider is a row
     * the next pull deletes or duplicates.
     *
     * PendingCreate rather than PendingUpdate, because the two are a POST and a
     * PUT at the remote and the remote has never heard of this row — and a sync
     * asked for now rather than at the next sweep, which is fifteen minutes of
     * an edit not being on the user's phone.
     */
    public function testACopyCreatedOnASyncedCalendarIsQueuedAsACreateAndSyncedAtOnce(): void
    {
        $client = $this->signIn();

        $this->mirror->role     = CalendarRole::Remote;
        $this->mirror->remoteId = 'remote-' . uniqid('', true);
        $this->em->flush();

        $lone = $this->copy(self::SHARED_UID, $this->account);

        $this->save($client, $lone, $this->bothCalendars(), ['title' => 'Sync']);

        $mirrored = $this->events->findOneBy(['calendar' => $this->mirror, 'uid' => self::SHARED_UID]);

        self::assertNotNull($mirrored);
        self::assertSame(
            SyncState::PendingCreate,
            $mirrored->syncState,
            'a row the remote has never seen owes it a create, not an update',
        );

        self::assertContains(
            $this->mirror->id,
            array_map(
                static fn (SyncCalendarMessage $message): int => $message->calendarId,
                $this->dispatchedSyncs(),
            ),
            'the calendar that gained the copy is asked to sync now',
        );
    }

    /**
     * A read-only destination is offered so the list stays true, and refused so
     * the list stays honest — a disabled checkbox is a statement to a browser,
     * never a guarantee to a server.
     */
    public function testTickingAReadOnlyCalendarCreatesNothingOnIt(): void
    {
        $client = $this->signIn();

        $this->mirror->isReadOnly = true;
        $this->em->flush();

        $lone = $this->copy(self::SHARED_UID, $this->account);

        $this->save($client, $lone, $this->bothCalendars(), ['title' => 'Sync']);

        self::assertNull(
            $this->events->findOneBy(['calendar' => $this->mirror, 'uid' => self::SHARED_UID]),
            'a mirror that accepts no writes back must not be written to, however the request is crafted',
        );
    }

    /**
     * "This event" is about one occurrence of a series, and a calendar the
     * series is not on has no series for it to be an occurrence of.
     *
     * Refused whole rather than honoured for the copies that exist and skipped
     * for the rest. The alternative the code has to avoid is worse than either:
     * writing the new copy from the posted fields would create a weekly series
     * starting on the day the user happened to click, which looks right in the
     * editor and is wrong on every other week.
     */
    public function testAPerInstanceSaveRefusesToCreateACopyRatherThanRebasingASeriesOntoTheOccurrence(): void
    {
        $client = $this->signIn();
        $weekly = ['@type' => 'RecurrenceRule', 'frequency' => 'weekly', 'count' => 8];
        $series = $this->copy(self::SHARED_UID, $this->account, $weekly);

        $moved = $this->start()->modify('+1 week')->setTime(14, 0);

        $this->save($client, $series, $this->bothCalendars(), [
            'scope'        => 'instance',
            'recurrenceId' => $this->start()->modify('+1 week')->format('Y-m-d\TH:i:s\Z'),
            'startsAt'     => $moved->format('Y-m-d\TH:i'),
            'endsAt'       => $moved->modify('+1 hour')->format('Y-m-d\TH:i'),
        ]);

        self::assertResponseStatusCodeSame(422);

        self::assertNull(
            $this->events->findOneBy(['calendar' => $this->mirror, 'uid' => self::SHARED_UID]),
            'nothing is created',
        );

        self::assertSame(
            [],
            array_keys($this->reload($series)->jscalendar['recurrenceOverrides'] ?? []),
            'and the half of the save that could have been honoured is not honoured either',
        );
    }

    /**
     * A tick means "write here" to the save and can only mean "remove what is
     * here" to the delete, so a ticked calendar with nothing on it is nothing
     * to do — not a row created in order to be destroyed, and not a refusal
     * over a box the editor itself ticked.
     */
    public function testADeleteIgnoresATickedCalendarWithNoCopyOnIt(): void
    {
        $client = $this->signIn();
        $lone   = $this->copy(self::SHARED_UID, $this->account);

        $this->delete($client, $lone, $this->bothCalendars());

        self::assertResponseRedirects();

        self::assertNull($this->events->find($lone->id), 'the copy that exists goes');
        self::assertNull(
            $this->events->findOneBy(['calendar' => $this->mirror, 'uid' => self::SHARED_UID]),
            'and the calendar it was never on gains nothing',
        );
    }

    // ── The hard case: a cluster whose members repeat ──────────────────────

    /**
     * "This event" across a cluster is the same patch filed once per copy,
     * keyed by that copy's OWN occurrence at the same recurrence id.
     *
     * Not refused, and the alternative is why. A merged chip is the only chip
     * the user has for that occurrence, so refusing per-instance edits on it
     * would make "move next Tuesday's standup" impossible without first going
     * to the settings screen and hiding a calendar. Each copy is its own series
     * with its own recurrenceOverrides map, so the fan-out is N ordinary calls
     * to the same EventInstanceEditor a lone series uses — no second mechanism,
     * and nothing shared between the copies that sync could trip over.
     */
    public function testAPerInstanceEditAcrossAClusterPatchesEachCopysOwnSeries(): void
    {
        $client             = $this->signIn();
        [$account, $mirror] = $this->twoSeries();

        $moved = $this->start()->modify('+1 week')->setTime(14, 0);

        $this->save($client, $account, $this->bothCalendars(), [
            'scope'        => 'instance',
            'recurrenceId' => $this->start()->modify('+1 week')->format('Y-m-d\TH:i:s\Z'),
            'startsAt'     => $moved->format('Y-m-d\TH:i'),
            'endsAt'       => $moved->modify('+1 hour')->format('Y-m-d\TH:i'),
        ]);

        $key = $this->start()->modify('+1 week')->format('Y-m-d\TH:i:s');

        foreach ([$account, $mirror] as $copy) {
            $overrides = $this->reload($copy)->jscalendar['recurrenceOverrides'] ?? [];

            self::assertSame(
                [$key],
                array_keys($overrides),
                'each copy carries one patch, filed where the rule originally put that instance',
            );

            self::assertSame($moved->format('Y-m-d\TH:i:s'), $overrides[$key]['start']);
        }

        self::assertSame(
            $this->start()->format(DATE_ATOM),
            $this->reload($mirror)->startsAt?->format(DATE_ATOM),
            'a patch on one instance must not move the series it belongs to',
        );
    }

    /**
     * The other half of the choice, across a cluster. "All events" reads the
     * posted fields as the change the user made to the occurrence they were
     * looking at, and applies that shift to each copy's own series — so both
     * move together and neither is rebased onto the clicked week.
     */
    public function testASeriesEditAcrossAClusterShiftsEveryCopyByWhatChanged(): void
    {
        $client             = $this->signIn();
        [$account, $mirror] = $this->twoSeries();

        $moved = $this->start()->modify('+1 week')->modify('+1 hour');

        $this->save($client, $account, $this->bothCalendars(), [
            'scope'        => 'series',
            'recurrenceId' => $this->start()->modify('+1 week')->format('Y-m-d\TH:i:s\Z'),
            'startsAt'     => $moved->format('Y-m-d\TH:i'),
            'endsAt'       => $moved->modify('+1 hour')->format('Y-m-d\TH:i'),
        ]);

        foreach ([$account, $mirror] as $copy) {
            self::assertSame(
                $this->start()->modify('+1 hour')->format(DATE_ATOM),
                $this->reload($copy)->startsAt?->format(DATE_ATOM),
                'the series keeps its week and gains the hour, on every copy',
            );
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * The editor posts CALENDAR ids, not event ids: its one control is a
     * checkbox per calendar the user owns, ticked where the meeting already is.
     *
     * @param list<int|null>        $calendars
     * @param array<string, string> $overrides
     */
    private function save(KernelBrowser $client, CalendarEvent $event, array $calendars, array $overrides = []): void
    {
        $start = $this->start();

        $client->request('POST', '/calendar/event/save', array_merge([
            '_token'    => $this->token($client, $event, '_token'),
            'eventId'   => (string) $event->id,
            'title'     => 'Sync',
            'timeZone'  => 'UTC',
            'startsAt'  => $start->format('Y-m-d\TH:i'),
            'endsAt'    => $start->modify('+1 hour')->format('Y-m-d\TH:i'),
            'view'      => CalendarView::Day->value,
            'calendars' => array_map(strval(...), $calendars),
        ], $overrides));
    }

    /** @param list<int|null> $calendars */
    private function delete(KernelBrowser $client, CalendarEvent $event, array $calendars): void
    {
        $client->request('POST', '/calendar/event/' . $event->id . '/delete', [
            '_deleteToken' => $this->token($client, $event, '_deleteToken'),
            'date'         => $this->start()->format('Y-m-d'),
            'calendars'    => array_map(strval(...), $calendars),
        ]);
    }

    /**
     * A token read out of the editor the way a browser gets it. Minted through
     * the token manager instead, it is a token for a session the test happens
     * to hold rather than the one the form was rendered into — which the
     * same-origin manager rejects, correctly and confusingly.
     */
    private function token(KernelBrowser $client, CalendarEvent $event, string $field): string
    {
        $crawler = $client->request('GET', '/calendar/event/' . $event->id . '/edit');

        return (string) $crawler->filter(sprintf('input[name="%s"]', $field))->first()->attr('value');
    }

    /**
     * The chips the calendar draws on the meeting's own day — clusters, which
     * is what a view iterates, not rows.
     *
     * Through the real reader rather than by counting rows, because "two chips"
     * is the claim: the merge is a read-time grouping and the only way to be
     * sure it happened is to ask the thing that does it. Nothing is cleared
     * from the entity manager first — the request under test ran in this same
     * container, so the identity map already holds what it wrote.
     *
     * @return list<OccurrenceCluster>
     */
    private function chipsOnTheDay(): array
    {
        $range = $this->reader->read($this->user, CalendarView::Day, $this->start());

        return array_values(array_filter(
            $range['clusters'],
            static fn (OccurrenceCluster $cluster): bool => self::SHARED_UID === $cluster->primary->event?->uid,
        ));
    }

    /**
     * The syncs the request asked for.
     *
     * Asserted to be the in-memory transport rather than cast to it: a real
     * transport here would make every assertion about what was dispatched
     * vacuously true.
     *
     * @return list<SyncCalendarMessage>
     */
    private function dispatchedSyncs(): array
    {
        $transport = self::getContainer()->get('messenger.transport.ingest');

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $syncs = [];

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if (true === $message instanceof SyncCalendarMessage) {
                $syncs[] = $message;
            }
        }

        return $syncs;
    }

    private function reload(CalendarEvent $event): CalendarEvent
    {
        $reloaded = $this->events->find($event->id);

        self::assertNotNull($reloaded);

        return $reloaded;
    }

    /**
     * Relative to the run, not a literal date: RecurrenceMaterialiser only
     * writes occurrences inside a horizon around now, so a fixed year is a
     * suite that passes until that year leaves the window.
     */
    private function start(): DateTimeImmutable
    {
        return new DateTimeImmutable('tomorrow 09:00', new DateTimeZone('UTC'));
    }

    /**
     * The two calendars this fixture has, which is what "every box ticked"
     * posts now that the control is a checkbox per calendar.
     *
     * @return list<int|null>
     */
    private function bothCalendars(): array
    {
        return [$this->account->id, $this->mirror->id];
    }

    /** @return array{CalendarEvent, CalendarEvent} */
    private function twoCopies(): array
    {
        return [
            $this->copy(self::SHARED_UID, $this->account),
            $this->copy(self::SHARED_UID, $this->mirror),
        ];
    }

    /**
     * The same weekly series on both calendars, under the organiser's one UID.
     *
     * @return array{CalendarEvent, CalendarEvent}
     */
    private function twoSeries(): array
    {
        $weekly = ['@type' => 'RecurrenceRule', 'frequency' => 'weekly', 'count' => 8];

        return [
            $this->copy(self::SHARED_UID, $this->account, $weekly),
            $this->copy(self::SHARED_UID, $this->mirror, $weekly),
        ];
    }

    /** @param array<string,mixed>|null $recurrenceRule */
    private function copy(string $uid, Calendar $calendar, ?array $recurrenceRule = null): CalendarEvent
    {
        $event      = new CalendarEvent();
        $event->uid = $uid;

        $start = $this->start();

        $this->writer->write(
            event:          $event,
            calendar:       $calendar,
            user:           $this->user,
            title:          'Sync',
            startsAt:       $start,
            endsAt:         $start->modify('+1 hour'),
            timeZone:       'UTC',
            recurrenceRule: $recurrenceRule,
        );

        $this->em->flush();

        return $event;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->writer     = $container->get(CalendarEventWriter::class);
        $this->events     = $container->get(CalendarEventRepository::class);
        $this->reader     = $container->get(CalendarRangeReader::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'duplicate-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Duplicate';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $this->em->persist($user);

        $this->account = $this->seedCalendar($user, 'Account', '#2563eb', isDefault: true);
        $this->mirror  = $this->seedCalendar($user, 'Mirror', '#16a34a');

        $this->em->flush();

        $this->user = $user;
        $client->loginUser($user);

        return $client;
    }

    private function seedCalendar(User $user, string $name, string $color, bool $isDefault = false): Calendar
    {
        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = $name;
        $calendar->color     = $color;
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = 'UTC';
        $calendar->isDefault = $isDefault;

        $this->em->persist($calendar);

        return $calendar;
    }
}
