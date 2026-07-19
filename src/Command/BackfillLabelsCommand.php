<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Enum\LabelRole;
use App\Entity\Account;
use App\Entity\Label;
use App\Entity\Mailbox;
use App\Repository\AccountRepository;
use App\Repository\LabelRepository;
use App\Repository\MailboxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Phase 1 backfill for the label-based architecture.
 *
 * Per account:
 *   1. Creates a system Label for every special-use mailbox
 *      (Inbox/Sent/Drafts/Trash/Spam + hidden Archive).
 *   2. Creates a custom Label chain for every non-special folder,
 *      splitting the folder's fullPath on the account delimiter so the
 *      IMAP hierarchy maps onto the Label parent tree. A leading "INBOX"
 *      path segment (Courier/Dovecot-style "INBOX.Work.Invoices") is
 *      stripped so it does not become a bogus root label.
 *   3. Links each Mailbox to its Label (mailbox.label_id).
 *   4. Bulk-inserts message_label rows from message.mailbox_id.
 *   5. Bulk-inserts thread_label rows from the same source.
 *
 * The command is idempotent: labels are find-or-created and the bulk
 * inserts use ON CONFLICT DO NOTHING against the composite primary keys.
 *
 * Gmail custom labels (Label_xxx ids stored on messages) are NOT handled
 * here — their display names require the Gmail labels.list API, which is
 * Phase 2's label sync. This backfill covers the mailbox-derived system
 * labels, which is all Gmail accounts currently route into anyway.
 */
#[AsCommand(
    name: 'app:label:backfill',
    description: 'Create labels from existing mailboxes and backfill message/thread label assignments',
)]
final class BackfillLabelsCommand extends Command
{
    public function __construct(
        private readonly AccountRepository      $accountRepository,
        private readonly MailboxRepository      $mailboxRepository,
        private readonly LabelRepository        $labelRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'account',
            null,
            InputOption::VALUE_REQUIRED,
            'Restrict the backfill to a single account id',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $accounts = $this->resolveAccounts($input, $io);

        if (count($accounts) === 0) {
            $io->warning('No accounts to process.');

            return Command::SUCCESS;
        }

        foreach ($accounts as $account) {
            $this->backfillAccount($account, $io);
        }

        $io->success('Label backfill complete.');

        return Command::SUCCESS;
    }

    /**
     * @return Account[]
     */
    private function resolveAccounts(InputInterface $input, SymfonyStyle $io): array
    {
        $accountId = $input->getOption('account');

        if (null === $accountId) {
            return $this->accountRepository->findAll();
        }

        $account = $this->accountRepository->find((int) $accountId);

        if (null === $account) {
            $io->error(sprintf('Account %s not found.', $accountId));

            return [];
        }

        return [$account];
    }

    private function backfillAccount(Account $account, SymfonyStyle $io): void
    {
        $io->section(sprintf('Account #%d (%s)', $account->getId(), $account->getEmail()));

        $mailboxes = $this->mailboxRepository->findBy(['account' => $account]);

        // Parents must exist before children — shallower paths first.
        usort($mailboxes, function (Mailbox $a, Mailbox $b): int {
            return substr_count((string) $a->getFullPath(), (string) ($a->getDelimiter() ?? '/'))
                <=> substr_count((string) $b->getFullPath(), (string) ($b->getDelimiter() ?? '/'));
        });

        $linked = 0;

        foreach ($mailboxes as $mailbox) {
            $label = $this->labelForMailbox($mailbox, $account);
            $mailbox->setLabel($label);
            $linked++;
        }

        $this->em->flush();

        $io->text(sprintf('✓ %d mailboxes linked to labels', $linked));

        $connection = $this->em->getConnection();

        $messageRows = $connection->executeStatement(
            <<<'SQL'
                INSERT INTO message_label (message_id, label_id)
                SELECT m.id, mb.label_id
                FROM message m
                JOIN mailbox mb ON mb.id = m.mailbox_id
                WHERE mb.account_id = :accountId
                  AND mb.label_id IS NOT NULL
                ON CONFLICT DO NOTHING
            SQL,
            ['accountId' => $account->getId()],
        );

        $io->text(sprintf('✓ %d message_label rows inserted', $messageRows));

        $threadRows = $connection->executeStatement(
            <<<'SQL'
                INSERT INTO thread_label (message_thread_id, label_id)
                SELECT DISTINCT m.thread_id, mb.label_id
                FROM message m
                JOIN mailbox mb ON mb.id = m.mailbox_id
                WHERE mb.account_id = :accountId
                  AND mb.label_id IS NOT NULL
                  AND m.thread_id IS NOT NULL
                ON CONFLICT DO NOTHING
            SQL,
            ['accountId' => $account->getId()],
        );

        $io->text(sprintf('✓ %d thread_label rows inserted', $threadRows));
    }

    private function labelForMailbox(Mailbox $mailbox, Account $account): Label
    {
        $specialUse = $mailbox->getSpecialUse();

        if (null !== $specialUse) {
            return $this->findOrCreateSystemLabel(LabelRole::fromSpecialUse($specialUse), $account);
        }

        return $this->findOrCreateCustomChain($mailbox, $account);
    }

    private function findOrCreateSystemLabel(LabelRole $role, Account $account): Label
    {
        $label = $this->labelRepository->findOneByRoleForAccount($role, $account);

        if (null !== $label) {
            return $label;
        }

        $label = new Label()
            ->setAccount($account)
            ->setName($role->displayName())
            ->setRole($role)
            ->setSortOrder($role->sortOrder())
            ->setIsVisible($role->isVisible());

        $this->em->persist($label);
        // Flush immediately so subsequent findOneBy calls within this run
        // see the label without needing an in-memory cache.
        $this->em->flush();

        return $label;
    }

    private function findOrCreateCustomChain(Mailbox $mailbox, Account $account): Label
    {
        $segments = $this->pathSegments($mailbox);

        $parent = null;
        $label = null;

        foreach ($segments as $segment) {
            $label = $this->labelRepository->findOneChildByName($account, $parent, $segment);

            if (null === $label) {
                $label = new Label()
                    ->setAccount($account)
                    ->setParent($parent)
                    ->setName($segment);

                $this->em->persist($label);
                $this->em->flush();
            }

            $parent = $label;
        }

        return $label;
    }

    /**
     * Splits a folder fullPath into label path segments using the
     * account's delimiter, stripping a leading INBOX namespace segment.
     *
     * @return list<string>
     */
    private function pathSegments(Mailbox $mailbox): array
    {
        $delimiter = $mailbox->getDelimiter();

        if (null === $delimiter || '' === $delimiter) {
            $delimiter = '/';
        }

        $segments = explode($delimiter, (string) $mailbox->getFullPath());
        $segments = array_values(array_filter($segments, function (string $segment): bool {
            return '' !== trim($segment);
        }));

        if (count($segments) > 1 && 'INBOX' === strtoupper($segments[0])) {
            array_shift($segments);
        }

        if (count($segments) === 0) {
            $segments = [(string) $mailbox->getName()];
        }

        return $segments;
    }
}
