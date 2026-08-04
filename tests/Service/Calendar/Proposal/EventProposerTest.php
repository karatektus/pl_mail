<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Proposal;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSourceLink;
use App\Entity\Calendar\EventSuppression;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\User\User;
use App\Repository\Calendar\EventSuppressionRepository;
use App\Service\Calendar\Proposal\EventProposer;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * When a message may offer a date, and when it must not.
 *
 * The parser is tested next door and in isolation; the claim here is the other
 * half, and it is the half that decides whether people keep the feature turned
 * on. A card offering to put "Sale ends Friday!" on somebody's calendar is
 * worse than no card at all, so every rule refuses on doubt and each of them is
 * pinned by a message that would otherwise have proposed.
 *
 * Two of these guard bugs that are invisible when they ship. A relative date
 * anchored on now() instead of on the message looks perfect for as long as the
 * mail is recent and turns a backfill into a calendar full of dates nobody
 * wrote; a proposal made for a message that already produced a real event is a
 * duplicate of an invitation, and the extraction that would have revealed it
 * runs asynchronously, so the race only loses on a busy worker.
 *
 * Against a real container and a real database, because what is being asserted
 * is what the next run will find: a suppression row read back by a query, and a
 * proposal that either exists or does not. Every collaborator here is final, so
 * doubles were never an option either.
 */
