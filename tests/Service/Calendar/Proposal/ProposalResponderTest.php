<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Proposal;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Entity\Calendar\EventProposal;
use App\Entity\Calendar\EventSourceLink;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Calendar\EventReconciler;
use App\Service\Calendar\Extraction\ExtractedEvent;
use App\Service\Calendar\Proposal\EventProposer;
use App\Service\Calendar\Proposal\ProposalResponder;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What yes and no actually cost.
 *
 * Yes has to produce an event that is *in the calendar*, which is not the same
 * as an event row: every view reads occurrences, and an event written beside
 * CalendarEventWriter rather than through it has none — it looks right in the
 * editor and is invisible in the month. So the assertion here is on the
 * occurrence, because that is the thing whose absence nobody would notice until
 * they went looking for an appointment that was not there.
 *
 * No has to survive the next detection run. Deleting the row is the obvious
 * half and useless alone: `app:backfill proposals` re-reads stored mail
 * whenever the parser improves, and the same message yields the same guess — so
 * a plain delete lasts until the next run and the user watches the thing they
 * refused come back. The last test proves the refusal holds by making the guess
 * a second time rather than by inspecting a table, because a row that is
 * written and never read is not a feature.
 *
 * And yes has to leave behind an event that says what it is. It is not Manual —
 * the parser chose the day and the hour — and it is not extraction output, so
 * neither the "found in your email" affordances nor EventReconciler's
 * supersede-and-overwrite rules apply to it. The reconciler tests here hand it
 * a claim carrying its own UID, which is the only way the reconciler could
 * reach it at all: before EventSource::mayBeRewrittenByMail() the sole
 * protection was that CalendarEventWriter mints UIDs no sender collides with,
 * and "unreachable" is not the same guarantee as "refused".
 *
 * Against a real container and a real database: the claim spans the writer, the
 * materialiser and a repository query, and every one of those is final.
 */
