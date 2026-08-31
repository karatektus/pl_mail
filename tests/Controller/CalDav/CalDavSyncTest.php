<?php

declare(strict_types=1);

namespace App\Tests\Controller\CalDav;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\ApiToken;
use App\Entity\User\User;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * sync-collection, which is the reason the change log was built first.
 *
 * Without it a client polls a full collection listing on every check and diffs
 * it locally; with it, a sync costs one request and the rows that moved. The
 * claims worth pinning are the ones that make a client trust the token: that a
 * delta contains what changed and nothing else, that a deletion arrives as a
 * 404 for an href the client still holds, and that a token this server cannot
 * place is refused rather than answered with silence.
 *
 * The refusal is the one that protects users. An empty delta for an unplaceable
 * token reads to a client as "nothing changed", and it would go on believing
 * that forever.
 */
final class CalDavSyncTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventWriter $writer;
    private User $user;
    private string $secret;
    private Calendar $calendar;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnInitialSyncReturnsEverythingAndAToken(): void
    {
        $client = $this->boot();
        $event  = $this->seedEvent('Kickoff');

        $body = $this->sync($client, null);

        self::assertStringContainsString(rawurlencode($event->uid) . '.ics', $body);
        self::assertNotSame('', $this->tokenIn($body), 'an initial sync must hand back a token');
    }

    /** The whole point: a second sync carries the change and nothing else. */
    public function testASecondSyncCarriesOnlyWhatChanged(): void
    {
        $client = $this->boot();
        $this->seedEvent('Already known');

        $token = $this->tokenIn($this->sync($client, null));

        $fresh = $this->seedEvent('Added later');

        $body = $this->sync($client, $token);

        self::assertStringContainsString(rawurlencode($fresh->uid) . '.ics', $body);
        self::assertStringNotContainsString('Already known', $body);
        self::assertSame(1, substr_count($body, '<d:response>'), 'only the new resource should be reported');
    }

    public function testADeletionArrivesAsA404ForTheHrefTheClientHolds(): void
    {
        $client = $this->boot();
        $event  = $this->seedEvent('Doomed');
        $name   = rawurlencode($event->uid) . '.ics';

        $token = $this->tokenIn($this->sync($client, null));

        $this->em->remove($event);
        $this->em->flush();

        $body = $this->sync($client, $token);

        self::assertStringContainsString($name, $body, 'the tombstone must still name the resource');
        self::assertStringContainsString('404 Not Found', $body);
    }

    public function testAnUnplaceableTokenIsRefusedRatherThanAnsweredWithSilence(): void
    {
        $client = $this->boot();
        $this->seedEvent('Kickoff');

        $client->request('REPORT', $this->collection(), [], [], [
            'HTTP_AUTHORIZATION' => $this->basic(),
            'CONTENT_TYPE'       => 'application/xml',
        ], $this->syncBody('not-a-sequence'));

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('valid-sync-token', (string) $client->getResponse()->getContent());
    }

    public function testMultigetReturnsTheCalendarDataForNamedResources(): void
    {
        $client = $this->boot();
        $event  = $this->seedEvent('Kickoff');
        $href   = $this->collection() . rawurlencode($event->uid) . '.ics';

        $xml = <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
              <d:prop><d:getetag/><c:calendar-data/></d:prop>
              <d:href>{$href}</d:href>
            </c:calendar-multiget>
            XML;

        $client->request('REPORT', $this->collection(), [], [], [
            'HTTP_AUTHORIZATION' => $this->basic(),
            'CONTENT_TYPE'       => 'application/xml',
        ], $xml);

        self::assertSame(207, $client->getResponse()->getStatusCode());

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('BEGIN:VEVENT', $body, 'calendar-data was asked for');
        self::assertStringContainsString($event->uid, $body);
    }

    /** Asking only for ETags must not pay to serialise every event. */
    public function testASyncWithoutCalendarDataCarriesOnlyEtags(): void
    {
        $client = $this->boot();
        $this->seedEvent('Kickoff');

        $body = $this->sync($client, null);

        self::assertStringContainsString('getetag', $body);
        self::assertStringNotContainsString('BEGIN:VEVENT', $body);
    }

    // ---------------------------------------------------------------- helpers

    private function sync(KernelBrowser $client, ?string $token): string
    {
        $client->request('REPORT', $this->collection(), [], [], [
            'HTTP_AUTHORIZATION' => $this->basic(),
            'CONTENT_TYPE'       => 'application/xml',
        ], $this->syncBody($token));

        self::assertSame(207, $client->getResponse()->getStatusCode());

        return (string) $client->getResponse()->getContent();
    }

    private function syncBody(?string $token): string
    {
        return <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <d:sync-collection xmlns:d="DAV:">
              <d:sync-token>{$token}</d:sync-token>
              <d:sync-level>1</d:sync-level>
              <d:prop><d:getetag/></d:prop>
            </d:sync-collection>
            XML;
    }

    private function tokenIn(string $body): string
    {
        self::assertSame(1, preg_match('#<d:sync-token>([^<]*)</d:sync-token>#', $body, $m), 'no sync-token in the answer');

        return $m[1];
    }

    private function collection(): string
    {
        return sprintf('/caldav/calendars/%d/%d/', $this->user->id, $this->calendar->id);
    }

    private function basic(): string
    {
        return 'Basic ' . base64_encode($this->user->email . ':' . $this->secret);
    }

    private function seedEvent(string $title): CalendarEvent
    {
        $event      = new CalendarEvent();
        $event->uid = 'sync-' . uniqid('', true) . '@plmail.test';

        $this->writer->write(
            event: $event,
            calendar: $this->calendar,
            user: $this->user,
            title: $title,
            startsAt: new DateTimeImmutable('+3 days 09:00'),
            endsAt: new DateTimeImmutable('+3 days 10:00'),
            timeZone: 'UTC',
        );

        $this->em->flush();

        return $event;
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->writer     = $container->get(CalendarEventWriter::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'caldavsync-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Sync';
        $user->nameLast  = 'Tester';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $minted       = ApiToken::create($user, 'CalDAV sync test');
        $this->secret = $minted['secret'];
        $this->em->persist($minted['token']);

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->name     = 'Work';
        $calendar->role     = CalendarRole::Custom;
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);
        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;

        return $client;
    }
}
