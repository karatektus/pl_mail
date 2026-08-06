<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Service\Calendar\EventReconciler;
use App\Service\Calendar\Extraction\ExtractedEvent;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A meeting shared onto a second calendar still hears its own updates.
 *
 * The report this pins was two bugs wearing one symptom. A user opened an event
 * that had been read out of a mail, ticked a second calendar, saved — and from
 * then on the meeting was frozen. Later mail moving it did nothing. On another
 * install, where the same event had not been shared, the same mail moved it
 * fine, which is what made it look like a sync problem rather than a rule.
 *
 * **Ticking a calendar was recorded as an edit.** The editor stamped every
 * ticked copy `isUserEdited`, which is the flag that tells EventReconciler to
 * leave an event alone — it exists for a person who *corrected* a wrong
 * extraction, and "also put this on my work calendar" corrects nothing. So the
 * update was filed against the event as a superseded claim and never applied,
 * silently and by design.
 *
 * **And the update only ever looked at one calendar.** A UID is unique within a
 * calendar and deliberately not across them, so the second copy is a second row
 * under the same UID — and the reconciler asked for the one on the calendar
 * extraction files to. Even with the flag out of the way, the copy the user had
 * made would have kept the old time while its sibling moved, which draws the
 * same meeting twice at two different hours.
 *
 * Driven through the real save endpoint rather than the writer, because the
 * first half of the bug is in what the endpoint decides — a service test would
 * assert the fixed behaviour of code the user never reaches.
 */
