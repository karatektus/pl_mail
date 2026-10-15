<?php

declare(strict_types=1);

namespace App\Command\Setup;

use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Forgets which provider identity an account signs in as.
 *
 * The way out of the one refusal single sign-on cannot resolve by itself: the
 * account is linked to a subject that no longer exists. Deleting somebody at
 * the provider and adding them again gives them a new `sub` — Authentik's is
 * derived from its own user id — and OidcUserResolver then sees a second
 * person claiming a linked account by address, which it refuses on purpose.
 * Nothing but clearing the link lets that person back in, and until this
 * existed that meant an UPDATE typed against the `user` table.
 *
 * Console-only, for the reason app:user:password and app:user:2fa-disable are:
 * an unlinked account is one the next identity with its address can claim, so
 * an administrator who could do this from a browser would have a way into
 * somebody else's mailbox with nothing but a session and a second login at the
 * provider.
 */
#[AsCommand(
    name: 'app:user:oidc-unlink',
    description: 'Forget the single sign-on identity an account is linked to, so its next sign-in links it again by address',
)]
final class UnlinkOidcIdentityCommand extends Command
{
    public function __construct(
        private readonly UserRepository         $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email of the account to unlink');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $user = $this->userRepository->findOneBy(['email' => $email]);

        if (null === $user) {
            $io->error(sprintf('No user found for email "%s".', $email));

            return Command::FAILURE;
        }

        if (null === $user->oidcSubject) {
            $io->info(sprintf('%s is not linked to a single sign-on identity.', $email));

            return Command::SUCCESS;
        }

        $subject = $user->oidcSubject;

        $user->oidcIssuer  = null;
        $user->oidcSubject = null;
        $this->entityManager->flush();

        $io->success(sprintf('%s is no longer linked to subject "%s".', $email, $subject));
        $io->comment('Their next sign-in through the provider links the account again, by its address.');

        return Command::SUCCESS;
    }
}
