<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Enum\PushHealth;
use App\Repository\AccountRepository;
use App\Service\Push\PushSubscriptionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renews push registrations across every provider.
 *
 * Replaces app:gmail:renew-watches and the provider-specific Graph command.
 * Both had the same body with a different service in the middle, and both
 * predated the pushEnabled flag — the Gmail one renewed watches for every
 * active Gmail account regardless of whether the user wanted push at all.
 *
 * Schedule daily; the renewal thresholds (24h for Gmail's 7-day watches, 12h
 * for Graph's ~3-day subscriptions) give ample headroom.
 *
 *   0 4 * * *  php bin/console app:push:renew
 *
 * This does NOT replace app:mail:sync. Neither provider guarantees delivery,
 * so scheduled polling stays the backstop.
 */
#[AsCommand(
    name: 'app:push:renew',
    description: 'Renew Gmail watches and Graph subscriptions that are close to expiring',
)]
final class PushRenewCommand extends Command
{
    public function __construct(
        private readonly AccountRepository        $accountRepository,
        private readonly PushSubscriptionRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Renew every push account regardless of expiry')
            ->addOption('repair', null, InputOption::VALUE_NONE, 'Also re-register accounts whose push is degraded');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $force  = true === $input->getOption('force');
        $repair = true === $input->getOption('repair');

        $accounts = $this->accountRepository->findBy([
            'isActive'    => true,
            'pushEnabled' => true,
        ]);

        if (count($accounts) === 0) {
            $io->info('No accounts have push enabled.');

            return Command::SUCCESS;
        }

        $renewed = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($accounts as $account) {
            $manager = $this->registry->resolve($account);

            if (null === $manager) {
                $skipped++;
                continue;
            }

            $due = $force
                || $manager->needsRenewal($account)
                || (true === $repair && PushHealth::Degraded === $manager->health($account));

            if (false === $due) {
                $skipped++;
                continue;
            }

            if (true === $manager->renew($account)) {
                $io->text(sprintf('→ renewed %s (#%d)', $account->getEmail(), $account->getId()));
                $renewed++;
                continue;
            }

            $io->warning(sprintf(
                'could not renew %s (#%d) — it stays on polling',
                $account->getEmail(),
                $account->getId(),
            ));
            $failed++;
        }

        $io->success(sprintf('%d renewed, %d still valid, %d failed.', $renewed, $skipped, $failed));

        if ($failed > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
