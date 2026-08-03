<?php

declare(strict_types=1);

namespace App\Command\Setup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `doctrine:migrations:migrate`, but only one container at a time.
 *
 * A plMail stack starts four containers from the same image — php,
 * imap-supervisor, messenger-worker and scheduler — and every one of them runs
 * `frankenphp/docker-entrypoint.sh`, which migrates on boot. They come up
 * within milliseconds of each other against one database, all four read the
 * ledger before any of them has written to it, and all four decide the same
 * migration is pending. One wins; the rest block on the table lock it holds,
 * are released when it commits, and then fail on a schema that has already
 * moved:
 *
 *     SQLSTATE[42701]: Duplicate column: 7
 *     ERROR: column "timezone" of relation "user" already exists
 *
 * `--all-or-nothing` widens that window rather than closing it: every pending
 * migration goes into one transaction, so the losers are held for as long as
 * the whole batch takes instead of one statement. Under `set -e` in the
 * entrypoint the failure aborts the boot, and three of the four services never
 * start. Reproduced with four concurrent migrates against one database: three
 * exited 7 with the message above.
 *
 * The fix is a Postgres advisory lock held across the whole run, so the losers
 * arrive after the winner has committed AND recorded the version, read a ledger
 * that is already current, and exit 0 having found nothing to do. They still
 * run the migration — skipping it would mean a container coming up against a
 * schema nobody verified.
 *
 * Why this cannot be a line of shell. `pg_advisory_lock()` is scoped to the
 * database session, so `bin/console dbal:run-sql "SELECT pg_advisory_lock(…)"`
 * takes the lock and gives it straight back when the process exits — the next
 * statement is unprotected. The lock has to be held by the same connection that
 * runs the migration, which means one PHP process doing both. That is this one.
 */
#[AsCommand(
    name: 'app:db:migrate',
    description: 'Run pending migrations under a lock, so several containers booting together cannot collide.',
)]
final class MigrateCommand extends Command
{
    /**
     * crc32('plmail:doctrine-migrations').
     *
     * Any stable number would do — what matters is that every container of a
     * given install computes the same one, which rules out anything derived at
     * runtime. Hashing a string the project owns means it is reproducible from
     * the source rather than being a magic number nobody may change.
     *
     * The scope is already right without any effort: Postgres advisory locks
     * live in one database, so two plMail installs sharing a server never see
     * each other's, and no key needs to carry the database name.
     */
    private const int LOCK_KEY = 1227082528;

    /**
     * How long a container waits for whoever is migrating before it gives up,
     * in seconds.
     *
     * Blocking forever is not an option: one container that dies holding the
     * lock — OOM-killed mid-migration, say — would otherwise hang every other
     * service in the stack indefinitely, with no output to say why. Five
     * minutes is long enough for any migration this project has ever shipped
     * and short enough that a stuck stack reports itself.
     */
    private const int DEFAULT_LOCK_TIMEOUT = 300;

    /** Between attempts. Contention lasts seconds, so polling is cheap. */
    private const int POLL_INTERVAL_MICROSECONDS = 250_000;

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'lock-timeout',
            null,
            InputOption::VALUE_REQUIRED,
            'Seconds to wait for another container to finish migrating before failing',
            (string) self::DEFAULT_LOCK_TIMEOUT,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Everything plMail ships runs on Postgres, but the connection is an
        // operator's to point wherever they like. On anything else the advisory
        // lock functions do not exist and asking for them would turn "your
        // database is unusual" into "your container will not start" — so
        // migrate unprotected, which is exactly what happened before this
        // command existed.
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return $this->runMigrations($output);
        }

        $timeout = max(0, (int) $input->getOption('lock-timeout'));

        if (!$this->acquireLock($io, $timeout)) {
            $io->error(sprintf(
                'Timed out after %ds waiting for another container to finish running migrations. '
                .'Refusing to migrate: whatever holds the lock may still be mid-migration, and a '
                .'second migrate on top of it is what this lock exists to prevent. Check whether '
                .'another container is stuck, then start this one again.',
                $timeout,
            ));

            return Command::FAILURE;
        }

        try {
            return $this->runMigrations($output);
        } finally {
            $this->releaseLock($io);
        }
    }

    /**
     * The real command, with the flags the entrypoint used to pass it.
     *
     * Run through the console application rather than reimplemented, so the
     * output, the exit codes and the behaviour of every edge case stay
     * Doctrine's and not a second copy of them that drifts.
     */
    private function runMigrations(OutputInterface $output): int
    {
        $application = $this->getApplication();

        if (null === $application) {
            throw new \RuntimeException('app:db:migrate has to be run through the console application.');
        }

        $migrate = new ArrayInput([
            'command' => 'doctrine:migrations:migrate',
            // Deliberately null and not true: --all-or-nothing takes an
            // optional value, and passing one is deprecated. null is how the
            // console represents "the flag was given, bare", which is what the
            // entrypoint's command line produced.
            '--all-or-nothing' => null,
        ]);

        // The entrypoint's --no-interaction. Without it a container with a TTY
        // would sit on Doctrine's "are you sure?" prompt forever.
        $migrate->setInteractive(false);

        return $application->find('doctrine:migrations:migrate')->run($migrate, $output);
    }

    /**
     * Polls rather than blocking on pg_advisory_lock() so that giving up is
     * possible at all — a blocking acquire cannot be interrupted from PHP, and
     * a stuck winner would take the whole stack down with it.
     */
    private function acquireLock(SymfonyStyle $io, int $timeout): bool
    {
        $deadline  = microtime(true) + $timeout;
        $announced = false;

        while (true) {
            if ($this->tryLock()) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            if (!$announced) {
                $io->text('Another container is running migrations; waiting for it to finish...');
                $announced = true;
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }
    }

    /**
     * The one place in this project where SQL lives outside a repository, and
     * deliberately so.
     *
     * `pg_try_advisory_lock` reads nothing and returns no rows — it is a lock
     * primitive, not a query over domain data, so there is no entity it belongs
     * to and no repository it would be at home in. More decisively, an advisory
     * lock is scoped to the database SESSION: it has to be taken, held and
     * released on the very connection that runs the migration. Routing it
     * through a collaborator would put a second object between this command and
     * the only property that makes the lock work, for no gain.
     *
     * Cast to int in SQL: pg_try_advisory_lock returns a boolean, and what a
     * driver hands back for one is its own business — true, 't' and '1' are all
     * plausible. An integer is not.
     */
    private function tryLock(): bool
    {
        return 1 === (int) $this->connection->fetchOne(
            'SELECT pg_try_advisory_lock(?)::int',
            [self::LOCK_KEY],
        );
    }

    private function releaseLock(SymfonyStyle $io): void
    {
        try {
            $this->connection->executeQuery('SELECT pg_advisory_unlock(?)', [self::LOCK_KEY]);
        } catch (\Throwable $error) {
            // Worth saying, not worth failing over. The lock belongs to this
            // session, so it is gone the moment the process is — the only cost
            // of an unlock that did not happen is the next container waiting
            // out the few milliseconds until PHP exits.
            $io->warning(sprintf('Could not release the migration lock: %s', $error->getMessage()));
        }
    }
}
