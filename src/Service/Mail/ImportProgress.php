<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\ImportLine;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Graph\GraphFolderResolver;
use DateTimeImmutable;

/**
 * Which of a person's accounts are still bringing in their history, and how
 * far each has got.
 *
 * What the topbar's background-work indicator shows while an import runs. An
 * import used to be invisible: mail appeared in bursts with nothing to say
 * whether the gaps between them were work or a fault (#42).
 *
 * Read off the same state the import reads — the folders' markers for IMAP,
 * the backfill state for Gmail, the list of folders still to be read for
 * Microsoft, and for those two a count of what is stored — so it cannot
 * say one thing while the import does another, and costs nothing to keep.
 *
 * CHEAP WHEN NOTHING IS IMPORTING, which is nearly always, because the topbar
 * asks on every page: a person's accounts are one query, and whether any of
 * their folders has history left is one more for all of them together. The
 * per-account queries only run for an account that is actually importing.
 */
final readonly class ImportProgress
{
    /**
     * Seconds without a sign of life after which a line says "waiting". Twice
     * the interval at which a stopped import is started again, so one that is
     * merely between two pages never shows it.
     */
    private const int WAITING_AFTER_SECONDS = MailImporter::QUIET_SECONDS * 2;

    public function __construct(
        private AccountRepository   $accounts,
        private MailboxRepository   $mailboxes,
        private MessageRepository   $messages,
        private GraphFolderResolver $folders,
    ) {}

    /** @return list<ImportLine> one per importing account, in the accounts' own order */
    public function forUser(User $user): array
    {
        /** @var list<Account> $accounts */
        $accounts = $this->accounts->findBy(['usr' => $user, 'isActive' => true], ['sortOrder' => 'ASC', 'id' => 'ASC']);

        if ([] === $accounts) {
            return [];
        }

        $folders = $this->mailboxes->importTotalsByAccount($accounts);
        $lines   = [];

        foreach ($accounts as $account) {
            if (true === $account->isMicrosoft()) {
                $plan = $account->graphImport;

                if ([] !== $plan) {
                    $lines[] = new ImportLine(
                        $account,
                        $this->messages->countForAccount($account),
                        $account->importTotal,
                        null,
                        $this->isWaiting($account),
                        $this->folders->resolveFolder($plan[0]['folder'], $account),
                    );
                }

                continue;
            }

            if (true === $account->isGmail()) {
                if (true === $account->needsBackfill()) {
                    $lines[] = new ImportLine(
                        $account,
                        $this->messages->countForAccount($account),
                        $account->importTotal,
                        null,
                        $this->isWaiting($account),
                    );
                }

                continue;
            }

            $totals = $folders[(int) $account->id] ?? null;

            if (null === $totals || 0 === $totals['importing']) {
                continue;
            }

            $lines[] = new ImportLine(
                $account,
                max(0, $totals['total'] - $totals['remaining']),
                0 < $totals['total'] ? $totals['total'] : null,
                $this->mailboxes->findImporting($account)[0] ?? null,
                $this->isWaiting($account),
            );
        }

        return $lines;
    }

    private function isWaiting(Account $account): bool
    {
        $beatAt = $account->importBeatAt;

        return null === $beatAt
            || $beatAt < new DateTimeImmutable(sprintf('-%d seconds', self::WAITING_AFTER_SECONDS));
    }
}
