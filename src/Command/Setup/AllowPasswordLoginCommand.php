<?php

declare(strict_types=1);

namespace App\Command\Setup;

use App\Repository\Oidc\OidcConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Turns "single sign-on only" off again from the console.
 *
 * The way back in when the identity provider is down, or was removed from
 * under an install that had made it the only way in: the login page shows no
 * password form and the server refuses a password, so nothing in a browser can
 * undo the setting. Console-only for the reason app:user:password is — what
 * can be done from a browser can be done by whoever has a session.
 *
 * It clears what Admin → SSO saved. A setting made with OIDC_DISABLE_PASSWORD_LOGIN
 * is the operator's own file and is said so rather than edited.
 */
#[AsCommand(
    name: 'app:sso:allow-password-login',
    description: 'Show the password form again after single sign-on was made the only way in',
)]
final class AllowPasswordLoginCommand extends Command
{
    public function __construct(
        private readonly OidcConfigRepository   $configs,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $config = $this->configs->current();

        if (null !== $config && true === $config->passwordLoginDisabled) {
            $config->passwordLoginDisabled = false;
            $this->entityManager->flush();

            $io->success('Password sign-in is allowed again. Single sign-on stays on beside it.');
        } else {
            $io->note('Admin → SSO does not hold "single sign-on only", so there is nothing to clear there.');
        }

        $io->writeln('If OIDC_DISABLE_PASSWORD_LOGIN is set to true in the environment, change it there and restart: this command does not touch it.');

        return Command::SUCCESS;
    }
}
