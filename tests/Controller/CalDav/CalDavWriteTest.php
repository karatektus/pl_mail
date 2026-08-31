<?php

declare(strict_types=1);

namespace App\Tests\Controller\CalDav;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\User\ApiToken;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Writing over CalDAV, and refusing to when refusing is the right answer.
 *
 * Two of these are about not losing data. A read-only calendar is a mirror of
 * somewhere that does not accept writes back, so a PUT that appeared to succeed
 * would show the user an edit that silently never leaves plMail. And If-Match
 * is what stands between two clients editing the same event: without the
 * precondition the second write wins and the first is gone with nothing raised.
 */
final class CalDavWriteTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
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

    public function testPuttingANewEventCreatesItAndItReadsBack(): void
    {
        $client = $this->boot();
        $uid    = 'put-' . uniqid('', true) . '@plmail.test';

        $this->put($client, $uid, $this->ics($uid, 'Kickoff'));

        self::assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('GET', $this->href($uid), [], [], ['HTTP_AUTHORIZATION' => $this->basic()]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Kickoff', (string) $client->getResponse()->getContent());
    }

    public function testPuttingOverAnExistingEventUpdatesItRatherThanDuplicating(): void
    {
        $client = $this->boot();
        $uid    = 'put-' . uniqid('', true) . '@plmail.test';

        $this->put($client, $uid, $this->ics($uid, 'First'));
        $this->put($client, $uid, $this->ics($uid, 'Second'));

        self::assertSame(204, $client->getResponse()->getStatusCode(), 'an update is 204, not 201');

        $client->request('GET', $this->href($uid), [], [], ['HTTP_AUTHORIZATION' => $this->basic()]);

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Second', $body);
        self::assertStringNotContainsString('First', $body);
    }

    /** The lost-update guard: a stale If-Match must not be allowed to win. */
    public function testAStaleIfMatchIsRefused(): void
    {
        $client = $this->boot();
        $uid    = 'put-' . uniqid('', true) . '@plmail.test';

        $this->put($client, $uid, $this->ics($uid, 'First'));

        $client->request('PUT', $this->href($uid), [], [], [
            'HTTP_AUTHORIZATION' => $this->basic(),
            'CONTENT_TYPE'       => 'text/calendar',
            'HTTP_IF_MATCH'      => '"1-999999"',
        ], $this->ics($uid, 'Overwrite'));

        self::assertSame(412, $client->getResponse()->getStatusCode());
    }

    public function testDeletingRemovesTheResource(): void
    {
        $client = $this->boot();
        $uid    = 'put-' . uniqid('', true) . '@plmail.test';

        $this->put($client, $uid, $this->ics($uid, 'Doomed'));

        $client->request('DELETE', $this->href($uid), [], [], ['HTTP_AUTHORIZATION' => $this->basic()]);

        self::assertSame(204, $client->getResponse()->getStatusCode());

        $client->request('GET', $this->href($uid), [], [], ['HTTP_AUTHORIZATION' => $this->basic()]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * A mirror that cannot accept writes must say so, or the user makes an edit
     * that never reaches the calendar they think they are editing.
     */
    public function testAReadOnlyCalendarRefusesAPut(): void
    {
        $client = $this->boot();

        $this->calendar->isReadOnly = true;
        $this->em->flush();

        $uid = 'put-' . uniqid('', true) . '@plmail.test';

        $this->put($client, $uid, $this->ics($uid, 'Nope'));

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testAReadOnlyCalendarRefusesADelete(): void
    {
        $client = $this->boot();
        $uid    = 'put-' . uniqid('', true) . '@plmail.test';

        $this->put($client, $uid, $this->ics($uid, 'Kickoff'));

        $this->calendar->isReadOnly = true;
        $this->em->flush();

        $client->request('DELETE', $this->href($uid), [], [], ['HTTP_AUTHORIZATION' => $this->basic()]);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    /** What makes "just type your server address" work in a client. */
    public function testTheWellKnownPathRedirectsToTheService(): void
    {
        $client = $this->boot();

        $client->request('PROPFIND', '/.well-known/caldav');

        self::assertSame(301, $client->getResponse()->getStatusCode());
        self::assertSame('/caldav/', $client->getResponse()->headers->get('Location'));
    }

    // ---------------------------------------------------------------- helpers

    private function put(KernelBrowser $client, string $uid, string $ics): void
    {
        $client->request('PUT', $this->href($uid), [], [], [
            'HTTP_AUTHORIZATION' => $this->basic(),
            'CONTENT_TYPE'       => 'text/calendar',
        ], $ics);
    }

    private function ics(string $uid, string $summary): string
    {
        $start = (new \DateTimeImmutable('+4 days 09:00'))->format('Ymd\THis\Z');
        $end   = (new \DateTimeImmutable('+4 days 10:00'))->format('Ymd\THis\Z');
        $stamp = (new \DateTimeImmutable())->format('Ymd\THis\Z');

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//plmail//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:{$stamp}\r\n"
            . "DTSTART:{$start}\r\nDTEND:{$end}\r\nSUMMARY:{$summary}\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function href(string $uid): string
    {
        return sprintf('/caldav/calendars/%d/%d/%s.ics', $this->user->id, $this->calendar->id, rawurlencode($uid));
    }

    private function basic(): string
    {
        return 'Basic ' . base64_encode($this->user->email . ':' . $this->secret);
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'caldavwrite-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Write';
        $user->nameLast  = 'Tester';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $minted       = ApiToken::create($user, 'CalDAV write test');
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
