<?php

declare(strict_types=1);

namespace App\Command\Demo;

use App\Repository\User\UserRepository;
use App\Service\Demo\DemoMode;
use App\Service\Demo\DemoProvisioner;
use App\Service\Demo\DemoUserEraser;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Clears out demo visitors whose time is up.
 *
 * A public demo mints a user per visitor and never hears from most of them
 * again, so without this the database grows by one user, one account, ten
 * threads and ten contacts for every person who ever followed the link. The
 * scheduler runs it every ten minutes.
 *
 * Selection is by the stamped expiry, and only over addresses demo mode
 * recognises as its own — DemoUserEraser checks that again before deleting
 * anything, because this is the one destructive thing in the feature and it
 * runs unattended.
 *
 * A user with no expiry stamp is left alone rather than treated as expired.
 * That is the safe direction for the failure that matters: an administrator
 * signed into their own demo instance has no stamp, and a reaper that read a
 * missing date as "long overdue" would delete them.
 */
#[AsCommand(
    name: 'app:demo:reap',
    description: 'Delete expired demo users and everything they own',
)]
final class ReapDemoUsersCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly DemoUserEraser $eraser,
        private readonly DemoMode       $demoMode,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'List what would be deleted without deleting it',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (false === $this->demoMode->isEnabled()) {
            $io->note('Demo mode is off — nothing to reap.');

            return Command::SUCCESS;
        }

        $dryRun = true === $input->getOption('dry-run');
        $now    = new DateTimeImmutable();
        $reaped = 0;

        foreach ($this->users->findAll() as $user) {
            if (false === $this->demoMode->ownsAddress($user->email)) {
                continue;
            }

            if (false === $this->hasExpired($user->getSetting(DemoProvisioner::SETTING_EXPIRES_AT), $now)) {
                continue;
            }

            if (true === $dryRun) {
                $io->text(sprintf('would delete %s', (string) $user->email));
                ++$reaped;

                continue;
            }

            try {
                $this->eraser->erase($user);
                ++$reaped;
            } catch (Throwable $e) {
                // One visitor's leftovers must not stop the sweep: the next run
                // is ten minutes away and the rest of the backlog would sit
                // through all of them. Logged rather than swallowed, because a
                // reaper that cannot delete is a database that only grows.
                $this->logger->error('Failed to reap demo user', [
                    'email'     => $user->email,
                    'exception' => $e,
                ]);

                $io->warning(sprintf('Could not delete %s: %s', (string) $user->email, $e->getMessage()));
            }
        }

        $io->success(sprintf(
            '%s %d expired demo user(s).',
            true === $dryRun ? 'Would delete' : 'Deleted',
            $reaped,
        ));

        return Command::SUCCESS;
    }

    /**
     * An unparseable stamp counts as not expired, for the reason the class
     * docblock gives: every ambiguity here resolves towards keeping the user.
     */
    private function hasExpired(mixed $stamp, DateTimeImmutable $now): bool
    {
        if (false === is_string($stamp) || '' === $stamp) {
            return false;
        }

        try {
            return new DateTimeImmutable($stamp) < $now;
        } catch (\Exception) {
            return false;
        }
    }
}
