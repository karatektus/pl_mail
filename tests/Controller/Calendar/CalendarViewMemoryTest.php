<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\CalendarView;
use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The calendar opens on the view you last chose, and each shape remembers its
 * own.
 *
 * WHY THE TWO SHAPES ARE SEPARATE, which is the part worth pinning rather than
 * the remembering itself. The full page has always opened on Week and the
 * docked pane on Agenda, because seven columns in a 380px strip are seven
 * slivers — CalendarView::minimumPaneWidth() goes as far as WIDENING the pane
 * for a view that needs the room. One remembered view for both would carry a
 * choice made on a full-width page into the strip and widen it on open, which
 * is a thing happening to somebody rather than a thing they did.
 *
 * Written as requests rather than against the entity, because the claim spans
 * three things that have to agree: the route records, the settings bag stores,
 * and the next visit reads. Asserting on the property alone would pass against
 * a controller that never called it.
 */
final class CalendarViewMemoryTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private int $userId;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** Nobody's calendar moves until they move it. */
    public function testTheDefaultsAreWhatEachShapeAlwaysOpenedOn(): void
    {
        $this->signIn();

        self::assertSame(CalendarView::Week, $this->stored()->calendarView);
        self::assertSame(CalendarView::Agenda, $this->stored()->calendarPaneView);
    }

    public function testThePageOpensOnTheViewLastChosenThere(): void
    {
        $client = $this->signIn();

        $client->request('GET', '/calendar/month');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/calendar');

        self::assertResponseIsSuccessful();
        self::assertSame(CalendarView::Month, $this->stored()->calendarView);
    }

    /**
     * And the pane's choice stays the pane's. A month picked on the page must
     * not reach a strip that would have to widen itself to draw it.
     */
    public function testTheTwoShapesRememberSeparately(): void
    {
        $client = $this->signIn();

        $client->request('GET', '/calendar/month');
        $client->request('GET', '/calendar/day?pane=1');

        self::assertSame(CalendarView::Month, $this->stored()->calendarView, 'the page kept its own');
        self::assertSame(CalendarView::Day, $this->stored()->calendarPaneView, 'the pane kept its own');
    }

    /**
     * A value the settings bag cannot make sense of opens on the default rather
     * than failing to render.
     *
     * The bag is JSON, and a config restore or an older build can put anything
     * in it. `from` would throw here and take the whole calendar down with it.
     */
    public function testAnUnrecognisedStoredViewFallsBackRatherThanThrowing(): void
    {
        $client = $this->signIn();

        $this->user->setSetting(User::SETTING_CALENDAR_VIEW, 'fortnight');
        $this->em->flush();

        self::assertSame(CalendarView::Week, $this->stored()->calendarView);

        $client->request('GET', '/calendar');
        self::assertResponseIsSuccessful();
    }

    // ── fixture ───────────────────────────────────────────────────────────

    /**
     * The user as the database now has them.
     *
     * Read back rather than asserted on the instance this test holds, and that
     * is not caution. A request resolves its own User through the security
     * token, so the object the controller writes to is not necessarily the one
     * here — the first assertion written this way passed by luck and the second
     * failed, which is a fixture telling you it is measuring the wrong thing.
     * What the feature promises is that the NEXT visit sees the choice, and the
     * next visit reads the database.
     */
    private function stored(): User
    {
        // Re-fetched by id rather than refreshed. A request detaches the
        // instance this test is holding — the kernel clears the manager between
        // them even with reboot disabled — so refresh() throws "not managed",
        // and asserting on the stale object measures what the test did rather
        // than what the request stored.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $user = $em->getRepository(User::class)->find($this->userId);

        self::assertNotNull($user, 'the fixture user disappeared');

        return $user;
    }



    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        // The kernel is kept between requests so the transaction below survives
        // them — without it Symfony reboots, the connection goes with it, and
        // this test's user is committed for the rest of the suite to find.
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'calview-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'View';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $this->em->persist($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'View fixture';
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = 'UTC';
        $calendar->isDefault = true;
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user   = $user;
        $this->userId = (int) $user->id;

        $client->loginUser($user);

        return $client;
    }
}
