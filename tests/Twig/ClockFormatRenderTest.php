<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The clock preference, asserted where a user would notice it.
 *
 * A resolver test cannot see this. What the setting is *for* is that every time
 * printed anywhere moves together, and the way that fails is a template someone
 * forgot — the user flips the switch, the mail list changes, and the calendar
 * goes on saying "2:30 pm" as though the setting did nothing. So this drives the
 * real calendar page through the real stack and reads the digits off it.
 *
 * Both directions are asserted, and the negative half is the load-bearing one: a
 * page that contains "14:30" would also contain it if the template had hardcoded
 * both. Asserting that the meridiem is absent is what proves the format actually
 * came from the preference.
 */
final class ClockFormatRenderTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventWriter $writer;
    private User $user;
    private Calendar $calendar;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTwentyFourHourDropsTheMeridiemFromTheCalendar(): void
    {
        $client = $this->signIn('24');
        $this->event();

        $client->request('GET', '/calendar/day/' . $this->start()->format('Y-m-d'));

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('14:30', $html);
        self::assertStringNotContainsString('2:30 pm', $html);
    }

    public function testTwelveHourPrintsTheMeridiem(): void
    {
        $client = $this->signIn('12');
        $this->event();

        $client->request('GET', '/calendar/day/' . $this->start()->format('Y-m-d'));

        self::assertResponseIsSuccessful();

        // The chip prints the compact form and carries the full one in its
        // title attribute, which is where the meridiem lives in a dense grid.
        self::assertStringContainsString('2:30 pm', (string) $client->getResponse()->getContent());
    }

    /**
     * The axis, which builds its labels from an hour number rather than from an
     * event — the one place the format is applied to a date nobody stored, and
     * therefore the one most likely to be missed.
     */
    public function testTheTimeGridsGutterFollowsTheSetting(): void
    {
        $client = $this->signIn('12');

        $client->request('GET', '/calendar/day/' . $this->start()->format('Y-m-d'));

        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('9 am', $html);
        self::assertStringContainsString('11 pm', $html, 'the axis runs to the end of the day');
        self::assertStringNotContainsString('>23:00<', $html);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** 14:30 UTC, which is 2:30 pm — a time the two formats cannot both print. */
    private function start(): DateTimeImmutable
    {
        return new DateTimeImmutable('monday next week 14:30', new DateTimeZone('UTC'));
    }

    private function event(): CalendarEvent
    {
        $event = $this->writer->write(
            event:    new CalendarEvent(),
            calendar: $this->calendar,
            user:     $this->user,
            title:    'Clock fixture',
            startsAt: $this->start(),
            endsAt:   $this->start()->modify('+1 hour'),
            timeZone: 'UTC',
        );

        $this->em->flush();

        return $event;
    }

    private function signIn(string $clock): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->writer     = $container->get(CalendarEventWriter::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'clock-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Clock';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        // UTC so the printed digits are the stored ones and the assertions are
        // about the format rather than about an offset.
        $user->timezone  = 'UTC';
        $user->setSetting(User::SETTING_CLOCK, $clock);
        $this->em->persist($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'Clock fixture';
        $calendar->role      = CalendarRole::Default;
        $calendar->isDefault = true;
        $calendar->timeZone  = 'UTC';
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;

        $client->loginUser($user);

        return $client;
    }
}