final class ProposalResponderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private ProposalResponder $responder;
    private EventProposer $proposer;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->responder  = $container->get(ProposalResponder::class);
        $this->proposer   = $container->get(EventProposer::class);

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

    public function testAcceptingCreatesExactlyOneEvent(): void
    {
        $this->responder->accept($this->proposal());
        $this->em->flush();

        self::assertCount(1, $this->em->getRepository(CalendarEvent::class)->findBy(['usr' => $this->user]));
    }

    /**
     * Through CalendarEventWriter, which is what materialises the occurrences
     * every calendar view reads. An event without them is a row nobody can see.
     */
    public function testTheAcceptedEventIsVisibleInTheCalendar(): void
    {
        $event = $this->responder->accept($this->proposal());
        $this->em->flush();

        self::assertNotNull($event);
        self::assertCount(
            1,
            $this->em->getRepository(CalendarEventOccurrence::class)->findBy(['event' => $event]),
            'an event with no occurrence is invisible in every view',
        );
        self::assertSame('2026-08-04 14:00', $event->startsAt?->format('Y-m-d H:i'));
        self::assertSame('2026-08-04 16:00', $event->endsAt?->format('Y-m-d H:i'));
    }

    /**
     * The proposal row goes, so without this the answer to "why is this in my
     * calendar?" would be gone a week later.
     */
    public function testTheAcceptedEventKeepsTheSentenceItWasReadFrom(): void
    {
        $event = $this->responder->accept($this->proposal());
        $this->em->flush();

        self::assertSame(
            'Termin wie vereinbart: 04.08.2026 um 14 Uhr',
            $event?->jscalendar['description'] ?? null,
        );
    }

    /**
     * Manual is what this used to write, and it was a small lie: the day, the
     * hour and the title were the parser's reading of somebody else's
     * sentence. Recording that a person agreed with a guess rather than made
     * one is the only chance there is — an event that says a human typed it
     * can never be asked afterwards whether the parser was to blame.
     */
    public function testTheAcceptedEventSaysAPersonAgreedWithAGuessRatherThanTypedIt(): void
    {
        $event = $this->responder->accept($this->proposal());
        $this->em->flush();

        self::assertSame(EventSource::AcceptedProposal, $event?->source);
    }

    /**
     * The other half of the same claim: agreeing with a guess is not the same
     * as the machinery having found something. An extraction kind would put a
     * "found in your email" note and a Dismiss button on an event the user
     * pressed Add for, and would offer to suppress a booking nothing would
     * ever re-create.
     */
    public function testTheAcceptedEventIsNotExtractionOutput(): void
    {
        $event = $this->responder->accept($this->proposal());
        $this->em->flush();

        self::assertNotNull($event);
        self::assertNull($event->kind);
        self::assertFalse($event->isExtracted(), 'nobody extracted this — a person answered a question');
        self::assertSame(100, $event->confidence);
    }

    /**
     * The reason the source is worth recording at all.
     *
     * A person read the sentence and decided, so the decision is final: no
     * later mail gets to move the date, whatever SEQUENCE it carries. Written
     * with a colliding UID on purpose, because that is the only way the
     * reconciler could ever reach the event — and "it cannot be reached" was
     * the entire protection before EventSource::mayBeRewrittenByMail() existed.
     * A UID is minted by CalendarEventWriter today; the day something imports
     * one instead, this is what still holds.
     */
    public function testALaterMessageDoesNotRewriteAnEventTheUserAcceptedFromProse(): void
    {
        $event = $this->responder->accept($this->proposal());
        $this->em->flush();

        self::assertNotNull($event);

        $reconciler = self::getContainer()->get(EventReconciler::class);
        $touched    = $reconciler->reconcile($this->message(), [$this->claimAbout($event->uid)]);

        $this->em->flush();

        self::assertSame([], $touched, 'the reconciler must report having changed nothing');
        self::assertSame('Probearbeit', $event->title, 'a mail claim overwrote a date the user had decided on');
        self::assertSame('2026-08-04 14:00', $event->startsAt?->format('Y-m-d H:i'));
        self::assertSame(EventSource::AcceptedProposal, $event->source);
    }

    /**
     * Refused, not ignored. The claim is still filed against the event with
     * applied = false, which is what keeps "why is this on my calendar, and
     * what else has been said about it?" answerable.
     */
    public function testTheRefusedClaimIsStillRecordedAgainstTheEvent(): void
    {
        $event = $this->responder->accept($this->proposal());
        $this->em->flush();

        self::assertNotNull($event);

        self::getContainer()->get(EventReconciler::class)
            ->reconcile($this->message(), [$this->claimAbout($event->uid)]);

        $this->em->flush();

        $links = $this->em->getRepository(EventSourceLink::class)->findBy(['event' => $event]);

        self::assertCount(1, $links);
        self::assertFalse($links[0]->applied, 'a claim that changed nothing must not read as applied');
    }

    public function testAcceptingTakesTheProposalOffTheTable(): void
    {
        $proposal = $this->proposal();
        $id       = $proposal->id;

        $this->responder->accept($proposal);
        $this->em->flush();

        self::assertNull($this->em->getRepository(EventProposal::class)->find($id));
    }

    public function testDismissingTakesTheProposalOffTheTable(): void
    {
        $proposal = $this->proposal();
        $id       = $proposal->id;

        $this->responder->dismiss($proposal);
        $this->em->flush();

        self::assertNull($this->em->getRepository(EventProposal::class)->find($id));
    }

    public function testDismissingAddsNothingToTheCalendar(): void
    {
        $this->responder->dismiss($this->proposal());
        $this->em->flush();

        self::assertCount(0, $this->em->getRepository(CalendarEvent::class)->findBy(['usr' => $this->user]));
    }

    /**
     * The claim the suppression table exists for, made the only way that proves
     * it: refuse the guess, then make the guess again.
     */
    public function testARefusedProposalIsNotOfferedAgainByTheNextRun(): void
    {
        $message  = $this->message();
        $proposal = $this->proposer->propose($message);
        $this->em->flush();

        self::assertNotNull($proposal, 'the fixture has to propose before its refusal means anything');

        $this->responder->dismiss($proposal);
        $this->em->flush();

        self::assertNull(
            $this->proposer->propose($message),
            'a backfill must not put back what the user just threw away',
        );
    }

    /** One refusal per claim, however many times the same claim is refused. */
    public function testTheSameRefusalIsRecordedOnce(): void
    {
        $first = $this->proposal();
        $hash  = $first->dedupKeyHash;

        self::assertTrue($this->responder->dismiss($first));
        $this->em->flush();

        $second               = $this->proposal();
        $second->dedupKeyHash = $hash;

        self::assertFalse($this->responder->dismiss($second), 'this claim was already refused');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * An extraction claiming a different day for the given UID, and claiming it
     * loudly: SEQUENCE 5 beats the accepted event's 0, so nothing but the
     * source rule can be what stops it.
     */
    private function claimAbout(string $uid): ExtractedEvent
    {
        return new ExtractedEvent(
            uid:        $uid,
            dedupKey:   uniqid('claim-', true),
            jscalendar: ['@type' => 'Event', 'title' => 'Something else entirely'],
            startsAt:   new DateTimeImmutable('2026-09-09 09:00', new DateTimeZone('UTC')),
            endsAt:     new DateTimeImmutable('2026-09-09 10:00', new DateTimeZone('UTC')),
            extractor:  'test',
            source:     EventSource::Ics,
            confidence: 100,
            title:      'Something else entirely',
            timeZone:   'UTC',
            kind:       ExtractionKind::Meeting,
            sequence:   5,
        );
    }

    /** A proposal as EventProposer would have written it. */
    private function proposal(): EventProposal
    {
        $message = $this->message();

        $proposal = $this->proposer->propose($message);

        self::assertNotNull($proposal, 'the fixture message has to propose something');

        $this->em->flush();

        return $proposal;
    }

    private function message(): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->messageId      = uniqid('responder-', true) . '@example.test';
        $message->subject        = 'Probearbeit';
        $message->bodyText       = "Hallo,\n\nTermin wie vereinbart: 04.08.2026 um 14 Uhr\nZeitrahmen: 2 Stunden";
        $message->fromAddress    = 'someone@example.org';
        $message->toAddresses    = ['me@example.test'];
        $message->category       = MessageCategory::Primary;
        $message->headers        = [];
        $message->hasAttachments = false;
        $message->receivedAt     = new DateTimeImmutable('2026-07-31 09:00', new DateTimeZone('UTC'));

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'responder-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Responder';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
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

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->account  = $account;
        $calendar->name     = 'Responder fixture';
        $calendar->role     = CalendarRole::Account;
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user    = $user;
        $this->account = $account;
    }
}
