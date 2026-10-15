<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Domain\Enum\Calendar\CalendarPaneMode;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Security\PasswordKdf;
use App\Service\Onboarding\OnboardingFlow;
use DateTimeImmutable;
use DateTimeInterface;
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
        private readonly OnboardingFlow              $onboardingFlow,
        private readonly PasswordKdf                 $kdf,
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
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Seed with ROLE_ADMIN as well')
            ->addOption('pending-onboarding', null, InputOption::VALUE_NONE, 'Leave setup unfinished so the wizard opens on first page load')
            ->addOption('stretched', null, InputOption::VALUE_NONE, 'Store the password as an already-moved account would: stretched here, as a browser would')
            ->addOption('change-required', null, InputOption::VALUE_NONE, 'Flag the password as chosen by somebody else, so the first sign-in asks for a new one')
            ->addOption('without-password', null, InputOption::VALUE_NONE, 'No usable password, as single sign-on creates an account: the keyring then hangs off an encryption passphrase');
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
            $user->email = $email;
            $user->nameFirst = 'E2E';
            $user->nameLast = 'Tester';

            $created = true;
        }

        $user->roles = $roles;

        // Server-hashed by default, as an administrator's would be, so every
        // spec's first sign-in walks the same path a real account's first one
        // after the update does. `--stretched` stores it as a moved account
        // holds it, stretched by PasswordKdf::derive() — which is what lets
        // auth.spec.ts prove a real browser stretches the same way PHP does.
        if (true === $input->getOption('stretched')) {
            $salt = base64_encode(random_bytes(PasswordKdf::SALT_BYTES));

            $user->useClientDerivedPassword(
                $this->passwordHasher->hashPassword($user, PasswordKdf::derive($plainPassword, $salt, $this->kdf->iterations)),
                $salt,
                $this->kdf->iterations,
            );
        } else {
            $user->useServerHashedPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        }

        // An account single sign-on made: the empty hash OidcUserResolver
        // writes, and an identity at a provider that does not exist. Such an
        // account is signed in through `app:test:remember-me`, since the form
        // cannot sign it in.
        if (true === $input->getOption('without-password')) {
            $user->password    = '';
            $user->oidcIssuer  = 'https://sso.plmail.test';
            $user->oidcSubject = $email;
        }

        // Off unless asked for: the stored login the other specs share has to
        // land on the inbox.
        $user->passwordChangeRequired = true === $input->getOption('change-required');

        // Before the onboarding block, which flushes through OnboardingFlow: a
        // brand-new user has to be managed by then, or that flush writes
        // everything except the row it is about.
        $this->entityManager->persist($user);

        // Finished by default, and this is not cosmetic. The setup wizard opens
        // itself over a backdrop that is fixed inset-0 z-50 and swallows every
        // click, so a seeded user who is still pending would break every spec
        // that shares the stored login. The onboarding spec asks for a pending
        // one explicitly, with an email of its own.
        if (true === $input->getOption('pending-onboarding')) {
            // Every key, not just the completion stamp. "Pending" has to mean
            // "has never seen the wizard", because the resume point is what
            // decides which step it opens on: clearing only the stamp reseeds a
            // user who reopens at whatever step the last run left off, so the
            // onboarding spec passed once and then walked into the middle of
            // the flow on every re-run.
            $this->onboardingFlow->reset($user);
        } else {
            $user->setSetting(
                User::SETTING_ONBOARDING_COMPLETED_AT,
                (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            );
        }

        // The mail alone, not the product's default. A new user opens with the
        // calendar docked beside the mail on a desktop, and the suite was
        // written against a list with the whole row. A docked pane narrows it
        // until row actions are display:none, which fails as missing buttons
        // rather than as a layout. SeedTestEmailCommand puts the same furniture
        // back on every mail seed; this covers the specs that never seed mail.
        $user->calendarPaneMode = CalendarPaneMode::Mail;

        $this->entityManager->flush();

        if (true === $created) {
            $io->success(sprintf('Created E2E user "%s".', $email));
        } else {
            $io->success(sprintf('Updated E2E user "%s".', $email));
        }

        return Command::SUCCESS;
    }
}
