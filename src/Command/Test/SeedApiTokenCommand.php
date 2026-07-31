<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Entity\User\ApiToken;
use App\Repository\User\ApiTokenRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Mints an app password for a test user, so a native client can be pointed at
 * a throwaway stack without a human driving Settings → App passwords.
 *
 * Everything else a client needs can already be seeded from the console; this
 * was the one step that could not, which made an unattended run of the iOS or
 * Android suites impossible.
 *
 * NOT idempotent in the usual sense, and it cannot be: only a SHA-256 digest of
 * the secret is stored, so a second run has no way to reprint the first run's
 * password. Re-running therefore revokes any active token of the same name and
 * mints a fresh one — the alternative, refusing when one exists, would leave
 * the caller holding a name it cannot get a working secret for, and letting
 * them accumulate would quietly grow a pile of live credentials on a box whose
 * whole point is to be disposable.
 *
 * The secret is written at QUIET verbosity, which is the one level `-q` does
 * not suppress, so a script gets exactly the credential and nothing else:
 *
 *     bin/console app:test:seed-api-token -q
 *
 * Refuses to run in prod — this is a test-fixture tool, and a console command
 * that mints live credentials has no business anywhere near a real instance.
 */
#[AsCommand(
    name: 'app:test:seed-api-token',
    description: 'Mint an app password for a test user and print the secret once',
)]
final class SeedApiTokenCommand extends Command
{
    use TargetsTestUser;

    private const string DEFAULT_TOKEN_NAME = 'Test client';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository         $userRepository,
        private readonly ApiTokenRepository     $apiTokenRepository,
        #[Autowire('%kernel.environment%')]
        private readonly string                 $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->configureUserOption();

        $this->addOption(
            'name',
            null,
            InputOption::VALUE_REQUIRED,
            'What to call the app password; re-running with the same name replaces it',
            self::DEFAULT_TOKEN_NAME,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('app:test:seed-api-token must not run in the prod environment.');

            return Command::FAILURE;
        }

        $userEmail = $this->resolveUserEmail($input);
        $user      = $this->userRepository->findOneBy(['email' => $userEmail]);

        if (null === $user) {
            $io->error(sprintf('Test user "%s" not found — run app:test:seed-user first.', $userEmail));

            return Command::FAILURE;
        }

        $name = $input->getOption('name');
        $name = is_string($name) && '' !== trim($name) ? trim($name) : self::DEFAULT_TOKEN_NAME;

        $revoked = 0;

        foreach ($this->apiTokenRepository->findForUser($user) as $existing) {
            if ($existing->name === $name) {
                $existing->revoke();
                ++$revoked;
            }
        }

        ['token' => $token, 'secret' => $secret] = ApiToken::create($user, $name);

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        if ($revoked > 0) {
            $io->note(sprintf('Revoked %d earlier app password(s) named "%s".', $revoked, $name));
        }

        $io->success(sprintf('Minted "%s" for %s. It is shown once and never again.', $name, $userEmail));

        // QUIET rather than the default so `-q` yields the bare secret and
        // nothing else, which is what any script wants out of this.
        $output->writeln($secret, OutputInterface::VERBOSITY_QUIET);

        return Command::SUCCESS;
    }
}
