<?php

declare(strict_types=1);

namespace App\Command\Diagnostics;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\GmailSkipReason;
use App\Entity\Mail\Account;
use App\Entity\Mail\GmailSkippedMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\GmailSkippedMessageRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Gmail\GmailAddressFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What a Gmail import looked at and did not store, and why.
 *
 * Gmail's own count of a mailbox is larger than what plMail holds for it, and
 * the difference used to be unaccountable: "24,199 in Gmail, 21,964 here" with
 * nothing to say whether the 2,235 were drafts nobody misses or mail somebody
 * does (#90). The batch handler now writes each one down as it declines it
 * (App\Entity\Mail\GmailSkippedMessage), and this reads the record back.
 *
 * It asks Gmail nothing and costs no quota. Everything printed was noted when
 * the message was first looked at.
 *
 * THE KINDS
 *
 * A reading of the labels and headers that were kept, in the order a person
 * would ask: could it not be fetched at all; is it a chat, a draft; was it
 * sent, from an address that is not this account's; was it delivered to some
 * other address; or does it name no recipient plMail could match. They are a
 * description for a human, not the rule that declined the message — that rule
 * is SyncGmailMessageBatchHandler::resolveOwningAccount(), and this does not
 * re-run it.
 *
 * `--forget` deletes the record for an account. The next listing then finds
 * those messages missing again and asks for them, which is the way to have
 * them reconsidered after connecting another account or after the rule that
 * declined them has changed.
 */
#[AsCommand(
    name: 'app:gmail:skipped',
    description: 'Show the Gmail messages an import looked at and did not store, grouped by why',
)]
final class GmailSkippedCommand extends Command
{
    private const int TOP = 10;

    public function __construct(
        private readonly AccountRepository             $accounts,
        private readonly MessageRepository             $messages,
        private readonly GmailSkippedMessageRepository $skipped,
        private readonly GmailAddressFilter            $addresses,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('account', InputArgument::OPTIONAL, 'A Gmail account, by id or address; leave it out for one line per account')
            ->addOption('list', null, InputOption::VALUE_REQUIRED, 'Also print this many of the messages themselves: id, labels and address headers', '0')
            ->addOption('forget', null, InputOption::VALUE_NONE, 'Delete the record for the account, so the next listing asks for these messages again');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<Account> $gmail */
        $gmail = $this->accounts->findBy(
            ['authType' => AuthType::OAuth2->value, 'oauthProvider' => 'google'],
            ['id' => 'ASC'],
        );

        if ([] === $gmail) {
            $io->warning('There is no Gmail account on this installation.');

            return Command::SUCCESS;
        }

        $named = $input->getArgument('account');

        if (false === is_string($named) || '' === trim($named)) {
            if (true === $input->getOption('forget')) {
                $io->error('Name the account to forget the record of.');

                return Command::FAILURE;
            }

            $this->overview($io, $gmail);

            return Command::SUCCESS;
        }

        $account = $this->named(trim($named), $gmail);

        if (null === $account) {
            $io->error(sprintf('"%s" is not a Gmail account here.', trim($named)));
            $this->overview($io, $gmail);

            return Command::FAILURE;
        }

        if (true === $input->getOption('forget')) {
            $io->success(sprintf(
                'Forgot %d skipped message(s) of %s. The next listing will ask for them again.',
                $this->skipped->forget($account),
                $account->displayAddress,
            ));

            return Command::SUCCESS;
        }

        $this->breakdown($io, $account, max(0, (int) $input->getOption('list')));

        return Command::SUCCESS;
    }

    /**
     * @param list<Account> $gmail
     */
    private function overview(SymfonyStyle $io, array $gmail): void
    {
        $rows = [];

        foreach ($gmail as $account) {
            $rows[] = [
                $account->id,
                $account->displayAddress,
                $this->messages->countForAccount($account),
                $this->skipped->countForAccount($account),
                $account->importTotal ?? '—',
                true === $account->needsBackfill() ? 'importing' : 'complete',
            ];
        }

        $io->table(['Id', 'Account', 'Stored', 'Skipped', 'Gmail\'s total', 'Import'], $rows);
        $io->text('Name an account for the breakdown: app:gmail:skipped <id or address>');
    }

