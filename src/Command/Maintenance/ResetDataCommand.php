<?php

namespace App\Command\Maintenance;

use App\Service\Maintenance\DataResetter;
use App\Service\Maintenance\ResetReport;
use App\Service\Maintenance\ResetScope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The reset, on a terminal.
 *
 * The deleting itself moved to App\Service\Maintenance\DataResetter when the
 * admin panel gained the same buttons; what is left here is the part that only
 * makes sense with a TTY — flags, prompts, and saying what happened line by
 * line. This is the documented way to reset an install whose web UI is
 * unreachable, so it keeps its exact behaviour: same flags, same defaults, same
 * output.
 */
#[AsCommand(
    name: 'app:reset',
    description: 'Truncate all synced message and calendar data, optionally including mailbox and calendar structure, contacts and monitoring data',
)]
class ResetDataCommand extends Command
{
    public function __construct(
        private readonly DataResetter $resetter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mailboxes', null, InputOption::VALUE_NONE, 'Also delete mailbox and calendar structure (folders, labels and calendars)')
            ->addOption('contacts', null, InputOption::VALUE_NONE, 'Also delete harvested contacts')
            ->addOption('accounts', null, InputOption::VALUE_NONE, 'Also delete the accounts themselves, aliases included (implies --mailboxes)')
            ->addOption('keep-monitoring', null, InputOption::VALUE_NONE, 'Keep monitoring data (aggregated logs and process heartbeats)')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Back to first-run state: every table, every user and the stored files')
            ->addOption('rotate-secrets', null, InputOption::VALUE_NONE, 'With --full: also discard the generated secrets. Requires restarting the whole stack before the app is used again');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (true === $input->getOption('full')) {
            return $this->fullReset($io, $input->isInteractive(), true === $input->getOption('rotate-secrets'));
        }

        $io->warning('This will permanently delete synced data from the database.');

        // Accounts are the one thing a plain reset never touches — you would
        // have to re-enter every password to get back to a syncing app. Flag
        // only, never a prompt, and it takes the structure with it because a
        // label without its account is a foreign key violation waiting to
        // happen.
        $deleteAccounts = true === $input->getOption('accounts');

        $deleteMailboxes = $deleteAccounts || true === $input->getOption('mailboxes') || (
            $input->isInteractive() && $io->confirm(
                'Also delete mailbox and calendar structure (folders, labels and calendars)? If no, only messages, threads and calendar events will be cleared.',
                false,
            )
        );

        $deleteContacts = true === $input->getOption('contacts') || (
            $input->isInteractive() && $io->confirm(
                'Also delete harvested contacts?',
                false,
            )
        );

        $resetMonitoring = true !== $input->getOption('keep-monitoring') && (
            !$input->isInteractive() || $io->confirm(
                'Also clear monitoring data (aggregated logs and process heartbeats)?',
                true,
            )
        );

        // Without a TTY every confirm() above is skipped and answered "no", so
        // `docker compose exec -T … app:reset` quietly keeps the structure.
        // Saying so beats leaving the user to work out why their labels and
        // accounts are still there.
        $io->listing([
            'mailboxes, labels and calendars: ' . ($deleteMailboxes ? 'deleted' : 'kept (--mailboxes)'),
            'contacts: ' . ($deleteContacts ? 'deleted' : 'kept (--contacts)'),
            'accounts and aliases: ' . ($deleteAccounts ? 'deleted' : 'kept (--accounts)'),
            'monitoring data: ' . ($resetMonitoring ? 'cleared' : 'kept'),
        ]);

        $io->section('Handing back push registrations...');

        $report = $this->resetter->reset(new ResetScope(
            mailboxes: $deleteMailboxes,
            contacts: $deleteContacts,
            accounts: $deleteAccounts,
            monitoring: $resetMonitoring,
        ));

        // Said before the tables, because that is the order it happened in and
        // because it is the step whose silence used to be the problem: a reset
        // that truncated the rows while Google and Microsoft went on pushing at
        // this install left warnings nobody could trace back to here. Zero is
        // printed too — "0 revoked" on an install with push accounts is worth
        // seeing, and a line that only appears on success teaches nothing.
        $io->text(sprintf('✓ %d provider-side registration(s) revoked', $report->pushRevoked));

        $io->section('Truncating tables...');

        $this->reportTables($io, $report);

        foreach ($report->cursorsCleared as $table) {
            $io->text('✓ ' . $table . ' (sync cursors)');
        }

        $io->success(true === $deleteAccounts
            ? 'Done. Add an account to start over.'
            : 'Done. Run app:mail:sync to re-sync.');

        return Command::SUCCESS;
    }

    /**
     * Table by table, in the order they were attempted, so a name missing from
     * the schema is reported where it would have been truncated rather than
     * collected into a footnote.
     */
    private function reportTables(SymfonyStyle $io, ResetReport $report): void
    {
        foreach ($report->tables as $table => $truncated) {
            $io->text(true === $truncated
                ? '✓ ' . $table
                : '– ' . $table . ' (not in schema, skipped)');
        }
    }

    /**
     * Put the install back to the state a fresh `docker compose up` produces:
     * no data, no users, no generated secrets. The next page load offers the
     * create-your-account screen again.
     *
     * Deliberately separate from the flags above rather than another one of
     * them. Those keep you signed in and keep your accounts syncing; this one
     * deletes the user you are running it as, and there is nothing to undo it
     * with.
     */
    private function fullReset(SymfonyStyle $io, bool $interactive, bool $rotateSecrets): int
    {
        $warning = [
            'FULL RESET — this deletes everything, not just synced mail:',
            'every user (you included), every account and its stored password,',
            'and the files on disk.',
            'The install goes back to asking for its first administrator.',
        ];

        if (true === $rotateSecrets) {
            $warning[] = '';
            $warning[] = 'AND the generated secrets. Every service is still running with the old';
            $warning[] = 'APP_ENCRYPTION_KEY in memory and will keep using it until restarted, so';
            $warning[] = 'anything saved before you restart the stack becomes unreadable to half';
            $warning[] = 'of it. Restart immediately afterwards and before using the app.';
        }

        $io->warning($warning);

        if (true === $interactive && false === $io->confirm('Continue?', false)) {
            $io->text('Nothing was changed.');

            return Command::SUCCESS;
        }

        $io->section('Handing back push registrations...');

        $report = $this->resetter->fullReset($rotateSecrets);

        $io->text(sprintf('✓ %d provider-side registration(s) revoked', $report->pushRevoked));

        $io->section('Truncating every table...');

        $io->text(sprintf('✓ %d tables', count($report->tables)));

        $io->section('Removing stored files...');

        foreach ($report->emptiedDirectories as $directory) {
            $io->text('✓ ' . $directory);
        }

        if (false === $rotateSecrets) {
            $io->success([
                'Done. Open plMail and create the first administrator.',
                'The generated secrets were left alone — add --rotate-secrets to discard them,',
                'and restart the whole stack immediately afterwards.',
            ]);

            return Command::SUCCESS;
        }

        $io->section('Clearing generated secrets...');

        $io->listing([...$report->removedSecrets, 'JWT keypair']);
        $io->text('POSTGRES_PASSWORD kept — Postgres was initialised with it. To change that, wipe the database volume.');

        $io->warning([
            'RESTART THE STACK NOW, before using plMail:',
            '  docker compose restart',
            'Every service is still running with the old encryption key. Anything saved',
            'before the restart will be unreadable to the services that have moved on.',
        ]);

        return Command::SUCCESS;
    }
}
