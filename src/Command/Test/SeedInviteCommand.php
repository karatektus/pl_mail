<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSourceLink;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\User\UserRepository;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\CalendarTimeResolver;
use App\Service\Calendar\Extraction\IcsEventExtractor;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A meeting invitation nobody has answered yet — the fixture the RSVP browser
 * tests answer.
 *
 * Not reachable through the UI, for the reason SeedExtractedEventCommand gives:
 * an invitation is written by the ICS extractor while it reads mail, and nothing
 * a browser can do posts mail into the account. So this writes what the
 * extractor would have: the event, its participants with this account among
 * them and no answer given, and the source link that puts the card above the
 * message.
 *
 * **It is not on the calendar**, and that is the point of it. An invitation is
 * drawn once it is answered Yes or Maybe and not before (see InviteResponder),
 * so the event is written with its participation already unanswered — BEFORE
 * the writer materialises occurrences, which it does as part of the write.
 * Stamped afterwards, the meeting would already be in the grid and the spec
 * that answers it would be watching a block that was there all along.
 *
 * **Today at 14:30, on the user's own clock.** Whatever day the suite runs, the
 * pane opens on the week that holds today, so the slot the answer flies to is
 * always on screen — a fixed date would sit in some other week most of the year.
 *
 * The link points at a message `app:test:seed-mail` already seeded, as the
 * extracted-booking fixture does, and for its reasons: the card lives above a
 * real message in a real thread, and a second copy of the mail fixture here
 * would drift from the first.
 *
 * Idempotent, and destructive within its own scope only: it removes the calendar
 * it made last time and everything on it — an answered invitation included — so
 * each test answers a fresh one.
 */
#[AsCommand(
    name: 'app:test:seed-invite',
    description: 'Put one unanswered meeting invitation on a seeded message, for the RSVP browser tests',
)]
final class SeedInviteCommand extends Command
{
    use TargetsTestUser;

    /** Named so a spec can find the card and the chip. */
    public const string TITLE = 'E2E Intro call';

    /** The calendar this fixture owns outright, and clears on every run. */
    public const string CALENDAR = 'E2E Invites';

    /**
     * The seeded inbox subject the invitation arrived as.
     *
     * The one the extracted booking uses as well, and for its reason: the
     * other seeded rows are starred, archived and trashed by mail.spec.ts, and
     * a message moved out of the inbox is one a spec would have to go looking
     * for.
     */
    public const string SOURCE_SUBJECT = 'E2E Read Me';

    /** When the meeting starts, today, and for how long. */
    private const string STARTS_AT = '14:30';

    private const string LASTS = '+30 minutes';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository         $userRepository,
        private readonly CalendarRepository     $calendarRepository,
        private readonly MessageRepository      $messages,
        private readonly CalendarEventWriter    $writer,
        private readonly CalendarTimeResolver   $time,
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
            'Remove the seeded calendar and its invitation, seeding nothing',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('app:test:seed-invite must not run in the prod environment.');

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
            $io->success(sprintf('Removed the seeded invitation for %s.', $userEmail));

            return Command::SUCCESS;
        }

        // Unlike the booking fixture, nothing here is any use without its
        // message: the card IS the feature, and it lives above that message.
        $source = $this->sourceMessage($user);

        if (null === $source) {
            $io->error(sprintf(
                'No seeded message "%s" for %s — run app:test:seed-mail first.',
                self::SOURCE_SUBJECT,
                $userEmail,
            ));

            return Command::FAILURE;
        }

        $me = $this->addressOf($source);

        if (null === $me) {
            $io->error('The seeded account has no address to invite.');

            return Command::FAILURE;
        }

        $zone = $this->time->zoneFor($user);

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->name     = self::CALENDAR;
        $calendar->color    = '#2563eb';
        $calendar->role     = CalendarRole::Custom;
        $calendar->timeZone = $zone->getName();

        $this->entityManager->persist($calendar);
        $this->entityManager->flush();

        $startsAt = new DateTimeImmutable('today ' . self::STARTS_AT, $zone);

        $event                  = new CalendarEvent();
        $event->uid             = 'e2e-invite@organiser.test';
        $event->kind            = ExtractionKind::Meeting;
        $event->source          = EventSource::Ics;
        $event->myParticipation = ParticipationStatus::NeedsAction;

        $this->writer->write(
            event:             $event,
            calendar:          $calendar,
            user:              $user,
            title:             self::TITLE,
            startsAt:          $startsAt,
            endsAt:            $startsAt->modify(self::LASTS),
            timeZone:          $zone->getName(),
            location:          'Teams meeting',
            jscalendarOverlay: [
                'participants' => [
                    'organiser' => [
                        '@type' => 'Participant',
                        'name'  => 'E2E Organiser',
                        'email' => 'organiser@organiser.test',
                        'roles' => ['owner' => true, 'attendee' => true],
                    ],
                    'me' => [
                        '@type'               => 'Participant',
                        'email'               => $me,
                        'roles'               => ['attendee' => true],
                        'participationStatus' => ParticipationStatus::NeedsAction->value,
                    ],
                ],
            ],
        );

        $link            = new EventSourceLink();
        $link->event     = $event;
        $link->message   = $source;
        $link->extractor = IcsEventExtractor::NAME;
        $link->dedupKey  = 'ics:' . $event->uid;
        $link->applied   = true;
        $link->payload   = ['method' => 'REQUEST'];

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        $io->success(sprintf('Seeded "%s", unanswered, for %s.', self::TITLE, $userEmail));

        return Command::SUCCESS;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Last run's calendar, and everything on it — see SeedExtractedEventCommand
     * for why it is the calendar Doctrine is told to remove.
     */
    private function removePrevious(User $user): void
    {
        foreach ($this->calendarRepository->findForUser($user) as $calendar) {
            if (self::CALENDAR === $calendar->name) {
                $this->entityManager->remove($calendar);
            }
        }

        $this->entityManager->flush();
    }

    /** The seeded message, matched on subject for the reason the booking fixture gives. */
    private function sourceMessage(User $user): ?Message
    {
        foreach ($this->messages->findBy(['subject' => self::SOURCE_SUBJECT], ['id' => 'DESC'], 10) as $message) {
            if ($message->account->usr === $user) {
                return $message;
            }
        }

        return null;
    }

    /**
     * The address the invitation is to: one the account owns, which is what
     * makes InviteReader recognise the attendee as the reader. The seeded
     * account's `email` is a display name, so the first owned address that is
     * an address.
     */
    private function addressOf(Message $message): ?string
    {
        foreach ($message->account->ownedAddresses as $address) {
            if (str_contains($address, '@')) {
                return $address;
            }
        }

        return null;
    }
}