    private function breakdown(SymfonyStyle $io, Account $account, int $list): void
    {
        $rows = $this->skipped->findForAccount($account);

        $io->title(sprintf('Skipped Gmail messages: %s', $account->displayAddress));
        $io->definitionList(
            ['Stored' => (string) $this->messages->countForAccount($account)],
            ['Skipped' => (string) count($rows)],
            ['Gmail\'s total' => (string) ($account->importTotal ?? '—')],
        );

        if ([] === $rows) {
            $io->text('Nothing has been skipped. A message is recorded when an import or a sync next looks at it, so an import that ran before this record existed shows here after its next listing.');

            return;
        }

        $kinds     = [];
        $labels    = [];
        $delivered = [];
        $senders   = [];

        foreach ($rows as $row) {
            $kind         = $this->kind($row, $account);
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;

            foreach ($row->labelIds as $label) {
                $labels[$label] = ($labels[$label] ?? 0) + 1;
            }

            $to = $this->addresses->normalise($row->headers['delivered-to'] ?? '');

            if ('' !== $to) {
                $delivered[$to] = ($delivered[$to] ?? 0) + 1;
            }

            if (true === in_array('SENT', $row->labelIds, true)) {
                $from = $this->addresses->normalise($row->headers['from'] ?? '');

                if ('' !== $from) {
                    $senders[$from] = ($senders[$from] ?? 0) + 1;
                }
            }
        }

        $io->section('By kind');
        $io->table(['Kind', 'Messages'], $this->counted($kinds));

        $io->section('By Gmail label (a message can carry several)');
        $io->table(['Label', 'Messages'], $this->counted($labels));

        if ([] !== $senders) {
            $io->section('Sent mail: the From addresses that were not this account');
            $io->table(['From', 'Messages'], $this->counted($senders, self::TOP));
        }

        if ([] !== $delivered) {
            $io->section('Delivered-To addresses');
            $io->table(['Delivered-To', 'Messages'], $this->counted($delivered, self::TOP));
        }

        if ($list > 0) {
            $io->section(sprintf('The first %d', min($list, count($rows))));

            foreach (array_slice($rows, 0, $list) as $row) {
                $io->text(sprintf(
                    '%s  [%s]  %s',
                    $row->gmailId,
                    implode(' ', $row->labelIds),
                    implode('  ', array_map(
                        static fn (string $name, string $value): string => sprintf('%s: %s', $name, $value),
                        array_keys($row->headers),
                        array_values($row->headers),
                    )),
                ));
            }
        }
    }

    /**
     * One description per message, the first that fits. See the class docblock:
     * this describes, it does not decide.
     */
    private function kind(GmailSkippedMessage $row, Account $account): string
    {
        if (GmailSkipReason::Unfetchable === $row->reason) {
            return 'Gmail lists it and could not return it';
        }

        if (GmailSkipReason::HeldElsewhere === $row->reason) {
            return 'Another Gmail account here holds it already';
        }

        $labels = $row->labelIds;

        if (true === in_array('CHAT', $labels, true)) {
            return 'A chat';
        }

        if (true === in_array('DRAFT', $labels, true)) {
            return 'A draft';
        }

        if (true === in_array('SENT', $labels, true)) {
            return 'Sent from an address that is not this account\'s';
        }

        $mine      = $this->addresses->normalise((string) $account->email);
        $delivered = $this->addresses->normalise($row->headers['delivered-to'] ?? '');

        if ('' !== $delivered && $delivered !== $mine) {
            return 'Delivered to another address';
        }

        if ('' === ($row->headers['to'] ?? '') && '' === ($row->headers['cc'] ?? '') && '' === $delivered) {
            return 'No recipient headers at all';
        }

        return 'Addressed to another address, with no Delivered-To';
    }

    /**
     * @param array<string,int> $counts
     *
     * @return list<array{0: string, 1: int}> most frequent first
     */
    private function counted(array $counts, ?int $top = null): array
    {
        arsort($counts);

        $rows = [];

        foreach (array_slice($counts, 0, $top, true) as $name => $count) {
            $rows[] = [(string) $name, $count];
        }

        return $rows;
    }

    /**
     * @param list<Account> $gmail
     */
    private function named(string $named, array $gmail): ?Account
    {
        foreach ($gmail as $account) {
            if ((string) $account->id === $named || 0 === strcasecmp((string) $account->email, $named)) {
                return $account;
            }
        }

        return null;
    }
}
