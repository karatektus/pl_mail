<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Repository\Mail\GmailSkippedMessageRepository;
use App\Domain\DTO\Mail\GmailQuotaStatus;
use App\Domain\DTO\Mail\ImportLine;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Gmail\GmailQuotaPacer;
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
        private GmailQuotaPacer     $pacer,
        private GmailSkippedMessageRepository $skipped,
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
                    // Dealt with, not only stored. The total is Google's count
                    // of the mailbox, which includes what plMail looks at and
                    // declines — a draft, a chat — and counting only what was
                    // stored left the line short of its total for good: 21,964
                    // of 24,199 with nothing still to come (#90).
                    //
                    // And dealt with wherever it went. Mail Gmail collected
                    // from a mailbox that is also connected here is stored on
                    // that account, not this one; counting only this account's
                    // rows left a line at 65,010 of 76,018 with the queue
                    // empty, short by exactly what the other account held.
                    $done = $this->messages->countForAccount($account)
                        + $this->messages->countCarriedBy($account)
                        + $this->skipped->countForAccount($account);

                    // Never past the total. It is Gmail's count when it was
                    // last asked, and mail that arrived since is stored too.
                    if (null !== $account->importTotal) {
                        $done = min($done, $account->importTotal);
                    }

                    $lines[] = new ImportLine(
                        $account,
                        $done,
                        $account->importTotal,
                        null,
                        $this->isWaiting($account),
                        quota: $this->quota($account, $done),
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

    /**
     * What the quota allows this import, and how long the rest takes at that.
     *
     * The time is the messages still to come over the rate the pacer holds an
     * import to. It runs long towards the end: the total is Google's count of
     * the mailbox, which includes what is never stored — a draft, a chat —
     * so the last of "what is left" is mail that is not coming.
     */
    private function quota(Account $account, int $done): GmailQuotaStatus
    {
        $total = $account->importTotal;
        $left  = null === $total ? null : max(0, $total - $done);

        $paused = $this->pacer->waitSeconds($account);
        $used   = $this->pacer->usedThisMinute($account);

        return new GmailQuotaStatus(
            $this->pacer->limit(),
            $this->pacer->isLimitLearnt(),
            // While Google holds the account nothing is being spent, and the
            // running count falls to nothing; what was spent when it said no
            // is the figure that explains the pause.
            $paused > 0 ? $this->pacer->usedWhenRefused($account) : $used,
            $paused,
            // What was spent, over what a message costs. A listing page is in
            // the count as well and is a quarter of one message; a refused
            // message is in it too, and was not fetched. Roughly, then.
            $paused > 0 ? 0 : intdiv($used, GmailQuotaPacer::UNITS_PER_MESSAGE),
            null === $left || 0 === $left ? null : (int) ceil($left / $this->pacer->messagesPerMinute($account)),
        );
    }

    private function isWaiting(Account $account): bool
    {
        $beatAt = $account->importBeatAt;

        return null === $beatAt
            || $beatAt < new DateTimeImmutable(sprintf('-%d seconds', self::WAITING_AFTER_SECONDS));
    }
}
