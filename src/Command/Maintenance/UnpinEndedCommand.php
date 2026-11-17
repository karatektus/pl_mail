<?php

declare(strict_types=1);

namespace App\Command\Maintenance;

use App\Service\Mail\ThreadPinService;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Takes the pin off conversations whose pin has run out (#654).
 *
 * Not what makes a pin end on screen: the lists compare the end time with the
 * clock themselves (ListSortOrder::pinnedFirst()), so an ended pin drops out of
 * the top the moment it ends. This is for the JMAP clients, whose copy of the
 * $pinned keyword only changes when a change is recorded for them, and for the
 * columns, which would otherwise keep an ended pin forever.
 */
#[AsCommand(
    name: 'app:mail:unpin-ended',
    description: 'Take the pin off conversations whose pin has run out.',
)]
final class UnpinEndedCommand extends Command
{
    /** Per run, so a backlog after downtime cannot hold the worker; the next run takes the rest. */
    private const int BATCH = 500;

    public function __construct(
        private readonly ThreadPinService $pins,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $cleared = $this->pins->clearEnded(new DateTimeImmutable(), self::BATCH);

        if (0 < $cleared) {
            $io->text(sprintf('Unpinned %d conversation(s) whose pin ran out.', $cleared));
        }

        return Command::SUCCESS;
    }
}
