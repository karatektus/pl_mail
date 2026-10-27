<?php

declare(strict_types=1);

namespace App\Service\JmapClient;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Exception\JmapApiException;
use App\Entity\Mail\Account;
use App\Infrastructure\JmapClient\JmapCapability;
use App\Infrastructure\JmapClient\JmapClient;
use App\Service\Label\LabelResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * A JMAP account's history, a page at a time, for MailImporter.
 *
 * The account's side of the chain MailImporter runs for every provider — see
 * that class for why it is a chain of pages and not a loop. What a page is
 * here: fifty Email ids from Email/query, newest first, handed to
 * JmapEmailSyncer::ingest(), which fetches the ones that are new and re-reads
 * the ones that are not.
 *
 * ## Inbox first
 *
 * The first phase lists only the Inbox, so the mail somebody opens plMail to
 * read is there before the archive is. The second lists everything; the Inbox
 * comes round again in it and costs one Email/get of its mailboxes and
 * keywords, which is also what makes the second phase a full re-read when a
 * resync started it.
 *
 * ## Where the listing has got to
 *
 * Account::$jmapImport, by anchor — see there. Changes that happen meanwhile
 * are not this class's: beginImport() records the Email state first, and the
 * live sync reads every change since then.
 */
final readonly class JmapImporter
{
    public function __construct(
        private JmapClient             $jmap,
        private JmapMailboxSyncer      $mailboxes,
        private JmapEmailSyncer        $emails,
        private LabelResolver          $labelResolver,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    /**
     * Plan an import: record where changes start, and how much there is.
     *
     * @throws JmapApiException
     */
    public function beginImport(Account $account): void
    {
        $accountId = $this->mailboxes->mailAccountId($account);

        $total = $this->jmap->call($account, [JmapCapability::MAIL], [
            ['Email/query', ['accountId' => $accountId, 'limit' => 0, 'calculateTotal' => true], 'q'],
        ])->get('q', 'Email/query');

        // Baseline and plan in one flush — see JmapEmailSyncer::baseline().
        $this->emails->baseline($account);

        $account->importTotal = is_int($total['total'] ?? null) ? $total['total'] : null;
        $account->jmapImport  = ['phase' => 'inbox', 'anchor' => null, 'position' => 0];

        $this->em->flush();

        $this->logger->info('JmapImporter: import planned', [
            'accountId' => $account->id,
            'total'     => $account->importTotal,
        ]);
    }

    /**
     * Read the next page of the listing.
     *
     * @throws JmapApiException
     */
    public function importPage(Account $account): JmapImportStep
    {
        $plan = $account->jmapImport;

        if (null === $plan) {
            return JmapImportStep::Finished;
        }

        $accountId = $this->mailboxes->mailAccountId($account);
        $inbox     = 'inbox' === $plan['phase'] ? $this->inboxId($account) : null;

        // An account whose server has no inbox role — a shared store, a
        // server that names none — goes straight to everything.
        if ('inbox' === $plan['phase'] && null === $inbox) {
            $plan = ['phase' => 'all', 'anchor' => null, 'position' => 0];
        }

        $ids = $this->list($account, $accountId, $plan, $inbox);

        $this->emails->ingest($account, $ids);

        // The account as it is now: ingest clears the entity manager when a
        // write is refused, and the plan is written onto this row.
        $account = $this->em->find(Account::class, $account->id) ?? $account;

        if (count($ids) < JmapEmailSyncer::BATCH_SIZE) {
            if ('inbox' === $plan['phase']) {
                $account->jmapImport = ['phase' => 'all', 'anchor' => null, 'position' => 0];
                $this->em->flush();

                return JmapImportStep::More;
            }

            $account->jmapImport = null;
            $this->em->flush();

            $this->logger->info('JmapImporter: import finished', ['accountId' => $account->id]);

            return JmapImportStep::Finished;
        }

        $account->jmapImport = [
            'phase'    => $plan['phase'],
            'anchor'   => $ids[array_key_last($ids)],
            'position' => $plan['position'] + count($ids),
        ];

        $this->em->flush();

        return JmapImportStep::More;
    }

    /**
     * One page of ids, after the anchor — or at the position, when the Email
     * the anchor names is gone.
     *
     * @param array{phase: string, anchor: string|null, position: int} $plan
     *
     * @return list<string>
     *
     * @throws JmapApiException
     */
    private function list(Account $account, string $accountId, array $plan, ?string $inbox): array
    {
        $arguments = [
            'accountId'       => $accountId,
            'sort'            => [['property' => 'receivedAt', 'isAscending' => false]],
            'limit'           => JmapEmailSyncer::BATCH_SIZE,
            'collapseThreads' => false,
        ];

        if (null !== $inbox) {
            $arguments['filter'] = ['inMailbox' => $inbox];
        }

        $byPosition = $arguments + ['position' => $plan['position']];
        $query      = null === $plan['anchor'] ? $byPosition : $arguments + ['anchor' => $plan['anchor'], 'anchorOffset' => 1];

        try {
            $result = $this->query($account, $query);
        } catch (JmapApiException $refused) {
            if ('anchorNotFound' !== $refused->getType()) {
                throw $refused;
            }

            $result = $this->query($account, $byPosition);
        }

        return array_values(array_filter(is_array($result['ids'] ?? null) ? $result['ids'] : [], 'is_string'));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     *
     * @throws JmapApiException
     */
    private function query(Account $account, array $arguments): array
    {
        return $this->jmap->call($account, [JmapCapability::MAIL], [
            ['Email/query', $arguments, 'q'],
        ])->get('q', 'Email/query');
    }

    /** The remote id of the account's Inbox, from the mailbox sync's binding. */
    private function inboxId(Account $account): ?string
    {
        return $this->labelResolver->systemLabel(LabelRole::Inbox, $account)->bindingFor($account)?->jmapMailboxId;
    }
}
