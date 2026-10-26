<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A calendar's zone is "follow plMail" unless somebody picks one (#343).
 *
 * The report: change your timezone in settings and the header clock moves but
 * the calendar does not. Every calendar had been stamped with a copy of its
 * owner's zone on the day it was made, and the page was drawn in that copy.
 * The page is the reader's clock now — CalendarTimeGridTest pins that — and
 * these pin the other half: what a calendar's own zone still decides, and how
 * it is picked.
 *
 * As requests, because the form, the list and the editor each read the column
 * and each has to understand null.
 */
final class CalendarZoneFollowTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Calendar $calendar;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** "Follow plMail" is the empty option, and saving it stores null. */
    public function testANewCalendarFollowsPlMailByDefault(): void
    {
        $client = $this->signIn();

        $crawler = $client->request('GET', '/settings/calendars/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();

        self::assertSame('', $form['calendar[timeZone]']->getValue(), 'the empty option is the one chosen');
        self::assertStringContainsString('Asia/Tokyo', $crawler->filter('form')->text(), 'the help names what following means today');

        $form['calendar[name]'] = 'Following';
        $client->submit($form);

        self::assertNull($this->calendarNamed('Following')->timeZone);
    }

    /** Picking a zone stores it, and picking "Follow plMail" again clears it. */
    public function testAPickedZoneIsStoredAndCanBeCleared(): void
    {
        $client = $this->signIn();

        $this->edit($client, 'America/New_York');
        self::assertSame('America/New_York', $this->reload()->timeZone);

        $this->edit($client, '');
        self::assertNull($this->reload()->timeZone);
    }

    /** The list says "follows" rather than printing a zone it is not pinned to. */
    public function testTheListSaysAFollowingCalendarFollows(): void
    {
        $client = $this->signIn();

        $crawler = $client->request('GET', '/settings?section=calendars');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Follows plMail', $crawler->filter('body')->text());
    }

    /**
     * A new event on a following calendar starts in the owner's zone, read
     * from wherever their zone is NOW — the copy taken at creation is what the
     * report was.
     */
    public function testANewEventOnAFollowingCalendarOpensInTheOwnersCurrentZone(): void
    {
        $client = $this->signIn();

        $this->user->timezone = 'America/Los_Angeles';
        $this->em->flush();

        $crawler = $client->request('GET', '/calendar/event/new?start=2026-11-02 14:00');

        self::assertResponseIsSuccessful();
        self::assertSame('America/Los_Angeles', $crawler->filter('input[name="timeZone"]')->attr('value'));
        self::assertSame('2026-11-02T14:00', $crawler->filter('input[name="startsAt"]')->attr('value'));
    }

    /**
     * A calendar with a zone picked writes new events in it. The slot was
     * clicked on the reader's clock, so it opens at the same MOMENT in the
     * calendar's zone: 14:00 in Tokyo is 06:00 in Berlin in November.
     */
    public function testANewEventOnAPinnedCalendarOpensAtTheClickedMomentInItsZone(): void
    {
        $client = $this->signIn();

        $this->calendar->timeZone = 'Europe/Berlin';
        $this->em->flush();

        $crawler = $client->request('GET', '/calendar/event/new?start=2026-11-02 14:00');

        $clicked = new DateTimeImmutable('2026-11-02 14:00', new DateTimeZone('Asia/Tokyo'));

        self::assertResponseIsSuccessful();
        self::assertSame('Europe/Berlin', $crawler->filter('input[name="timeZone"]')->attr('value'));
        self::assertSame(
            $clicked->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i'),
            $crawler->filter('input[name="startsAt"]')->attr('value'),
        );
    }

    private function edit(KernelBrowser $client, string $zone): void
    {
        $crawler = $client->request('GET', '/settings/calendars/' . $this->calendar->id . '/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['calendar[timeZone]'] = $zone;
        $client->submit($form);

        self::assertResponseIsSuccessful();
    }

    private function reload(): Calendar
    {
        $this->em->clear();

        $calendar = $this->em->find(Calendar::class, $this->calendar->id);
        self::assertInstanceOf(Calendar::class, $calendar);

        return $calendar;
    }

    private function calendarNamed(string $name): Calendar
    {
        $this->em->clear();

        $calendar = static::getContainer()->get(CalendarRepository::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Calendar::class, $calendar);

        return $calendar;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        // Never committed, so the suite leaves nothing behind.
        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'zonefollow-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Zone';
        $user->nameLast  = 'Follow';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        // Far from the install default, so following it cannot be mistaken
        // for following the server.
        $user->timezone  = 'Asia/Tokyo';
        $this->em->persist($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'Zone fixture';
        $calendar->role      = CalendarRole::Default;
        $calendar->isDefault = true;
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;

        $client->loginUser($user);

        return $client;
    }
}
