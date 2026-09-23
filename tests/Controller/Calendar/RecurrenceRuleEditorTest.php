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
 * Saving a series from the editor must not flatten a rule the dropdown cannot
 * show.
 *
 * The dropdown says a bare frequency, and the save used to take what it posted
 * as the new rule — so correcting the title of "every other Tuesday and
 * Thursday, ten times" turned it into "every week, forever", and pushed that to
 * the remote. Written as requests, because the claim is about the round trip:
 * what the editor selects when it opens, posted back unchanged.
 */
final class RecurrenceRuleEditorTest extends WebTestCase
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

    public function testRenamingASeriesKeepsARuleTheDropdownCannotShow(): void
    {
        $client = $this->signIn();
        $rule   = [
            '@type'     => 'RecurrenceRule',
            'frequency' => 'weekly',
            'interval'  => 2,
            'byDay'     => [['@type' => 'NDay', 'day' => 'tu'], ['@type' => 'NDay', 'day' => 'th']],
            'count'     => 10,
        ];

        $start = new DateTimeImmutable('tuesday next week 09:00', new DateTimeZone('UTC'));
        $event = static::getContainer()->get(CalendarEventWriter::class)->write(
            event:          new CalendarEvent(),
            calendar:       $this->calendar,
            user:           $this->user,
            title:          'Planning',
            startsAt:       $start,
            endsAt:         $start->modify('+1 hour'),
            timeZone:       'UTC',
            recurrenceRule: $rule,
        );
        // A property no form field carries, which the rebuild used to erase.
        $event->jscalendar = $event->jscalendar + ['freeBusyStatus' => 'free'];
        $this->em->flush();

        $crawler = $client->request('GET', '/calendar/event/' . $event->id . '/edit');
        $repeat  = (string) $crawler->filter('select[name="repeat"] option[selected]')->attr('value');

        self::assertSame('keep', $repeat, 'the editor offers to keep a rule it cannot express');

        $client->request('POST', '/calendar/event/save', [
            '_token'    => (string) $crawler->filter('form[action$="/calendar/event/save"] input[name="_token"]')->first()->attr('value'),
            'eventId'   => $event->id,
            'calendars' => [(string) $this->calendar->id],
            'title'     => 'Planning (renamed)',
            'timeZone'  => 'UTC',
            'startsAt'  => $start->format('Y-m-d\TH:i:s'),
            'endsAt'    => $start->modify('+1 hour')->format('Y-m-d\TH:i:s'),
            'repeat'    => $repeat,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();

        $saved = static::getContainer()->get(CalendarEventRepository::class)->find((int) $event->id);

        self::assertNotNull($saved);
        self::assertSame('Planning (renamed)', $saved->title);
        // Equals, not Same: jsonb hands an object's keys back in its own order.
        self::assertEquals($rule, $saved->jscalendar['recurrenceRules'][0]);
        self::assertSame('free', $saved->jscalendar['freeBusyStatus'] ?? null);
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
        $user->email     = 'ruleeditor-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Rule';
        $user->nameLast  = 'Editor';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $this->em->persist($user);

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'Rule editor fixture';
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
