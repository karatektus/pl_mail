<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Enum\MailProvider;
use App\Enum\AuthType;
use App\Repository\MailboxRepository;
use App\Service\Gmail\GmailWatchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renews Gmail push-notification watches that are expiring within 24 hours.
 *
 * Run this daily via cron or Symfony Scheduler:
 *   0 6 * * * php bin/console app:gmail:renew-watches
 *
 * Google watch registrations expire after at most 7 days so a daily renewal
 * gives us a comfortable 6-day buffer.
 */
#[AsCommand(
    name: 'app:gmail:renew-watches',
    description: 'Renew Gmail push-notification watches expiring within 24 hours',
)]
final class GmailWatchRenewalCommand extends Command
{
    public function __construct(
        private readonly MailboxRepository $mailboxRepository,
        private readonly GmailWatchService $watchService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Gmail watch renewal');

        $mailboxes = $this->mailboxRepository->findAll();
        $renewed   = 0;
        $skipped   = 0;

        foreach ($mailboxes as $mailbox) {
            $account = $mailbox->getAccount();

            // Only Gmail OAuth accounts
            if (false === (
                    AuthType::OAuth2->value === $account->getAuthType()
                    && MailProvider::Google->value === $account->getOauthProvider()
                )) {
                $skipped++;
                continue;
            }

            // Only sync-enabled mailboxes
            if (false === $mailbox->isSyncEnabled()) {
                $skipped++;
                continue;
            }

            // Renew if expiry is within 24 hours or not yet registered
            $expiry = $mailbox->getGmailWatchExpiry();
            $needsRenewal = (null === $expiry)
                || ($expiry <= new \DateTimeImmutable('+24 hours'));

            if (false === $needsRenewal) {
                $skipped++;
                continue;
            }

            try {
                $this->watchService->watch($mailbox);
                $io->text(sprintf(
                    '✓ Renewed watch for %s / %s',
                    $account->getEmail(),
                    $mailbox->getName(),
                ));
                $renewed++;
            } catch (\Throwable $e) {
                $io->error(sprintf(
                    'Failed to renew watch for mailbox %d: %s',
                    $mailbox->getId(),
                    $e->getMessage(),
                ));
            }
        }

        $io->success(sprintf('Renewed %d watch(es), skipped %d.', $renewed, $skipped));

        return Command::SUCCESS;
    }
}
