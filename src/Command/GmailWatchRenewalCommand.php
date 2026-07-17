<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AccountRepository;
use App\Service\Gmail\GmailWatchService;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renews Gmail push-notification watches expiring within 24 hours.
 *
 * Run daily (cron or Symfony Scheduler):
 *   0 6 * * * php bin/console app:gmail:renew-watches
 *
 * Watches expire after at most 7 days, so a daily renewal gives a 6-day buffer.
 */
#[AsCommand(
    name: 'app:gmail:renew-watches',
    description: 'Renew Gmail push-notification watches expiring within 24 hours',
)]
final class GmailWatchRenewalCommand extends Command
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly GmailWatchService $watchService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Gmail watch renewal');

        $accounts = $this->accountRepository->findBy(['isActive' => true]);
        $renewed  = 0;
        $skipped  = 0;

        foreach ($accounts as $account) {
            if (false === $account->isGmail()) {
                $skipped++;
                continue;
            }

            $expiry       = $account->getGmailWatchExpiry();
            $needsRenewal = (null === $expiry)
                || ($expiry <= new DateTimeImmutable('+24 hours'));

            if (false === $needsRenewal) {
                $skipped++;
                continue;
            }

            try {
                $this->watchService->watch($account);
                $io->text(sprintf('✓ Renewed watch for %s', $account->getEmail()));
                $renewed++;
            } catch (\Throwable $e) {
                $io->error(sprintf(
                    'Failed to renew watch for account %d: %s',
                    $account->getId(),
                    $e->getMessage(),
                ));
            }
        }

        $io->success(sprintf('Renewed %d watch(es), skipped %d.', $renewed, $skipped));

        return Command::SUCCESS;
    }
}
