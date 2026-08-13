<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * A saved event is an event the user can then SEE, or is one they were told
 * they cannot.
 *
 * This is the test the suite did not have, and its absence is why "new events
 * save silently and then do not exist" survived a green run. There was already
 * a spec creating an event and asserting a chip for it appears, and it passed
 * throughout — because it ran against a freshly provisioned fixture whose one
 * calendar is visible and holds the default flag. The bug lived entirely in the
 * states that fixture never reaches.
 *
 * The mechanism, stated because every assertion below is about one half of it.
 * The editor lists `findForUser()` — every calendar, hidden ones included, and
 * deliberately so: a copy of a meeting on a hidden calendar is still a fact
 * about that meeting. Every VIEW reads `findVisibleForUser()`. Where a save
 * landed on a calendar that is in the first list and not the second, the row was
 * written, the response was a 302, the dialog closed on `turbo:submit-end`
 * because the submit had genuinely succeeded, and the event was absent from the
 * week, the month and the agenda for as long as the calendar stayed hidden.
 * There was no error to show because nothing had gone wrong; there was simply
 * nowhere for the result to appear.
 *
 * So an endpoint test that asserts 302 proves nothing here, and neither does one
 * that asserts the row exists. Both were true throughout the defect. What has to
 * be asserted is that the event RENDERS afterwards — which is what
 * assertRendersOnEveryView() does, on all three views, through real requests.
 */
final class CalendarSaveVisibilityTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventRepository $events;
    private User $user;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The whole defect, as one test.
     *
     * A hidden calendar first in sort order, a visible one after it, and NO
     * default flag anywhere. That last part is what made the bug reachable and
     * is not a contrived fixture: the flag is per-user, nothing re-asserts it,
     * and an account arrives here by having its default calendar deleted or by
     * predating the flag. It is also the same state that had these accounts
     * drawing the grid in UTC (see CalendarTimeResolverTest), which is how the
     * two halves of this bug report turned out to be one install.
     *
     * With no flag to prefer, landingCalendar() fell through to "the first
     * writable calendar" — the hidden one — and the editor opened with it
     * ticked. The save then wrote a perfectly good row onto a calendar no view
     * reads, answered 302, and closed the dialog. Every symptom of a save that
     * did nothing, from a save that did exactly what it was told.
     *
     * The settings guard that refuses to hide the default calendar cannot cover
     * this, because it is written in terms of the flag that is missing.
     */
    public function testAnEventSavedFromTheEditorIsVisibleOnEveryViewAfterwards(): void
    {
        $client = $this->signIn();

        $this->calendar('Archive (hidden)', isVisible: false, sortOrder: 0);
        $this->calendar('Personal', isVisible: true, sortOrder: 1);
        $this->em->flush();

        $this->save($client, 'Visible check');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertNotNull($this->events->findOneBy(['title' => 'Visible check']));

        $this->assertRendersOnEveryView($client, 'Visible check');
    }

    /**
     * Preferring a visible calendar must not have demoted the default one.
     *
     * Sort order puts the hidden calendar first and the visible non-default
     * second, so a fix that merely skipped hidden calendars would land here on
     * "Scratch" rather than on the calendar the user nominated.
     */
    public function testTheDefaultCalendarStillWinsOverMereVisibility(): void
    {
        $client = $this->signIn();

        $this->calendar('Archive (hidden)', isVisible: false, sortOrder: 0);
        $this->calendar('Scratch', isVisible: true, sortOrder: 1);
        $this->calendar('Personal', isVisible: true, sortOrder: 2, isDefault: true);
        $this->em->flush();

        $this->save($client, 'Default wins');

        $saved = $this->events->findOneBy(['title' => 'Default wins']);

        self::assertNotNull($saved);
        self::assertSame('Personal', $saved->calendar?->name);
    }

    /**
     * A read-only calendar is never the landing spot, and a hidden one must not
     * become the landing spot by being the only thing left that is writable.
     * Here the visible calendar takes no writes, so the choice is between a
     * hidden calendar and nothing — and the event has to go somewhere.
     */
    public function testAHiddenCalendarIsStillUsedWhenNothingVisibleAcceptsWrites(): void
    {
        $client = $this->signIn();

        $this->calendar('Mirror', isVisible: true, sortOrder: 0, isReadOnly: true);
        $this->calendar('Archive (hidden)', isVisible: false, sortOrder: 1);
        $this->em->flush();

        $this->save($client, 'Last resort');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

        $saved = $this->events->findOneBy(['title' => 'Last resort']);

        self::assertNotNull($saved);
        self::assertSame('Archive (hidden)', $saved->calendar?->name);
    }

    /**
     * And when it does land somewhere unseeable, the user is TOLD — by name.
     *
     * This is the half that makes the case above survivable rather than merely
     * defined. Silence is what turned a working save into a bug report; a
     * sentence naming the calendar turns it into a setting the user can change.
     * Asserted on the flash bag rather than on rendered markup so the claim is
     * about what the action decided, not about where the layout puts toasts.
     */
    public function testASaveOntoAHiddenCalendarSaysSoInsteadOfSucceedingSilently(): void
    {
        $client = $this->signIn();

        $this->calendar('Archive (hidden)', isVisible: false, sortOrder: 0);
        $this->em->flush();

        $this->save($client, 'Announced');

        $flashes = $this->flashes($client);

        self::assertSame([], $flashes->peek('success'), 'a save nobody can see is not a plain success');

        $info = $flashes->peek('info');

        self::assertCount(1, $info);
        self::assertStringContainsString('Archive (hidden)', (string) $info[0]);
    }

    /** An ordinary save confirms itself, so "nothing happened" is never the UI. */
    public function testAnOrdinarySaveConfirmsItself(): void
    {
        $client = $this->signIn();

        $this->calendar('Personal', isVisible: true, sortOrder: 0, isDefault: true);
        $this->em->flush();

        $this->save($client, 'Confirmed');

        self::assertCount(1, $this->flashes($client)->peek('success'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * The flash bag, narrowed from SessionInterface — which does not declare
     * getFlashBag(), because a session need not have one. The test session
     * always does.
     */
    private function flashes(KernelBrowser $client): FlashBagInterface
    {
        $session = $client->getRequest()->getSession();

        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);

        return $session->getFlashBag();
    }

    /**
     * The event as the browser renders it, on each view in turn.
     *
     * Three views and not one because they read the occurrences through
     * different shapes — a positioned grid, a month cell, a flat list — and the
     * defect was in what they all share underneath.
     */
    private function assertRendersOnEveryView(KernelBrowser $client, string $title): void
    {
        foreach (['week', 'month', 'agenda'] as $view) {
            $crawler = $client->request('GET', '/calendar/' . $view . '/' . $this->day());

            self::assertResponseIsSuccessful();
            self::assertGreaterThan(
                0,
                $crawler->filter(sprintf('[title*="%s"], [aria-label*="%s"]', $title, $title))->count()
                    + substr_count($crawler->html(), $title),
                sprintf('"%s" should be on the %s view after being saved', $title, $view),
            );
        }
    }

    /**
     * Post the editor's form exactly as the browser posts it, token and all.
     *
     * Through a real request rather than by calling the writer, because the
     * decision under test — which calendar the editor ticks — is made while
     * RENDERING the editor and travels to the save as a posted field. Calling
     * the writer would supply the answer the test is trying to check.
     */
    private function save(KernelBrowser $client, string $title): void
    {
        $crawler = $client->request('GET', '/calendar/event/new');

        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/calendar/event/save"]')->first();

        $ticked = $crawler
            ->filter('form input[name="calendars[]"][checked]')
            ->each(static fn ($node): string => (string) $node->attr('value'));

        self::assertNotSame([], $ticked, 'the editor must open with somewhere for the event to go');

        $client->request('POST', '/calendar/event/save', [
            '_token'    => (string) $form->filter('input[name="_token"]')->attr('value'),
            'eventId'   => 0,
            'timeZone'  => (string) $form->filter('input[name="timeZone"]')->attr('value'),
            'view'      => 'week',
            'title'     => $title,
            'startsAt'  => $this->day() . 'T15:00',
            'endsAt'    => $this->day() . 'T16:00',
            'calendars' => $ticked,
            'repeat'    => 'none',
        ]);
    }

    /**
     * Tomorrow, not a literal date: RecurrenceMaterialiser only writes
     * occurrences inside a horizon around now, and a fixed date is a test that
     * passes until it leaves that window. Tomorrow is inside every view's range
     * — the week, the month grid and the agenda's thirty days — whichever day
     * this runs on, which a date later in the week would not be.
     */
    private function day(): string
    {
        return (new DateTimeImmutable('tomorrow', new DateTimeZone('UTC')))->format('Y-m-d');
    }

    private function calendar(
        string $name,
        bool   $isVisible,
        int    $sortOrder,
        bool   $isDefault = false,
        bool   $isReadOnly = false,
    ): Calendar {
        $calendar             = new Calendar();
        $calendar->usr        = $this->user;
        $calendar->name       = $name;
        $calendar->role       = CalendarRole::Custom;
        $calendar->timeZone   = 'Europe/Berlin';
        $calendar->isVisible  = $isVisible;
        $calendar->isDefault  = $isDefault;
        $calendar->isReadOnly = $isReadOnly;
        $calendar->sortOrder  = $sortOrder;

        $this->em->persist($calendar);

        return $calendar;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->events     = $container->get(CalendarEventRepository::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'save-visibility-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Save';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $this->em->persist($user);
        $this->em->flush();

        $this->user = $user;

        $client->loginUser($user);

        return $client;
    }
}
