<?php

declare(strict_types=1);

namespace App\Command\Setup;

use App\Repository\Monitoring\PostgresStatusRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Waits at boot for the database to answer, and logs only if it never does.
 *
 * `frankenphp/docker-entrypoint.sh` runs this in every container before it
 * migrates. It replaces a shell loop that ran `dbal:run-sql -q "SELECT 1"` once
 * a second, where every attempt that failed was an uncaught console exception —
 * which Symfony logs as CRITICAL. A database a few seconds behind the app left
 * CRITICAL rows behind although nothing was wrong: waiting for it was the
 * loop's whole job, and it had done it.
 *
 * Those were also the ONLY rows the loop could leave in the admin log. That log
 * is a table in the database being waited for, so a failed attempt reached it
 * only when the database answered a few milliseconds later; a database that
 * stayed away recorded nothing. The dashboard showed exactly the failures that
 * did not matter, as a red outline on the user menu — seen 2026-09-25 with
 * `could not translate host name "database"`, after Docker started php ahead of
 * the database container.
 *
 * So a miss is retried here, in one process, and logs nothing. The one record
 * this writes is the one that means something: the last error, once every
 * attempt has failed. Progress goes to the console, which is the container log,
 * as it always did.
 */
#[AsCommand(
    name: 'app:db:wait',
    description: 'Wait for the database to answer, and log an error only if it never does.',
)]
final class WaitForDatabaseCommand extends Command
{
    /**
     * Once a second for a minute — what the shell loop allowed. Long enough for
     * a Postgres recovering from an unclean stop; one that takes longer is the
     * actual problem this exists to report.
     */
    private const int DEFAULT_ATTEMPTS = 60;

    public function __construct(
        private readonly PostgresStatusRepository $database,
        private readonly LoggerInterface $logger,
        // Between attempts. A parameter only so a test need not sleep through it.
        private readonly int $pauseMicroseconds = 1_000_000,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'attempts',
            null,
            InputOption::VALUE_REQUIRED,
            'How many times to ask, a second apart, before giving up',
            (string) self::DEFAULT_ATTEMPTS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $attempts = max(1, (int) $input->getOption('attempts'));
        $attempt  = 0;

        while (true) {
            ++$attempt;

            $error = $this->database->whyUnreachable();

            if (null === $error) {
                return Command::SUCCESS;
            }

            if ($attempt >= $attempts) {
                return $this->giveUp(new SymfonyStyle($input, $output), $attempts, $error);
            }

            $left = $attempts - $attempt;

            $output->writeln(sprintf(
                'Still waiting for database to be ready... Or maybe the database is not reachable. %d %s left.',
                $left,
                1 === $left ? 'attempt' : 'attempts',
            ));

            usleep($this->pauseMicroseconds);
        }
    }

    /**
     * The actual problem, said twice on purpose: on the console for whoever
     * reads the container log, and to the logger for stderr's JSON. The admin
     * log will rarely get it — its table is in the database that did not
     * answer — which is why the console line is not left to the logger.
     */
    private function giveUp(SymfonyStyle $io, int $attempts, \Throwable $error): int
    {
        $io->error(sprintf('The database is not up or not reachable: %s', $error->getMessage()));

        $this->logger->critical('The database did not answer in {attempts} attempts: {error}', [
            'attempts'  => $attempts,
            'error'     => $error->getMessage(),
            'exception' => $error,
        ]);

        return Command::FAILURE;
    }
}
