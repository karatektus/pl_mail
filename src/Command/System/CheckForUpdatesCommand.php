<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Domain\DTO\System\UpdateStatus;
use App\Domain\Enum\System\UpdateChannel;
use App\Domain\Enum\System\UpdateVerdict;
use App\Service\System\Update\UpdateChecker;
use App\Service\System\Update\UpdateNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ask whether the update channel has a newer build, and tell the
 * administrators the first time it does. Scheduled hourly (MaintenanceSchedule).
 *
 * **A check that gets no answer still exits 0.** The registry being unreachable
 * for an hour is a fact about the network, recorded on the check and shown on
 * Admin → Updates. A failed command would put a scheduler error in the log
 * every hour an install sits behind a firewall, for something nobody has to fix.
 */
#[AsCommand(
    name: 'app:updates:check',
    description: 'Check the update channel for a newer build and notify the administrators once per build.',
)]
final class CheckForUpdatesCommand extends Command
{
    public function __construct(
        private readonly UpdateChecker $checker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $check   = $this->checker->check();
        $channel = $this->checker->channel($check);

        if (UpdateChannel::Off === $channel) {
            $io->writeln('Update checks are off.');

            return Command::SUCCESS;
        }

        if (null !== $check->error) {
            $io->warning(sprintf('No answer from the %s channel: %s', $channel->value, $check->error));

            return Command::SUCCESS;
        }

        $status = UpdateStatus::fromArray($check->status);

        if (null === $status) {
            return Command::SUCCESS;
        }

        $io->writeln(match ($status->verdict) {
            UpdateVerdict::Available => sprintf('Newer build on %s: %s (%s commits ahead).', $channel->value, UpdateNotifier::label($status->latest), $status->aheadBy ?? '?'),
            UpdateVerdict::Current   => sprintf('Up to date with %s (%s).', $channel->value, UpdateNotifier::label($status->latest)),
            UpdateVerdict::Unknown   => sprintf('Newest on %s is %s; this build has no commit to compare it with.', $channel->value, UpdateNotifier::label($status->latest)),
        });

        return Command::SUCCESS;
    }
}
