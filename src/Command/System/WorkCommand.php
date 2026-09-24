<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Domain\DTO\System\BackgroundProcess;
use App\Service\System\Work\BackgroundProcesses;
use App\Service\System\Work\ProcessSupervisor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The worker container: every background process plMail runs, in one place.
 *
 * The queue consumers, the scheduler, the IMAP supervisor and the Mercure hub
 * used to be seven containers of one image. They are now the children of this
 * command, still one process each (see BackgroundProcesses for why that part
 * cannot change) and kept alive the way Docker kept the containers alive (see
 * ProcessSupervisor).
 *
 * --only and --without pick a subset, for the two cases that want one: an
 * installation that splits its busiest queue into a container of its own, and
 * the demo, which has no IMAP server for the supervisor to reach.
 *
 * Run it under an init (compose's `init: true`). The IMAP supervisor's own
 * children are this command's grandchildren, and if the supervisor dies hard,
 * something has to reap them.
 */
#[AsCommand(
    name: 'app:work',
    description: 'Run every background process in one container: the queue workers, the scheduler, the IMAP supervisor and the Mercure hub.',
)]
final class WorkCommand extends Command implements SignalableCommandInterface
{
    /** Below the compose files' stop_grace_period of 30s, so Docker does not kill the lot first. */
    private const int GRACE_SECONDS = 25;

    private bool $stopping = false;

    public function __construct(
        private readonly BackgroundProcesses $processes,
        private readonly ProcessSupervisor   $supervisor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Run only these, comma-separated (e.g. worker-bulk)')
            ->addOption('without', null, InputOption::VALUE_REQUIRED, 'Run everything but these, comma-separated (e.g. imap-supervisor)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $chosen = $this->choose((string) $input->getOption('only'), (string) $input->getOption('without'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $io->writeln(sprintf('app:work: running %s', implode(', ', array_map(
            static fn (BackgroundProcess $process): string => $process->name,
            $chosen,
        ))));

        $this->supervisor->run($chosen, fn (): bool => $this->stopping, self::GRACE_SECONDS);

        $io->writeln('app:work: stopped');

        return Command::SUCCESS;
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return [SIGTERM, SIGINT];
    }

    /**
     * Stop, but not here: `false` lets execute() finish, and it is execute()
     * that passes the signal on to every child and waits for them.
     */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stopping = true;

        return false;
    }

    /**
     * @return list<BackgroundProcess>
     *
     * @throws \InvalidArgumentException naming what is not a process, and what is
     */
    private function choose(string $only, string $without): array
    {
        $known  = $this->processes->names();
        $only   = $this->names($only);
        $skip   = $this->names($without);
        $wrong  = array_diff([...$only, ...$skip], $known);

        if ([] !== $wrong) {
            throw new \InvalidArgumentException(sprintf('No such process: %s. There are: %s.', implode(', ', $wrong), implode(', ', $known)));
        }

        $chosen = array_values(array_filter(
            $this->processes->all(),
            static fn (BackgroundProcess $process): bool => ([] === $only || in_array($process->name, $only, true))
                && false === in_array($process->name, $skip, true),
        ));

        if ([] === $chosen) {
            throw new \InvalidArgumentException('That leaves nothing to run.');
        }

        return $chosen;
    }

    /** @return list<string> */
    private function names(string $list): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $list)), static fn (string $name): bool => '' !== $name));
    }
}
