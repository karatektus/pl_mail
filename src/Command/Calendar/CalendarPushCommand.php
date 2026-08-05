<?php

declare(strict_types=1);

namespace App\Command\Calendar;

use App\Repository\Calendar\CalendarRepository;
use App\Service\Calendar\Push\CalendarPushRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Keeps calendar push channels alive — and is the thing that eventually opens
 * one.
 *
 * "Eventually" is the decision worth arguing, and it survived being revisited.
 * The obvious place to register a channel is the moment a user ticks a calendar
 * to mirror, and registering there and only there was rejected: registration is
 * best-effort and fails for reasons that have nothing to do with the user's
 * click — no public HTTPS address yet, a Google Cloud project whose domain
 * verification is still pending, a tenant that refuses subscriptions. Tied to
 * the subscribe flow alone, each of those means push never happens for that
 * calendar until somebody thinks to unsubscribe and re-subscribe it. Driven from
 * a sweep, the same install starts delivering by push within the hour of the
 * underlying problem being fixed, with nobody touching anything.
 *
 * What the subscribe flow does now is dispatch RegisterCalendarPushMessage,
 * which asks once, off the request, and gives up quietly. That is an addition on
 * top of this command and not a replacement for it: it removes the first hour,
 * during which a working feature otherwise looks like nothing happened, and it
 * cannot remove anything else — an install that is not reachable when the box is
 * ticked is still waiting for this sweep.
 *
 * Renewal is the same call: both managers treat "no channel" as "make one", so
 * there is one code path for register, renew and repair rather than three that
 * can disagree about what a live channel is.
 *
 * It costs almost nothing when there is nothing to do. An install with no
 * public address stops at isConfigured() before a single HTTP request; a
 * calendar with a live channel answers needsRenewal() from a column.
 *
 * Exits SUCCESS even when registrations failed, which is a deliberate
 * difference from app:push:renew. A calendar that could not register is on
 * polling, which is the documented degraded state and not a fault of this run —
 * and this is scheduled through RunCommandMessage, where a non-zero exit throws
 * and is retried by the transport, so reporting "Google will not verify this
 * domain" as a failure would dead-letter an envelope every hour, forever, for a
 * condition the sweep is designed to tolerate.
 */
#[AsCommand(
    name: 'app:calendar:push',
    description: 'Register and renew push channels for connected calendars, so changes arrive instead of being polled for',
)]
final class CalendarPushCommand extends Command
{
    public function __construct(
        private readonly CalendarRepository   $calendars,
        private readonly CalendarPushRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('calendar-id', InputArgument::OPTIONAL, 'Act on a single calendar by ID; omit for every mirrored one')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-register every channel regardless of when it expires')
            ->addOption('stop', null, InputOption::VALUE_NONE, 'Tear the channels down and go back to polling');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $calendarId = $input->getArgument('calendar-id');
        $force      = true === $input->getOption('force');
        $stop       = true === $input->getOption('stop');

        if (null !== $calendarId) {
            $calendar = $this->calendars->find((int) $calendarId);

            if (null === $calendar) {
                $io->error(sprintf('Calendar %s not found.', $calendarId));

                return Command::FAILURE;
            }

            $calendars = [$calendar];
        } else {
            $calendars = $this->calendars->findMirrored();
        }

        $registered = 0;
        $skipped    = 0;
        $failed     = 0;
        $stopped    = 0;

        foreach ($calendars as $calendar) {
            $manager = $this->registry->resolve($calendar);

            if (null === $manager) {
                // CalDAV, and every local calendar. Neither has push, and
                // neither is a problem.
                $skipped++;
                continue;
            }

            if (true === $stop) {
                $manager->unsubscribe($calendar);
                $io->text(sprintf('→ stopped push for %s (#%d)', $calendar->name, (int) $calendar->id));
                $stopped++;
                continue;
            }

            // Asked per manager rather than once, because the two providers
            // could one day disagree about what counts as configured — and
            // asked before needsRenewal() so an unconfigured install does no
            // work at all rather than one refused registration per calendar.
            if (false === $manager->isConfigured()) {
                $skipped++;
                continue;
            }

            if (false === $force && false === $manager->needsRenewal($calendar)) {
                $skipped++;
                continue;
            }

            if (true === $manager->renew($calendar)) {
                $io->text(sprintf(
                    '→ push registered for %s (#%d) until %s',
                    $calendar->name,
                    (int) $calendar->id,
                    $manager->expiresAt($calendar)?->format('Y-m-d H:i') ?? 'unknown',
                ));
                $registered++;
                continue;
            }

            $io->text(sprintf(
                '· %s (#%d) stays on polling — see the log for what the provider said',
                $calendar->name,
                (int) $calendar->id,
            ));
            $failed++;
        }

        if (true === $stop) {
            $io->success(sprintf('%d channel(s) stopped, %d calendar(s) had none.', $stopped, $skipped));

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%d registered or renewed, %d already current or unsupported, %d staying on polling.',
            $registered,
            $skipped,
            $failed,
        ));

        return Command::SUCCESS;
    }
}
