<?php

declare(strict_types=1);

namespace App\Command\Setup;

use App\Repository\User\UserRepository;
use App\Service\User\TwoFactor\TwoFactorEnrolment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The way back in after losing the phone and the recovery codes.
 *
 * plMail runs on hardware its user owns, which is the whole reason this can
 * exist: shell access to the box already implies database access, so this
 * grants nothing that was not already available with a psql prompt and more
 * patience. What it buys is that the recovery path is a documented command
 * rather than hand-written UPDATE statements against the `user` table, which
 * is where somebody in a hurry gets it wrong and clears the wrong column.
 *
 * Deliberately not exposed to administrators through the web UI. An admin who
 * can strip another user's second factor from a browser is a second way into
 * every mailbox on the install, reachable with nothing but a stolen admin
 * session — which would give back most of what enabling 2FA was for.
 */
#[AsCommand(
    name: 'app:user:2fa-disable',
    description: 'Turn off two-factor authentication for a user locked out of their account',
)]
final class DisableTwoFactorCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TwoFactorEnrolment $enrolment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email of the user to unlock')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $user = $this->userRepository->findOneBy(['email' => $email]);

        if (null === $user) {
            $io->error(sprintf('No user found for email "%s".', $email));

            return Command::FAILURE;
        }

        if (false === $user->isTotpAuthenticationEnabled()) {
            $io->info(sprintf('Two-factor authentication is already off for %s.', $email));

            return Command::SUCCESS;
        }

        $io->warning(sprintf(
            'This removes the second factor from %s. Their mailbox will be protected by their password alone until they set it up again.',
            $email,
        ));

        if (false === (bool) $input->getOption('force') && false === $io->confirm('Continue?', false)) {
            $io->comment('Nothing was changed.');

            return Command::SUCCESS;
        }

        // Discards the secret, the recovery codes and every trusted device —
        // see TwoFactorEnrolment::disable().
        $this->enrolment->disable($user);

        $io->success(sprintf('Two-factor authentication is off for %s.', $email));
        $io->comment('Tell them to set it up again from Settings → Security once they are back in.');

        return Command::SUCCESS;
    }
}
