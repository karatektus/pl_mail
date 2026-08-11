<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Domain\DTO\Calendar\HappeningSoonRow;
use App\Domain\DTO\Calendar\OccurrenceCluster;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;
use Twig\Extension\CoreExtension;

/**
 * The "Happening Soon" panel, actually rendered.
 *
 * A template test rather than more reader tests, because what is left to go
 * wrong here is not in the PHP. The row's icon comes off a METHOD (`row.icon`)
 * and Twig resolves that by name at runtime: rename it, or let it quietly become
 * a property, and every row renders an empty `class` — valid Twig, valid PHP,
 * twelve blank squares, and a green suite. `lint:twig` cannot see it either; it
 * parses.
 *
 * Two more things are asserted here and nowhere else, because both are only
 * true in the markup. The provenance link has to carry `data-turbo-frame="_top"`
 * — inside a <turbo-frame> Turbo treats a plain link as a navigation OF the
 * frame, so without it "from this email" loads the whole mailbox into the dialog
 * and strands the user in a modal. And the empty case has to read as an answer:
 * this panel is empty for most people on most days, so a blank frame would be
 * the normal case looking exactly like a failed load.
 *
 * The rows are built by hand rather than read back through HappeningSoonReader.
 * What is under test is the template, and the reader has its own test — building
 * the rows here is what makes the "no surviving message" case expressible at
 * all, since nothing that goes through extraction produces one on demand.
 */
final class HappeningSoonRenderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Environment $twig;
    private Account $account;
    private DateTimeImmutable $startsAt;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->twig       = $container->get(Environment::class);

        // The router needs a request context to build the message link, and the
        // container has none outside a real request. The same push the invite
        // card's test makes, for the same class of reason.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);

        $this->connection->beginTransaction();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** The shape is read before the words are: a plane among boxes. */
    public function testEachRowWearsItsKindIcon(): void
    {
        $html = $this->render([
            $this->row('Flight to Berlin', ExtractionKind::Flight),
            $this->row('Parcel from Ada', ExtractionKind::Delivery),
        ]);

        self::assertStringContainsString('fa-plane', $html, 'the icon comes off the enum');
        self::assertStringContainsString('fa-box', $html);
    }

    /**
     * An event the owner typed has no kind, and the panel lists it now — so the
     * icon column must still draw something. A hole there reads as a row that
     * failed to load, which is exactly what a fallback exists to prevent.
     */
    public function testARowWithNoKindStillWearsAnIcon(): void
    {
        $html = $this->render([$this->row('Dentist', null)]);

        self::assertStringContainsString('Dentist', $html);
        // A clock, not a calendar: this icon is also the topbar trigger's, and
        // the trigger sits next to the calendar switch — see the constant.
        self::assertStringContainsString('fa-regular fa-clock', $html);
    }

    public function testARowSaysWhatIsHappeningAndWhen(): void
    {
        $html = $this->render([$this->row('Flight to Berlin', ExtractionKind::Flight)]);

        self::assertStringContainsString('Flight to Berlin', $html);

        // Read in the zone Twig's `date` filter renders in, not in UTC. The two
        // disagree about which day it is for part of every day, and an
        // assertion that assumed UTC would pass until the suite happened to run
        // late enough in the evening.
        $zone = $this->twig->getExtension(CoreExtension::class)->getTimezone();

        // Built with ICU rather than with PHP's format(), because the row is
        // rendered with ICU now: `format('D, j M')` is one arrangement of the
        // fields for every language on earth, which is the bug this replaced.
        // The expectation has to be "whatever this locale writes", or the test
        // simply re-asserts the defect in the test file instead.
        $expected = (new \IntlDateFormatter(
            'en',
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            $zone,
            \IntlDateFormatter::GREGORIAN,
            (new \IntlDatePatternGenerator('en'))->getBestPattern('EEEdMMM'),
        ))->format($this->startsAt->setTimezone($zone));

        self::assertStringContainsString((string) $expected, $html);
    }

    /**
     * The whole reason extracted events are marked as extracted: "why is this
     * on my calendar?" has to be one click from the row.
     */
    public function testARowLinksToTheMessageItWasReadOutOf(): void
    {
        $message = $this->message('Your flight is confirmed');

        $html = $this->render([$this->row('Flight to Berlin', ExtractionKind::Flight, $message)]);

        self::assertStringContainsString(sprintf('href="/mail/message/%d"', $message->id), $html);
        self::assertStringContainsString('Your flight is confirmed', $html);
    }

    /**
     * Regression guard. Turbo reads a plain link inside a <turbo-frame> as a
     * navigation of that frame, so without this the provenance link loads the
     * mailbox page into the dialog and leaves no way back to the mail.
     */
    public function testTheProvenanceLinkLeavesTheDialogRatherThanLoadingIntoIt(): void
    {
        $html = $this->render([
            $this->row('Flight to Berlin', ExtractionKind::Flight, $this->message('Confirmed')),
        ]);

        self::assertStringContainsString('data-turbo-frame="_top"', $html);
    }

    /**
     * An event keeps its kind after the mail behind it is expunged
     * provider-side. A link to a message that is not there is worse than no
     * link, so the row renders without one rather than with a dead one.
     */
    public function testARowWhoseMessageIsGoneOffersNoLink(): void
    {
        $html = $this->render([$this->row('Flight to Berlin', ExtractionKind::Flight)]);

        self::assertStringContainsString('Flight to Berlin', $html);
        self::assertStringNotContainsString('href="/mail/message/', $html);
    }

    /**
     * The normal case. Most people have nothing coming up on most days, and a
     * panel that opened on blank space would make that look like a failure.
     */
    public function testAnEmptyListReadsAsNothingComingUpRatherThanAsABrokenPage(): void
    {
        $html = $this->render([]);

        self::assertStringContainsString('Nothing coming up', $html);
        self::assertStringContainsString('reservations and tickets', $html, 'the empty state says what would appear here');
        self::assertStringNotContainsString('<ul', $html);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** @param list<HappeningSoonRow> $rows */
    private function render(array $rows): string
    {
        return $this->twig->render('calendar/_happening_soon.html.twig', ['rows' => $rows]);
    }

    private function row(string $title, ?ExtractionKind $kind, ?Message $source = null): HappeningSoonRow
    {
        $event        = new CalendarEvent();
        $event->title = $title;
        $event->kind  = $kind;

        $occurrence           = new CalendarEventOccurrence();
        $occurrence->event    = $event;
        $occurrence->startsAt = $this->startsAt;
        $occurrence->endsAt   = $this->startsAt->modify('+2 hours');

        // A cluster of one, which is what a meeting that reached plMail once
        // is — the reader hands the template clusters now, and a lone
        // occurrence is the ordinary case rather than a special one.
        $row = HappeningSoonRow::of(OccurrenceCluster::of([$occurrence]), $source);

        self::assertNotNull($row, 'a dated occurrence must produce a row');

        return $row;
    }

    private function message(string $subject): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->messageId      = uniqid('soon-render-', true) . '@example.test';
        $message->subject        = $subject;
        $message->fromAddress    = 'bookings@example.test';
        $message->hasAttachments = false;
        $message->receivedAt     = $this->startsAt->modify('-3 days');

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seed(): void
    {
        $this->startsAt = new DateTimeImmutable('now', new DateTimeZone('UTC'))->modify('+3 days');

        $user            = new User();
        $user->email     = 'soon-render-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Soon';
        $user->nameLast  = 'Render';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'soon-render@example.test';
        $account->username       = 'soon-render@example.test';
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

        $this->em->flush();

        $this->account = $account;
    }
}
