<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AccountRepository;
use App\Repository\ContactRepository;
use App\Repository\MailboxRepository;
use App\Repository\MessageRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:contacts:harvest',
    description: 'Harvest contact addresses from all synced messages',
)]
final class HarvestContactsCommand extends Command
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly MailboxRepository $mailboxRepository,
        private readonly MessageRepository $messageRepository,
        private readonly ContactRepository $contactRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'account-id',
            InputArgument::OPTIONAL,
            'Limit harvest to a single account ID',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $accountId = $input->getArgument('account-id');

        if ($accountId !== null) {
            $accounts = [$this->accountRepository->find((int) $accountId)];
        } else {
            $accounts = $this->accountRepository->findBy(['isActive' => true]);
        }

        foreach ($accounts as $account) {
            if ($account === null) {
                $io->error('Account not found.');
                return Command::FAILURE;
            }

            $user = $account->getUsr();
            $io->section(sprintf('Harvesting contacts for %s', $account->getEmail()));

            $mailboxes = $this->mailboxRepository->findBy(['account' => $account]);
            $total     = 0;

            foreach ($mailboxes as $mailbox) {
                $messages = $this->messageRepository->findByMailboxOrderedByDate($mailbox);
                $batch    = [];

                foreach ($messages as $msg) {
                    if ($msg->getFromAddress() !== null && $msg->getFromAddress() !== '') {
                        $batch[] = [
                            'email' => $msg->getFromAddress(),
                            'name'  => $msg->getFromName(),
                        ];
                    }

                    foreach ([
                                 $msg->getToAddresses(),
                                 $msg->getCcAddresses(),
                                 $msg->getBccAddresses(),
                             ] as $group) {
                        if ($group === null) {
                            continue;
                        }

                        foreach ($group as $addr) {
                            if (isset($addr['address']) && $addr['address'] !== '') {
                                $batch[] = [
                                    'email' => $addr['address'],
                                    'name'  => $addr['name'] ?? null,
                                ];
                            }
                        }
                    }

                    if (count($batch) >= 200) {
                        $this->contactRepository->upsertBatch($user, $batch);
                        $total += count($batch);
                        $batch  = [];
                    }
                }

                if (count($batch) > 0) {
                    $this->contactRepository->upsertBatch($user, $batch);
                    $total += count($batch);
                }

                $io->text(sprintf('  ✓ %s — %d messages processed', $mailbox->getName(), count($messages)));
            }

            $io->success(sprintf('Harvested %d address occurrences for %s', $total, $account->getEmail()));
        }

        return Command::SUCCESS;
    }
}