final class SharedEventUpdateTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private EventReconciler $reconciler;
    private CalendarEventRepository $events;
    private User $user;
    private Account $account;
    private Calendar $personal;
    private Calendar $work;

    private const string UID = 'shared-update@example.test';

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** The scenario, start to finish. */
    public function testAnUpdateReachesBothCopiesAfterSharing(): void
    {
        $client = $this->signIn();

        $this->extracted('Kick-off', '09:00');
        $this->share($client);

        self::assertCount(2, $this->copies(), 'ticking a second calendar makes a second copy');

        $this->extracted('Kick-off, moved', '14:00', sequence: 1);

        foreach ($this->copies() as $copy) {
            self::assertSame('Kick-off, moved', $copy->title, 'on the calendar ' . $copy->calendar?->name);
            self::assertSame('14:00', $copy->startsAt->format('H:i'));
        }
    }

    /**
     * The flag, on its own. Sharing must leave it alone — this is the half that
     * would keep the event frozen even if the reconciler looked everywhere.
     */
    public function testSharingDoesNotCountAsEditingTheEvent(): void
    {
        $client = $this->signIn();

        $this->extracted('Kick-off', '09:00');
        $this->share($client);

        foreach ($this->copies() as $copy) {
            self::assertFalse($copy->isUserEdited, 'on the calendar ' . $copy->calendar?->name);
        }
    }

    /**
     * And the rule it must not weaken: a real correction still stops later mail
     * overwriting it. That is what the flag is for, and a fix that cleared it
     * for everything would trade one silent failure for a worse one.
     */
    public function testAnActualEditStillFreezesTheEvent(): void
    {
        $client = $this->signIn();

        $this->extracted('Kick-off', '09:00');
        $this->share($client, title: 'Kick-off (my title)');

        foreach ($this->copies() as $copy) {
            self::assertTrue($copy->isUserEdited, 'on the calendar ' . $copy->calendar?->name);
        }

        $this->extracted('Kick-off, moved', '14:00', sequence: 1);

        foreach ($this->copies() as $copy) {
            self::assertSame('Kick-off (my title)', $copy->title);
            self::assertSame('09:00', $copy->startsAt->format('H:i'));
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** @return list<CalendarEvent> */
    private function copies(): array
    {
        $this->em->clear();

        return $this->events->findByUidForUser(
            $this->em->getRepository(User::class)->find((int) $this->user->id),
            self::UID,
        );
    }

    /**
     * The save the editor makes: both calendars ticked, everything else as the
     * event already says it.
     */
    private function share(KernelBrowser $client, ?string $title = null): void
    {
        $event = $this->copies()[0];

        $client->request('POST', '/calendar/event/save', [
            '_token'    => $this->token($client),
            'eventId'   => $event->id,
            'calendars' => [(string) $this->personal->id, (string) $this->work->id],
            'title'     => $title ?? (string) $event->title,
            'timeZone'  => 'UTC',
            'startsAt'  => $event->startsAt->format('Y-m-d\TH:i'),
            'endsAt'    => $event->endsAt->format('Y-m-d\TH:i'),
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
    }

    private function token(KernelBrowser $client): string
    {
        return (string) $client->request('GET', '/calendar/event/new')
            ->filter('form[action$="/calendar/event/save"] input[name="_token"]')
            ->first()
            ->attr('value');
    }

    /**
     * A claim arriving by mail, reconciled the way extraction does it.
     *
     * The extractor itself is not exercised — IcsExtractionTest owns that — so
     * the claim is built by hand and handed straight to the reconciler, which
     * is the piece under test.
     */
    private function extracted(string $title, string $clock, int $sequence = 0): void
    {
        $this->em->clear();

        $account = $this->em->getRepository(Account::class)->find((int) $this->account->id);
        self::assertNotNull($account);

        $message                 = new Message();
        $message->account        = $account;
        $message->messageId      = uniqid('shared-', true) . '@example.test';
        $message->subject        = $title;
        $message->fromAddress    = 'organiser@example.test';
        $message->hasAttachments = false;
        $message->receivedAt     = new DateTimeImmutable();

        $this->em->persist($message);
        $this->em->flush();

        $startsAt = $this->day()->setTime((int) substr($clock, 0, 2), 0);

        $this->reconciler->reconcile($message, [new ExtractedEvent(
            uid:        self::UID,
            dedupKey:   'ics:' . self::UID,
            jscalendar: [
                '@type'    => 'Event',
                'uid'      => self::UID,
                'title'    => $title,
                'start'    => $startsAt->format('Y-m-d\TH:i:s'),
                'duration' => 'PT1H',
            ],
            startsAt:   $startsAt,
            endsAt:     $startsAt->modify('+1 hour'),
            extractor:  'ics',
            source:     EventSource::Ics,
            confidence: 100,
            title:      $title,
            timeZone:   'UTC',
            kind:       ExtractionKind::Meeting,
            sequence:   $sequence,
        )]);

        $this->em->flush();
    }

    /**
     * Relative to the run, for the reason every calendar test here is:
     * occurrences only exist inside a horizon around now.
     */
    private function day(): DateTimeImmutable
    {
        return new DateTimeImmutable('monday next week 00:00', new DateTimeZone('UTC'));
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->reconciler = $container->get(EventReconciler::class);
        $this->events     = $container->get(CalendarEventRepository::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'shared-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Shared';
        $user->nameLast  = 'Update';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $user->timezone  = 'UTC';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'shared-update@example.test';
        $account->username       = 'shared-update@example.test';
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

        // Where extraction files, and where the user then shares it to.
        $personal            = new Calendar();
        $personal->usr       = $user;
        $personal->name      = 'Personal';
        $personal->role      = CalendarRole::Default;
        $personal->isDefault = true;
        $personal->timeZone  = 'UTC';
        $this->em->persist($personal);

        $work            = new Calendar();
        $work->usr       = $user;
        $work->name      = 'Work';
        $work->role      = CalendarRole::Custom;
        $work->timeZone  = 'UTC';
        $this->em->persist($work);

        $this->em->flush();

        $this->user     = $user;
        $this->account  = $account;
        $this->personal = $personal;
        $this->work     = $work;

        $client->loginUser($user);

        return $client;
    }
}
