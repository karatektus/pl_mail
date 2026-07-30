<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seeds a single, known user for end-to-end tests.
 *
 * Idempotent: find-or-create by email, and the password is (re)hashed on
 * every run so the credentials always match what the E2E suite expects.
 *
 * Credentials default to the APP_DEV_USER_EMAIL / APP_DEV_USER_PASSWORD
 * environment variables so the console command, the login template prefill,
 * and the Playwright specs all read from a single source of truth.
 *
 * Refuses to run in prod — this is a test-fixture tool.
 */
#[AsCommand(
    name: 'app:test:seed-user',
    description: 'Create or update the known user used by the end-to-end test suite',
)]
final class SeedTestUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface      $entityManager,
        private readonly UserRepository              $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%kernel.environment%')]
        private readonly string                      $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Override the seeded email')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Override the seeded password')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Seed with ROLE_ADMIN as well');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('app:test:seed-user must not run in the prod environment.');

            return Command::FAILURE;
        }

        $email = $input->getOption('email')
            ?? $_SERVER['APP_DEV_USER_EMAIL']
            ?? 'e2e@plmail.test';

        $plainPassword = $input->getOption('password')
            ?? $_SERVER['APP_DEV_USER_PASSWORD']
            ?? 'e2e-password-change-me';

        // Seeded up front rather than promoted later: changing a user's roles
        // makes Symfony consider the token stale and deauthenticates every
        // existing session, so promoting mid-suite would log out the shared
        // storage state the other specs run on.
        $roles = [User::ROLE_USER];

        if (true === $input->getOption('admin')) {
            $roles[] = User::ROLE_ADMIN;
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        $created = false;

        if (null === $user) {
            $user = new User();
            $user
                ->setEmail($email)
                ->setNameFirst('E2E')
                ->setNameLast('Tester');

            $created = true;
        }

        $user->setRoles($roles);

        $user->setPassword(
            $this->passwordHasher->hashPassword($user, $plainPassword)
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        if (true === $created) {
            $io->success(sprintf('Created E2E user "%s".', $email));
        } else {
            $io->success(sprintf('Updated E2E user "%s".', $email));
        }

        return Command::SUCCESS;
    }
}