final class EventProposerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private EventProposer $proposer;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->proposer   = $container->get(EventProposer::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
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

    /** The first worked example, end to end. */
    public function testAGermanRecruitingMailProposesTheAppointmentItNames(): void
    {
        $proposal = $this->proposer->propose($this->message(
            subject: 'Einladung Probearbeit von zu Hause | Senior Backend Engineer (m/w/d) | VGL Publishing AG',
            body:    "Hallo,\n\nTermin wie vereinbart: 04.08.2026 um 14 Uhr\nZeitrahmen: 2 Stunden\n\nViele Grüße",
        ));

        self::assertNotNull($proposal);
        self::assertSame('2026-08-04 14:00', $proposal->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-08-04 16:00', $proposal->endsAt->format('Y-m-d H:i'), 'two stated hours');
        self::assertSame(
            'Einladung Probearbeit von zu Hause | Senior Backend Engineer (m/w/d) | VGL Publishing AG',
            $proposal->title,
            'prose does not name itself, so the subject does',
        );
        self::assertSame('Termin wie vereinbart: 04.08.2026 um 14 Uhr', $proposal->sourceSentence);
    }

    /** The second, and the one whose date exists only relative to the mail. */
    public function testAnInformalEnglishMailProposesTheSaturdayAfterItWasSent(): void
    {
        $proposal = $this->proposer->propose($this->message(
            subject: 'coffee?',
            body:    'hey lets meet up on saturday at 3pm',
        ));

        self::assertNotNull($proposal);
        // Sent on Friday 31 July 2026, so Saturday is the first of August.
        self::assertSame('2026-08-01 15:00', $proposal->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-08-01 16:00', $proposal->endsAt->format('Y-m-d H:i'), 'unstated is one hour');
        self::assertSame('hey lets meet up on saturday at 3pm', $proposal->sourceSentence);
    }

    /**
     * The bug this whole design is arranged around. The same sentence, in a mail
     * from eighteen months ago, has to give the Saturday of eighteen months ago
     * — which no reading of the clock can produce.
     */
    public function testARelativeDateResolvesAgainstTheMessageAndNotAgainstToday(): void
    {
        $proposal = $this->proposer->propose($this->message(
            subject:    'coffee?',
            body:       'hey lets meet up on saturday at 3pm',
            receivedAt: '2025-03-07 09:00',
        ));

        self::assertNotNull($proposal);
        self::assertSame(
            '2025-03-08 15:00',
            $proposal->startsAt->format('Y-m-d H:i'),
            'the anchor is the message, not now()',
        );
    }

    public function testADateBehindTheMessageIsNotProposed(): void
    {
        self::assertNull($this->proposer->propose($this->message(
            subject: 'sorry I missed you',
            body:    'we said 04.08.2026 um 14 Uhr and I forgot entirely',
            receivedAt: '2026-09-01 09:00',
        )));
    }

    /**
     * A year out. Prose that names a real appointment that far ahead barely
     * exists; contract dates and renewal terms do.
     */
    public function testADateBeyondTheHorizonIsNotProposed(): void
    {
        self::assertNull($this->proposer->propose($this->message(
            subject: 'your policy',
            body:    'Die Police verlängert sich am 04.08.2031 um 14 Uhr automatisch.',
        )));
    }

    /** "Sale ends Friday!" — the failure mode that makes people switch this off. */
    public function testMarketingMailProposesNothing(): void
    {
        self::assertNull($this->proposer->propose($this->message(
            subject:  'Summer sale!',
            body:     'Angebot endet am 04.08.2026 um 14 Uhr — jetzt zugreifen!',
            category: MessageCategory::Promotions,
        )));
    }

    /**
     * The category alone is not enough: MessageCategorizer pulls anyone the user
     * has ever written to back into Primary, and a shop the user once mailed
     * still sends newsletters full of dates.
     */
    public function testMailCarryingListHeadersProposesNothingEvenWhenItIsFiledAsPersonal(): void
    {
        self::assertNull($this->proposer->propose($this->message(
            subject: 'This week at the club',
            body:    'Der nächste Stammtisch ist am 04.08.2026 um 14 Uhr.',
            headers: ['List-Unsubscribe' => '<mailto:leave@example.test>'],
        )));
    }

    /** A date announced to a list is an announcement; one sent to you is an arrangement. */
    public function testMailNotAddressedToTheUserProposesNothing(): void
    {
        self::assertNull($this->proposer->propose($this->message(
            subject: 'Team offsite',
            body:    'Wir treffen uns am 04.08.2026 um 14 Uhr.',
            to:      ['everyone@example.org'],
        )));
    }

    /**
     * The invitation is the better answer to the same question, and it wins by
     * the cascade. Refused on the signal rather than on the resulting event,
     * because extraction runs asynchronously and waiting for it would be a race
     * this loses on exactly the busy machines where it matters.
     */
    public function testAMessageCarryingAnInviteProposesNothing(): void
    {
        $message = $this->message(
            subject: 'Invitation: Quarterly review',
            body:    'Wir sehen uns am 04.08.2026 um 14 Uhr.',
        );

        $part              = new MessagePart();
        $part->message     = $message;
        $part->contentType = 'text/calendar';
        $part->disposition = 'inline';
        $message->addMessagePart($part);
        $this->em->persist($part);
        $this->em->flush();

        self::assertNull($this->proposer->propose($message));
    }

    /** And the same answer from the other side, for mail extraction has already read. */
    public function testAMessageThatAlreadyProducedARealEventProposesNothing(): void
    {
        $message = $this->message(
            subject: 'Your booking',
            body:    'Tisch reserviert am 04.08.2026 um 14 Uhr.',
        );

        $event             = new CalendarEvent();
        $event->calendar   = $this->calendar();
        $event->usr        = $this->user;
        $event->uid        = uniqid('proposer-', true) . '@plmail.test';
        $event->title      = 'Tisch';
        $event->startsAt   = new DateTimeImmutable('2026-08-04 14:00', new DateTimeZone('UTC'));
        $event->endsAt     = new DateTimeImmutable('2026-08-04 16:00', new DateTimeZone('UTC'));
        $event->kind       = ExtractionKind::Dining;
        $event->jscalendar = ['@type' => 'Event'];
        $this->em->persist($event);

        $link            = new EventSourceLink();
        $link->event     = $event;
        $link->message   = $message;
        $link->extractor = 'ics';
        $link->dedupKey  = 'ics:already@example.test';
        $link->applied   = true;
        $link->payload   = ['method' => 'REQUEST'];
        $this->em->persist($link);
        $this->em->flush();

        self::assertNull($this->proposer->propose($message));
    }

    /**
     * The point of writing a suppression rather than merely deleting a row:
     * detection is re-runnable, and a refusal that did not survive the re-run
     * would put the thing back every time the parser improved.
     */
    public function testAClaimTheUserHasAlreadyRefusedIsNotProposedAgain(): void
    {
        $message = $this->message(
            subject: 'coffee?',
            body:    'hey lets meet up on saturday at 3pm',
        );

        $suppression               = new EventSuppression();
        $suppression->usr          = $this->user;
        $suppression->dedupKeyHash = EventSuppressionRepository::hash($this->proposer->dedupKey(
            $message,
            new DateTimeImmutable('2026-08-01 15:00', new DateTimeZone('UTC')),
        ));
        $this->em->persist($suppression);
        $this->em->flush();

        self::assertNull($this->proposer->propose($message));
    }

    /** One guess per message: a second card beside the first is two decisions to make about one sentence. */
    public function testAMessageIsNotProposedTwice(): void
    {
        $message = $this->message(subject: 'coffee?', body: 'hey lets meet up on saturday at 3pm');

        self::assertNotNull($this->proposer->propose($message));
        $this->em->flush();

        self::assertNull($this->proposer->propose($message));
    }

    /** A draft is the user talking to themself, and there is nobody to offer anything to. */
    public function testADraftProposesNothing(): void
    {
        $message = $this->message(subject: 'coffee?', body: 'hey lets meet up on saturday at 3pm');
        $message->addFlag(MessageFlag::DRAFT);

        self::assertNull($this->proposer->propose($message));
    }

    public function testTheTitleLosesItsReplyPrefixes(): void
    {
        $proposal = $this->proposer->propose($this->message(
            subject: 'AW: Re: Probearbeit',
            body:    'Termin wie vereinbart: 04.08.2026 um 14 Uhr',
        ));

        self::assertNotNull($proposal);
        self::assertSame('Probearbeit', $proposal->title);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @param array<string,string> $headers
     * @param list<string>|null    $to
     */
    private function message(
        string           $subject,
        string           $body,
        string           $receivedAt = '2026-07-31 09:00',
        ?MessageCategory $category = MessageCategory::Primary,
        array            $headers = [],
        ?array           $to = null,
    ): Message {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->messageId      = uniqid('proposer-', true) . '@example.test';
        $message->subject        = $subject;
        $message->bodyText       = $body;
        $message->fromAddress    = 'someone@example.org';
        $message->toAddresses    = $to ?? ['me@example.test'];
        $message->category       = $category;
        $message->headers        = $headers;
        $message->hasAttachments = false;
        $message->receivedAt     = new DateTimeImmutable($receivedAt, new DateTimeZone('UTC'));

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function calendar(): Calendar
    {
        $calendar           = new Calendar();
        $calendar->usr      = $this->user;
        $calendar->account  = $this->account;
        $calendar->name     = 'Proposer fixture';
        $calendar->role     = CalendarRole::Account;
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);

        return $calendar;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'proposer-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Proposer';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        // Both halves of the fixture's clock: the language reads a slashed
        // date, and the zone is the wall clock "14 Uhr" is stated in. UTC so
        // the expectations above are the digits the mail contains.
        $user->locale    = 'de';
        $user->timezone  = 'UTC';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'me@example.test';
        $account->username       = 'me@example.test';
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

        $this->user    = $user;
        $this->account = $account;
    }
}
