<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Repository\Mail\AccountRepository;
use App\Service\HarvestContactsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuild the contact list from every message already stored.
 *
 * Contacts are learned as mail arrives — see HarvestContactsStep — so this is
 * for the two cases that produces nothing for: an account whose history was
 * synced before the harvesting existed, and a rebuild after the rules changed
 * about what counts as an address. `app:backfill addresses` rewrote the stored
 * spellings for exactly the second reason, and the contacts derived from them
 * were left as they were.
 *
 * This is the only remaining caller of the account-wide sweep, and it is meant
 * to be: it reads every message an account owns, which was happening after
 * every sync and cost hours of database time a day for work that a batch of
 * new mail already covers.
 */
final readonly class ContactsBackfillTask implements BackfillTaskInterface
{
    public function __construct(
        private AccountRepository $accounts,
        private HarvestContactsService $harvest,
    ) {
    }

    public function getName(): string
    {
        return 'contacts';
    }

    public function getDescription(): string
    {
        return 'Rebuild contacts from every stored message (one sweep per account)';
    }

    public function run(SymfonyStyle $io): int
    {
        $accounts = $this->accounts->findAll();

        if ([] === $accounts) {
            $io->warning('No accounts to harvest.');

            return Command::SUCCESS;
        }

        $total = 0;

        foreach ($accounts as $account) {
            $addresses = $this->harvest->harvestForAccount($account);
            $total += $addresses;

            $io->writeln(sprintf(
                '  %s — %d addresses',
                $account->email ?? ('account '.$account->id),
                $addresses,
            ));
        }

        $io->success(sprintf('%d addresses harvested across %d accounts.', $total, count($accounts)));

        return Command::SUCCESS;
    }
}
