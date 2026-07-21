<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user:promote',
    description: 'Grant (or revoke with --revoke) ROLE_ADMIN for a user by email',
)]
final class PromoteUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository         $userRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email of the user to promote')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Revoke ROLE_ADMIN instead of granting it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $email  = (string) $input->getArgument('email');
        $revoke = true === (bool) $input->getOption('revoke');

        $user = $this->userRepository->findOneBy(['email' => $email]);

        if (null === $user) {
            $io->error(sprintf('No user found for email "%s".', $email));

            return Command::FAILURE;
        }

        $roles = $user->getRoles();

        if (true === $revoke) {
            $roles = array_values(array_diff($roles, ['ROLE_ADMIN']));
        } else {
            if (false === in_array('ROLE_ADMIN', $roles, true)) {
                $roles[] = 'ROLE_ADMIN';
            }
        }

        // ROLE_USER is implied by getRoles(); persist without it to keep the
        // stored list minimal.
        $user->setRoles(array_values(array_diff($roles, ['ROLE_USER'])));
        $this->em->flush();

        $io->success(sprintf(
            '%s ROLE_ADMIN for %s.',
            true === $revoke ? 'Revoked' : 'Granted',
            $email,
        ));

        return Command::SUCCESS;
    }
}
