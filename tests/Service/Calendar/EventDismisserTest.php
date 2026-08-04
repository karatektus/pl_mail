<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSourceLink;
use App\Entity\Calendar\EventSuppression;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Calendar\EventSuppressionRepository;
use App\Service\Calendar\EventDismisser;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Dismissing an extracted event is a refusal, not a delete.
 *
 * That distinction is the whole subject here. Extraction is re-runnable by
 * design — a backfill walks the entire mailbox again whenever a mapper
 * improves — so an event removed without a record of the refusal comes back on
 * the next run, and keeps coming back. EventSuppression existed for this from
 * the first pass and nothing wrote a row into it, which made the delete button
 * on an extracted event a button that undoes itself.
 *
 * Against a real container and a real database, because the claim being pinned
 * is what the *reconciler* will find on its next run: a hash in a table, read
 * by a query. A double asserting that a row was persisted would be asserting
 * the code calls itself.
 */
final class EventDismisserTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private EventDismisser $dismisser;
    private EventSuppressionRepository $suppressions;
    private User $user;
    private Calendar $calendar;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container          = self::getContainer();
        $this->em           = $container->get(EntityManagerInterface::class);
        $this->connection   = $container->get(Connection::class);
        $this->dismisser    = $container->get(EventDismisser::class);
        $this->suppressions = $container->get(EventSuppressionRepository::class);

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

    public function testDismissingRemovesTheEvent(): void
    {
        $event = $this->extractedEvent(['ics:dinner@example.test']);
        $id    = $event->id;

        $this->dismisser->dismiss($event, $this->user);
        $this->em->flush();

        self::assertNull(
            $this->em->getRepository(CalendarEvent::class)->find($id),
            'the event should be gone, not merely hidden',
        );
    }

    public function testTheClaimBehindItIsRefusedAfterwards(): void
    {
        $event = $this->extractedEvent(['ics:dinner@example.test']);

        self::assertFalse($this->suppressions->isSuppressed($this->user, 'ics:dinner@example.test'));

        $this->dismisser->dismiss($event, $this->user);
        $this->em->flush();

        self::assertTrue(
            $this->suppressions->isSuppressed($this->user, 'ics:dinner@example.test'),
            'the next extraction run must be refused before it creates anything',
        );
    }

    /**
     * The case that makes this more than one row. A booking is described by
     * several messages, and a superseded claim is exactly the one a re-run
     * would apply next — suppressing only the applied key leaves the event a
     * door back in.
     */
    public function testEverySourceClaimIsRefused(): void
    {
        $event = $this->extractedEvent(
            ['ics:booking@example.test', 'jsonld:PNR-4471'],
            appliedFlags: [true, false],
        );

        $this->dismisser->dismiss($event, $this->user);
        $this->em->flush();

        self::assertTrue($this->suppressions->isSuppressed($this->user, 'ics:booking@example.test'));
        self::assertTrue(
            $this->suppressions->isSuppressed($this->user, 'jsonld:PNR-4471'),
            'a superseded claim is the one that would be applied next',
        );
    }

    /**
     * One event, two messages, one claim — a resend, or an invitation and its
     * forward. Writing a row per link would violate uniq_event_suppression and
     * turn a dismissal into a 500.
     */
    public function testRepeatedClaimsAreRefusedOnce(): void
    {
        $event = $this->extractedEvent(['ics:repeat@example.test', 'ics:repeat@example.test']);

        $this->dismisser->dismiss($event, $this->user);
        $this->em->flush();

        self::assertCount(1, $this->suppressions->findBy(['usr' => $this->user]));
    }

    /**
     * The same claim, refused twice across two requests. The row from the first
     * refusal is already committed, so this is the case where the constraint
     * fires unless the existing row is seen.
     */
    public function testAClaimAlreadyRefusedIsNotWrittenAgain(): void
    {
        $this->suppress('ics:again@example.test');

        $event = $this->extractedEvent(['ics:again@example.test']);

        $this->dismisser->dismiss($event, $this->user);
        $this->em->flush();

        self::assertCount(
            1,
            $this->suppressions->findBy(['usr' => $this->user]),
            'one refusal per claim, however many times it is refused',
        );
    }

    /**
     * A hand-made event has no claim behind it, so there is nothing to refuse
     * — and suppressing its uid would silently swallow a real invitation that
     * later arrived carrying the same one.
     */
    public function testAHandMadeEventCannotBeDismissed(): void
    {
        $event       = $this->extractedEvent(['ics:manual@example.test']);
        $event->kind = null;

        self::assertFalse($this->dismisser->canDismiss($event));
    }

    public function testAnExtractedEventCanBeDismissed(): void
    {
        self::assertTrue($this->dismisser->canDismiss($this->extractedEvent(['ics:x@example.test'])));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @param list<string>    $dedupKeys
     * @param list<bool>|null $appliedFlags one per key; all applied when absent
     */
    private function extractedEvent(array $dedupKeys, ?array $appliedFlags = null): CalendarEvent
    {
        $utc = new DateTimeZone('UTC');

        $event             = new CalendarEvent();
        $event->calendar   = $this->calendar;
        $event->usr        = $this->user;
        $event->uid        = uniqid('dismiss-', true) . '@plmail.test';
        $event->title      = 'Dinner at the Ivy';
        $event->startsAt   = new DateTimeImmutable('2026-05-04 19:00', $utc);
        $event->endsAt     = new DateTimeImmutable('2026-05-04 21:00', $utc);
        $event->kind       = ExtractionKind::Dining;
        $event->jscalendar = ['@type' => 'Event', 'title' => 'Dinner at the Ivy'];
        $this->em->persist($event);

        foreach ($dedupKeys as $index => $dedupKey) {
            $link              = new EventSourceLink();
            $link->event       = $event;
            $link->message     = $this->message();
            $link->extractor   = 'ics';
            $link->dedupKey    = $dedupKey;
            $link->applied     = $appliedFlags[$index] ?? true;
            $link->payload     = ['method' => 'REQUEST'];
            $this->em->persist($link);
        }

        $this->em->flush();

        return $event;
    }

    private function suppress(string $dedupKey): void
    {
        $suppression               = new EventSuppression();
        $suppression->usr          = $this->user;
        $suppression->dedupKeyHash = EventSuppressionRepository::hash($dedupKey);

        $this->em->persist($suppression);
        $this->em->flush();
    }

    private function message(): Message
    {
        $message              = new Message();
        $message->account     = $this->account;
        $message->messageId   = uniqid('dismiss-', true) . '@example.test';
        $message->subject     = 'Your booking';
        $message->fromAddress = 'bookings@example.test';
        $message->hasAttachments = false;
        $message->receivedAt  = new DateTimeImmutable('2026-05-01 08:00', new DateTimeZone('UTC'));
        $this->em->persist($message);

        return $message;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'dismiss-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Dismiss';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'dismiss-fixture@example.test';
        $account->username       = 'dismiss-fixture@example.test';
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

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->account   = $account;
        $calendar->name      = 'Dismiss fixture';
        $calendar->role      = CalendarRole::Account;
        $calendar->timeZone  = 'UTC';
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->account  = $account;
        $this->calendar = $calendar;
    }
}
