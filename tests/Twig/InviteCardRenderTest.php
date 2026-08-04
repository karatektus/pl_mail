<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSourceLink;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Calendar\Extraction\IcsEventExtractor;
use App\Service\Calendar\InviteReader;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * The invite card, actually rendered.
 *
 * A template test rather than another service test, because what is left to go
 * wrong here is not in the PHP: the card reads its statuses off enum METHODS
 * (`status.isAnswer`, `answer.action`, `participant.status.icon`), and Twig
 * resolves those by name at runtime. A rename on the enum, or a method that
 * quietly becomes a property, leaves every one of those expressions rendering
 * an empty string — valid Twig, valid PHP, a card with blank buttons, and a
 * suite that stays green. `lint:twig` cannot see it either; it parses.
 *
 * The assertions are deliberately about the *answers offered*, since that is
 * the part a user acts on and the part that fails silently.
 */
final class InviteCardRenderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private InviteReader $reader;
    private Environment $twig;
    private User $user;
    private Account $account;
    private Calendar $calendar;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->reader     = $container->get(InviteReader::class);
        $this->twig       = $container->get(Environment::class);

        // The card puts a CSRF token in every answer form, and the token
        // manager reads the session off the request stack — which is empty
        // outside a real request. Pushing one is what makes the template
        // renderable here at all.
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

    public function testTheCardOffersTheThreeAnswers(): void
    {
        $html = $this->render($this->invitation());

        self::assertStringContainsString('Yes', $html);
        self::assertStringContainsString('Maybe', $html);
        self::assertStringContainsString('No', $html);

        // One form per answer: a submit button's value only travels when it is
        // the button that was pressed, which is the sort of thing that works
        // until something else submits the form.
        self::assertSame(3, substr_count($html, 'action="/calendar/invite/'));
    }

    public function testTheCardNamesTheMeetingAndTheOrganiser(): void
    {
        $html = $this->render($this->invitation());

        self::assertStringContainsString('Quarterly review', $html);
        self::assertStringContainsString('The Chair', $html);
    }

    /** Every answer carries a CSRF token, or the post is refused. */
    public function testEveryAnswerIsTokened(): void
    {
        self::assertSame(3, substr_count($this->render($this->invitation()), 'name="_token"'));
    }

    /** An answer already given is shown, not offered again as if unanswered. */
    public function testAnAnsweredInvitationShowsItsAnswer(): void
    {
        $html = $this->render($this->invitation(myStatus: 'accepted'));

        self::assertStringContainsString('Accepted', $html);
        self::assertStringContainsString('fa-circle-check', $html, 'the status icon comes off the enum');
    }

    /** A cancelled meeting offers nothing to accept. */
    public function testACancelledInvitationOffersNoAnswer(): void
    {
        $html = $this->render($this->invitation(status: EventStatus::Cancelled));

        self::assertStringContainsString('called off', $html);
        self::assertStringNotContainsString('action="/calendar/invite/', $html);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function render(Message $message): string
    {
        $invite = $this->reader->forMessage($message, $this->user);

        self::assertNotNull($invite);

        return $this->twig->render('calendar/_invite_card.html.twig', ['invite' => $invite]);
    }

    private function invitation(?string $myStatus = null, EventStatus $status = EventStatus::Confirmed): Message
    {
        $utc = new DateTimeZone('UTC');

        $event             = new CalendarEvent();
        $event->calendar   = $this->calendar;
        $event->usr        = $this->user;
        $event->uid        = uniqid('card-', true) . '@example.org';
        $event->title      = 'Quarterly review';
        $event->location   = 'Room 3';
        $event->startsAt   = new DateTimeImmutable('2026-06-02 09:00', $utc);
        $event->endsAt     = new DateTimeImmutable('2026-06-02 10:00', $utc);
        $event->kind       = ExtractionKind::Meeting;
        $event->status     = $status;
        $event->jscalendar = [
            '@type'        => 'Event',
            'title'        => 'Quarterly review',
            'participants' => [
                'chair@example.org' => [
                    '@type' => 'Participant',
                    'email' => 'chair@example.org',
                    'name'  => 'The Chair',
                    'roles' => ['owner' => true],
                ],
                'me@example.test' => array_filter([
                    '@type'               => 'Participant',
                    'email'               => 'me@example.test',
                    'roles'               => ['attendee' => true],
                    'participationStatus' => $myStatus,
                ], static fn (mixed $value): bool => null !== $value),
            ],
        ];
        $this->em->persist($event);

        $message                 = new Message();
        $message->account        = $this->account;
        $message->messageId      = uniqid('card-', true) . '@example.org';
        $message->subject        = 'Invitation: Quarterly review';
        $message->fromAddress    = 'chair@example.org';
        $message->hasAttachments = false;
        $message->receivedAt     = new DateTimeImmutable('2026-06-01 08:00', $utc);
        $this->em->persist($message);

        $link            = new EventSourceLink();
        $link->event     = $event;
        $link->message   = $message;
        $link->extractor = IcsEventExtractor::NAME;
        $link->dedupKey  = 'ics:' . $event->uid;
        $link->payload   = ['method' => 'REQUEST'];
        $this->em->persist($link);

        $this->em->flush();
        $this->reader->reset();

        return $message;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'card-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Card';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'me@example.test';
        $account->username       = 'me@example.test';
        $account->name           = 'Card Fixture';
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

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->account  = $account;
        $calendar->name     = 'Card fixture';
        $calendar->role     = CalendarRole::Account;
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->account  = $account;
        $this->calendar = $calendar;
    }
}
