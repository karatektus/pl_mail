<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Entity\Label;
use App\Repository\AccountRepository;
use App\Repository\LabelRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Seeds one visible custom label ("E2E Label") on the E2E mail account so the
 * "Label as" attach flow has something to attach. Idempotent; refuses prod.
 *
 * Mirrors what LabelController::new persists: a Label with account + name
 * (+ colour), role left null (custom), everything else default. Fields are
 * assigned as public properties to match the entity's property-hook style.
 */
#[AsCommand(
    name: 'app:test:seed-label',
    description: 'Seed a custom label on the E2E mail account for the label-attach test',
)]
final class SeedTestLabelCommand extends Command
{
    private const string SEED_ACCOUNT_USERNAME = 'mailbox@e2e.test';
    private const string LABEL_NAME            = 'E2E Label';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository         $userRepository,
        private readonly AccountRepository      $accountRepository,
        private readonly LabelRepository        $labelRepository,
        #[Autowire('%kernel.environment%')]
        private readonly string                 $environment,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('app:test:seed-label must not run in the prod environment.');

            return Command::FAILURE;
        }

        $userEmail = $_SERVER['APP_DEV_USER_EMAIL'] ?? 'e2e@plmail.test';
        $user      = $this->userRepository->findOneBy(['email' => $userEmail]);

        if (null === $user) {
            $io->error(sprintf('E2E user "%s" not found — run app:test:seed-user first.', $userEmail));

            return Command::FAILURE;
        }

        $account = $this->accountRepository->findOneBy([
            'usr'      => $user,
            'username' => self::SEED_ACCOUNT_USERNAME,
        ]);

        if (null === $account) {
            $io->error('E2E mail account not found — run app:test:seed-mail first.');

            return Command::FAILURE;
        }

        $existing = $this->labelRepository->findOneBy([
            'account' => $account,
            'name'    => self::LABEL_NAME,
        ]);

        if (null !== $existing) {
            $io->success(sprintf('Custom label "%s" already present.', self::LABEL_NAME));

            return Command::SUCCESS;
        }

        $label = new Label();
        $label->account = $account;
        $label->name    = self::LABEL_NAME;
        $label->color   = 'blue';

        $this->entityManager->persist($label);
        $this->entityManager->flush();

        $io->success(sprintf('Seeded custom label "%s".', self::LABEL_NAME));

        return Command::SUCCESS;
    }
}
