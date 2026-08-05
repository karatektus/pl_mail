<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSourceLink;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\User\UserRepository;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A flight plMail "found" in an email — the fixture "Happening Soon" is drawn
 * from.
 *
 * Not a state the browser suite could reach through the UI, which is the whole
 * reason this exists. An ExtractionKind is stamped by an extractor and the event
 * editor has no field for it, so nothing a user can click produces an event this
 * panel would list; and the provenance link needs an EventSourceLink, which is
 * written by the reconciler while it reads mail nobody can post through the app.
 *
 * The link points at a message `app:test:seed-mail` already seeded rather than
 * at one invented here. The panel's link opens a real mailbox page, so the
 * message has to be a real one with a thread and a label behind it — rebuilding
 * that here would be a second, drifting copy of the mail fixture, and the first
 * time the two disagreed the browser test would fail on a 500 in a page it is
 * not testing. Seeded mail is missing only when this is run without seed-mail,
 * which is reported rather than papered over: the event is still written, and
 * the row simply has no provenance to show.
 *
 * Idempotent, and destructive within its own scope only: it removes the calendar
 * it made last time and everything on it, so a spec that failed halfway does not
 * leave a topbar button behind for the next spec on the same worker to trip over.
 */
#[AsCommand(
    name: 'app:test:seed-extracted-event',
    description: 'Put one mail-extracted booking on a calendar, for the "Happening Soon" browser tests',
)]
final class SeedExtractedEventCommand extends Command
{
    use TargetsTestUser;

    /** Named so a spec can find the chip, the row and the topbar tooltip. */
    public const string TITLE = 'E2E flight to Berlin';

    /** The calendar this fixture owns outright, and clears on every run. */
    public const string CALENDAR = 'E2E Soon';

    /**
     * The seeded inbox subject this booking claims to have been read out of.
     *
     * "E2E Read Me" rather than one of the other three: the star, archive and
     * trash rows are acted on by mail.spec.ts, and a message that spec moves out
     * of the inbox is one this fixture's link would still point at — reachable,
     * but no longer where a reader would expect to find it.
     */
    public const string SOURCE_SUBJECT = 'E2E Read Me';

    /**
     * How far ahead the booking sits.
     *
     * Comfortably inside HappeningSoonReader's fortnight and comfortably outside
     * "today", so the row is listed by the panel without also lighting the
     * topbar's separate today-dot and making the two indicators impossible to
     * tell apart in a screenshot.
     */
    private const string STARTS_IN = '+3 days';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository         $userRepository,
        private readonly CalendarRepository     $calendarRepository,
        private readonly MessageRepository      $messages,
        private readonly CalendarEventWriter    $writer,
        #[Autowire('%kernel.environment%')]
        private readonly string                 $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->configureUserOption();

        $this->addOption(
            'clear',
            null,
            InputOption::VALUE_NONE,
            'Remove the seeded calendar and its events, seeding nothing',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('app:test:seed-extracted-event must not run in the prod environment.');

            return Command::FAILURE;
        }

        $userEmail = $this->resolveUserEmail($input);
        $user      = $this->userRepository->findOneBy(['email' => $userEmail]);

        if (false === $user instanceof User) {
            $io->error(sprintf('Test user "%s" not found — run app:test:seed-user first.', $userEmail));

            return Command::FAILURE;
        }

        $this->removePrevious($user);

        if (true === $input->getOption('clear')) {
            $io->success(sprintf('Removed the extracted booking for %s.', $userEmail));

            return Command::SUCCESS;
        }

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->name     = self::CALENDAR;
        $calendar->color    = '#7c3aed';
        $calendar->role     = CalendarRole::Custom;
        $calendar->timeZone = 'UTC';

        $this->entityManager->persist($calendar);
        $this->entityManager->flush();

        $startsAt = new DateTimeImmutable(self::STARTS_IN, new DateTimeZone('UTC'))->setTime(9, 0);

        $event      = new CalendarEvent();
        $event->uid = 'e2e-soon@airline.test';

        $this->writer->write(
            event:    $event,
            calendar: $calendar,
            user:     $user,
            title:    self::TITLE,
            startsAt: $startsAt,
            endsAt:   $startsAt->modify('+2 hours'),
            timeZone: 'UTC',
            location: 'Heathrow Terminal 5',
        );

        // After write(), which projects the columns a query reads and knows
        // nothing about extraction. These three are what an extractor stamps,
        // and $kind is the one the whole feature filters on.
        $event->kind       = ExtractionKind::Flight;
        $event->source     = EventSource::StructuredData;
        $event->confidence = 90;

        $source = $this->sourceMessage($user);

        if (null !== $source) {
            $link            = new EventSourceLink();
            $link->event     = $event;
            $link->message   = $source;
            $link->extractor = 'jsonld';
            $link->dedupKey  = 'jsonld:e2e-soon';
            $link->applied   = true;
            $link->payload   = ['@type' => 'FlightReservation', 'reservationNumber' => 'E2E-4471'];

            $this->entityManager->persist($link);
        }

        $this->entityManager->flush();

        if (null === $source) {
            $io->warning(sprintf(
                'No seeded message "%s" for %s — the booking has no provenance. Run app:test:seed-mail first.',
                self::SOURCE_SUBJECT,
                $userEmail,
            ));
        }

        $io->success(sprintf('Seeded "%s" on "%s" for %s.', self::TITLE, self::CALENDAR, $userEmail));

        return Command::SUCCESS;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Last run's calendar, and everything on it.
     *
     * The events, occurrences and source links go by cascade at the database,
     * but Doctrine has to be told about the calendar itself rather than the rows
     * underneath — the same thing SeedDuplicateEventCommand does, and for the
     * same reason: removing the Calendar it holds in memory is what makes the
     * identity map agree with what the delete actually did.
     */
    private function removePrevious(User $user): void
    {
        foreach ($this->calendarRepository->findForUser($user) as $calendar) {
            if (self::CALENDAR !== $calendar->name) {
                continue;
            }

            $this->entityManager->remove($calendar);
        }

        $this->entityManager->flush();
    }

    /**
     * The seeded inbox message this booking claims to have come from.
     *
     * Matched on subject across the user's accounts rather than on an id: the
     * mail fixture is reseeded — and re-keyed — by every spec file that needs
     * it, so an id captured here would be stale before this command's own run
     * was over.
     */
    private function sourceMessage(User $user): ?Message
    {
        foreach ($this->messages->findBy(['subject' => self::SOURCE_SUBJECT], ['id' => 'DESC'], 10) as $message) {
            if ($message->account->usr === $user) {
                return $message;
            }
        }

        return null;
    }
}
