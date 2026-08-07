<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSourceLink;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Happening Soon" draws one line per meeting.
 *
 * The panel was reported with a screenshot: the same meeting on two consecutive
 * rows, one wearing the participants icon of an extracted invitation and one the
 * plain clock of a mirrored copy, at the same title and the same hour. Both rows
 * are correct in the database — a meeting reaches plMail twice by two honest
 * routes at once, extracted from its invitation onto the account's calendar and
 * mirrored from the provider onto a Remote one, under the organiser's UID — and
 * nothing collapses them there, because they are two remote objects with their
 * own remoteId, etag and sync state.
 *
 * The calendar grid has answered that on the screen since `EventClusterer`
 * existed. This panel read occurrences and drew a line each, which is the more
 * damaging place for the duplication to surface: a grid at least draws its two
 * chips inside one visibly shared hour, while a list of twelve lines simply
 * claims there are two things about to happen.
 *
 * Written as a request rather than against `HappeningSoonReader` — which has its
 * own test — because what is pinned here is the MARKUP: one `<li>`, the title
 * once, and the merge visible rather than silent. A reader that collapsed
 * correctly into a template that then looped over the cluster's members would
 * pass every test in that class and ship the bug.
 */
final class HappeningSoonPanelTest extends WebTestCase
{
    /** The organiser's UID, shared by both copies — the identity the merge rests on. */
    private const string SHARED_UID = 'happening-soon@organiser.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventWriter $writer;
    private User $user;
    private Account $account;
    private Calendar $accountCalendar;
    private Calendar $mirror;
    private DateTimeImmutable $now;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAMeetingOnTwoCalendarsIsDrawnAsOneRow(): void
    {
        $client = $this->signIn();

        $this->meetingOnBothCalendars('Weekly sync');

        $crawler = $client->request('GET', '/calendar/soon');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('li'), 'one meeting is one line, however many rows hold it');

        // The row's own title element, one per line — counted rather than
        // searched for, because "contains the title" is true of a panel that
        // prints it twice.
        self::assertSame(
            1,
            substr_count($crawler->filter('ul')->html(), 'title="Weekly sync"'),
            'the title is printed once, not once per copy',
        );
    }

    /**
     * And the merge is stated rather than performed quietly. A list that
     * silently drops a line is indistinguishable from one that lost something,
     * so the icon tile wears the same conic-gradient dot the merged chip does
     * and names the calendars behind it.
     */
    public function testTheCollapsedRowShowsTheSameMulticolourAffordanceTheChipDoes(): void
    {
        $client = $this->signIn();

        $this->meetingOnBothCalendars('Weekly sync');

        $client->request('GET', '/calendar/soon');

        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('conic-gradient', $html, 'a merged row says it is merged');
        self::assertStringContainsString('On Account, Mirror', $html, 'and names what it was merged from');
    }

    /**
     * The provenance shown is the extracted copy's, whichever row the query
     * returned first. The mirrored copy carries no kind and no message; reading
     * whichever came first would make the panel's icon and its "why is this on
     * my calendar?" link appear and disappear with the sort order.
     */
    public function testTheCollapsedRowKeepsTheIconAndTheMailOfTheExtractedCopy(): void
    {
        $client = $this->signIn();

        [$extracted] = $this->meetingOnBothCalendars('Weekly sync');

        $this->claim($extracted, 'Einladung zum Weekly sync');

        $client->request('GET', '/calendar/soon');

        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('fa-solid fa-users', $html, 'the extracted copy draws the icon');
        self::assertStringContainsString('Einladung zum Weekly sync', $html);
        self::assertStringNotContainsString('fa-regular fa-clock', $html, 'the kindless copy does not get a line of its own');
    }

    /** A lone meeting is a cluster of one and must not grow a mark that means "merged". */
    public function testAMeetingThatArrivedOnceIsDrawnWithoutTheMergeMark(): void
    {
        $client = $this->signIn();

        $this->copy('Dentist', $this->accountCalendar, uniqid('lone-', true) . '@plmail.test');
        $this->em->flush();

        $client->request('GET', '/calendar/soon');

        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Dentist', $html);
        self::assertStringNotContainsString('conic-gradient', $html);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * One meeting, two rows, one UID — the shape `CalendarPuller` produces when
     * it writes the remote's UID verbatim beside an extraction that already read
     * the same one out of the invitation.
     *
     * @return array{CalendarEvent, CalendarEvent} extracted copy first
     */
    private function meetingOnBothCalendars(string $title): array
    {
        $extracted = $this->copy($title, $this->accountCalendar, self::SHARED_UID);

        // Set after write(): the writer projects the JSCalendar object and the
        // columns a query reads, and $kind is neither — extraction stamps it,
        // which is exactly what makes an event one of these.
        $extracted->kind = ExtractionKind::Meeting;

        $mirror = $this->copy($title, $this->mirror, self::SHARED_UID);

        $this->em->flush();

        return [$extracted, $mirror];
    }

    private function copy(string $title, Calendar $calendar, string $uid): CalendarEvent
    {
        $startsAt = $this->now->modify('+2 days')->setTime(9, 0);

        $event      = new CalendarEvent();
        $event->uid = $uid;

        return $this->writer->write(
            event:    $event,
            calendar: $calendar,
            user:     $this->user,
            title:    $title,
            startsAt: $startsAt,
            endsAt:   $startsAt->modify('+1 hour'),
            timeZone: 'UTC',
        );
    }

    private function claim(CalendarEvent $event, string $subject): void
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->messageId      = uniqid('soon-panel-', true) . '@example.test';
        $message->subject        = $subject;
        $message->fromAddress    = 'organiser@example.test';
        $message->hasAttachments = false;
        $message->receivedAt     = $this->now->modify('-2 days');
        $this->em->persist($message);

        $link            = new EventSourceLink();
        $link->event     = $event;
        $link->message   = $message;
        $link->extractor = 'ics';
        $link->dedupKey  = 'ics:' . self::SHARED_UID;
        $link->applied   = true;
        $link->payload   = ['uid' => self::SHARED_UID];
        $this->em->persist($link);

        $this->em->flush();
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->writer     = $container->get(CalendarEventWriter::class);

        // Relative to the real clock rather than a fixed date, because
        // RecurrenceMaterialiser writes occurrences only inside a horizon around
        // now: a literal date stops being materialised eventually, and the suite
        // would fail for a reason nothing in it says.
        $this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'soon-panel-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Soon';
        $user->nameLast  = 'Panel';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'soon-panel@example.test';
        $account->username       = 'soon-panel@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);

        $this->accountCalendar = $this->seedCalendar($user, 'Account', '#2563eb', isDefault: true);
        $this->mirror          = $this->seedCalendar($user, 'Mirror', '#16a34a');

        $this->em->flush();

        $this->user    = $user;
        $this->account = $account;

        $client->loginUser($user);

        return $client;
    }

    private function seedCalendar(User $user, string $name, string $color, bool $isDefault = false): Calendar
    {
        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = $name;
        $calendar->color     = $color;
        $calendar->role      = true === $isDefault ? CalendarRole::Account : CalendarRole::Custom;
        $calendar->isDefault = $isDefault;
        $calendar->timeZone  = 'UTC';

        $this->em->persist($calendar);

        return $calendar;
    }
}
