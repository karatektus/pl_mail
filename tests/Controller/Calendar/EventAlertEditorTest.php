<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Setting a reminder in the editor, and taking it off again.
 *
 * The save path is where the interesting failures are, and none of them are
 * visible from a service test:
 *
 *   **Unticking the last box has to mean something.** A writer that read "no
 *   alerts stated" as "keep what you have" — which is exactly what it does for
 *   every other caller, because extraction and sync must not strip a reminder —
 *   would make an alert impossible to remove through the only UI that can set
 *   one.
 *
 *   **A checkbox value is editable by whoever submits it.** The form posts a
 *   key rather than an offset, and a key nobody rendered has to resolve to
 *   nothing rather than to an alert of the poster's choosing.
 *
 *   **A save must not lose an alarm the form cannot draw.** An event mirrored
 *   from a CalDAV server can carry a trigger at an absolute instant, which no
 *   control in this editor can express. It is listed as its own ticked box and
 *   survives; that is the reason the value is a key.
 *
 * Written as requests rather than against the controller, because the list the
 * editor renders and the list the save resolves against have to be the same
 * list, and only a request exercises both.
 */
final class EventAlertEditorTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventWriter $writer;
    private CalendarEventRepository $events;
    private User $user;
    private Calendar $calendar;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** The editor offers the six one-click choices on a brand-new event. */
    public function testTheEditorOffersTheCommonRemindersOnANewEvent(): void
    {
        $client = $this->signIn();

        $crawler = $client->request('GET', '/calendar/event/new');

        self::assertResponseIsSuccessful();
        self::assertCount(
            6,
            $crawler->filter('input[name="alerts[]"]'),
            'at the time, 5, 10 and 30 minutes, an hour and a day',
        );
        self::assertCount(
            0,
            $crawler->filter('input[name="alerts[]"][checked]'),
            'a new event has no reminders until somebody asks for one',
        );
    }

    public function testTickingACommonReminderStoresItAsAJsCalendarAlert(): void
    {
        $client = $this->signIn();
        $event  = $this->oneOff();

        $this->save($client, $event, ['display/-PT10M']);

        $alerts = $this->reload($event)->jscalendar['alerts'];

        self::assertSame(['display/-PT10M'], array_keys($alerts));
        self::assertSame(
            ['@type' => 'OffsetTrigger', 'offset' => '-PT10M'],
            $alerts['display/-PT10M']['trigger'],
        );
        self::assertSame('display', $alerts['display/-PT10M']['action']);
    }

    /** And the editor draws it back, ticked, next time it is opened. */
    public function testAStoredReminderComesBackTicked(): void
    {
        $client = $this->signIn();
        $event  = $this->oneOff();

        $this->save($client, $event, ['display/-PT10M']);

        $crawler = $client->request('GET', '/calendar/event/' . $event->id . '/edit');

        self::assertCount(1, $crawler->filter('input[name="alerts[]"][checked]'));
        self::assertSame(
            'display/-PT10M',
            $crawler->filter('input[name="alerts[]"][checked]')->attr('value'),
        );
    }

    /**
     * The removal case. Without alerts being stated on every save this passes
     * only by accident, and only until somebody sets one.
     */
    public function testUntickingEveryBoxRemovesTheReminder(): void
    {
        $client = $this->signIn();
        $event  = $this->oneOff();

        $this->save($client, $event, ['display/-PT10M']);
        $this->save($client, $event, []);

        self::assertArrayNotHasKey(
            'alerts',
            $this->reload($event)->jscalendar,
            'an empty map is not stored either — see CalendarEventWriter',
        );
    }

    /** The custom field is the general case, and it comes back as a ticked box. */
    public function testAnArbitraryNumberOfMinutesBecomesAReminderOfItsOwn(): void
    {
        $client = $this->signIn();
        $event  = $this->oneOff();

        $this->save($client, $event, [], customMinutes: 45);

        self::assertSame(['display/-PT45M'], array_keys($this->reload($event)->jscalendar['alerts']));

        $crawler = $client->request('GET', '/calendar/event/' . $event->id . '/edit');

        self::assertCount(
            7,
            $crawler->filter('input[name="alerts[]"]'),
            'the six common ones, plus the one that was typed',
        );
    }

    /** An email reminder is a different alert at the same offset, not the same one. */
    public function testAnEmailReminderIsStoredAsItsOwnAlert(): void
    {
        $client = $this->signIn();
        $event  = $this->oneOff();

        $this->save($client, $event, ['display/-PT10M'], customMinutes: 10, customAction: 'email');

        $keys = array_keys($this->reload($event)->jscalendar['alerts']);

        // Sorted before comparing: jsonb stores an object's keys in its own
        // order (shortest first, then bytewise), so the order they went in with
        // is not the order they come back in. Nothing depends on it — the
        // editor's list is ordered by AlertReader::COMMON_OFFSETS — but a test
        // that asserted insertion order would fail for a reason that has
        // nothing to do with alerts.
        sort($keys);

        self::assertSame(['display/-PT10M', 'email/-PT10M'], $keys);
    }

    /**
     * A crafted post can untick things. It must not be able to invent one.
     */
    public function testAKeyTheEditorNeverRenderedIsIgnored(): void
    {
        $client = $this->signIn();
        $event  = $this->oneOff();

        $this->save($client, $event, ['email/-P365D']);

        self::assertArrayNotHasKey('alerts', $this->reload($event)->jscalendar);
    }

    /**
     * An alarm from elsewhere survives a save that had no way to describe it.
     *
     * This is the regression the key-based value exists for: a form posting
     * offsets would drop an absolute trigger on the first edit, and the reminder
     * somebody set in Thunderbird would be gone.
     */
    public function testAnImportedAlarmTheFormCannotExpressSurvivesASave(): void
    {
        $client = $this->signIn();
        $event  = $this->oneOff();

        $event->jscalendar = $event->jscalendar + [
            'alerts' => [
                'imported' => [
                    '@type'   => 'Alert',
                    'trigger' => ['@type' => 'AbsoluteTrigger', 'when' => '2026-06-02T08:30:00Z'],
                    'action'  => 'display',
                ],
            ],
        ];
        $this->em->flush();

        $this->save($client, $event, ['imported']);

        self::assertSame(['imported'], array_keys($this->reload($event)->jscalendar['alerts']));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @param list<string> $alertKeys
     */
    private function save(
        KernelBrowser $client,
        CalendarEvent $event,
        array         $alertKeys,
        ?int          $customMinutes = null,
        string        $customAction = 'display',
    ): void {
        $client->request('POST', '/calendar/event/save', [
            '_token'             => $this->token($client),
            'eventId'            => $event->id,
            // Calendar ids, which is what the editor's one calendar control
            // posts. A save that names none is refused, so this is not
            // boilerplate — it is the statement "write the copy on this
            // calendar" that every save has to make.
            'calendars'          => [(string) $this->calendar->id],
            'title'              => 'Standup',
            'timeZone'           => 'UTC',
            'startsAt'           => $this->start()->format('Y-m-d\TH:i:s'),
            'endsAt'             => $this->start()->modify('+30 minutes')->format('Y-m-d\TH:i:s'),
            'alerts'             => $alertKeys,
            'alertCustomMinutes' => null === $customMinutes ? '' : (string) $customMinutes,
            'alertCustomAction'  => $customAction,
        ]);

        self::assertResponseRedirects();

        // The controller wrote through the ORM in a different request; without
        // this the assertions read the instance this test still holds.
        $this->em->clear();
    }

    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/calendar/event/new');

        return (string) $crawler
            ->filter('form[action$="/calendar/event/save"] input[name="_token"]')
            ->first()
            ->attr('value');
    }

    private function reload(CalendarEvent $event): CalendarEvent
    {
        $reloaded = $this->events->find((int) $event->id);

        self::assertNotNull($reloaded);

        return $reloaded;
    }

    /**
     * Relative to the run rather than a literal date, for the reason every
     * calendar test here is: occurrences only exist inside a horizon around now.
     */
    private function start(): DateTimeImmutable
    {
        return new DateTimeImmutable('monday next week 09:00', new DateTimeZone('UTC'));
    }

    private function oneOff(): CalendarEvent
    {
        $event = $this->writer->write(
            event:    new CalendarEvent(),
            calendar: $this->calendar,
            user:     $this->user,
            title:    'Standup',
            startsAt: $this->start(),
            endsAt:   $this->start()->modify('+30 minutes'),
            timeZone: 'UTC',
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

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'alerteditor-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Alert';
        $user->nameLast  = 'Editor';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $this->em->persist($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'Alert editor fixture';
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = 'UTC';
        $calendar->isDefault = true;
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;

        $client->loginUser($user);

        return $client;
    }
}
