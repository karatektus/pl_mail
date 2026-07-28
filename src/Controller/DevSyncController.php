<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Account;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Repository\AccountRepository;
use App\Repository\MailboxRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * TEMPORARY: the topbar sync button. Same effect as `app:mail:sync`, scoped to
 * the current user's active accounts, plus a queue probe so the button can stop
 * spinning once this user's sync jobs are done.
 */
#[Route('/dev/sync', name: 'app_dev_sync_')]
// ROLE_USER, not IS_AUTHENTICATED_FULLY: "keep me logged in" survives the
// session, so a remembered user browses the app normally but fails a FULLY
// check — the button's fetch followed the redirect to /login and choked on the
// HTML. Same level as the rest of the app (access_control ^/).
#[IsGranted('ROLE_USER')]
final class DevSyncController extends AbstractController
{
    public function __construct(
        private readonly AccountRepository   $accountRepository,
        private readonly MailboxRepository   $mailboxRepository,
        private readonly MessageBusInterface $bus,
        private readonly Connection          $connection,
    ) {}

    #[Route('', name: 'run', methods: ['POST'])]
    public function run(): JsonResponse
    {
        $accounts = $this->activeAccounts();

        foreach ($accounts as $account) {
            $this->bus->dispatch(new SyncAccountMessage($account->getId()));
        }

        return $this->json(['dispatched' => count($accounts)]);
    }

    /**
     * Pending + in-flight sync jobs belonging to this user.
     *
     * The doctrine transport has no user column, so rows are matched on the
     * PHP-serialized envelope body: every sync-chain message is keyed either by
     * accountId (SyncAccount, HarvestContacts, the Gmail/Graph batches) or by
     * mailboxId (SyncImapMailbox). Mailbox ids are re-read on every poll so
     * folders discovered mid-sync are counted too. The failed queue is excluded
     * — those never drain on their own and would spin forever.
     */
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $accounts   = $this->activeAccounts();
        $accountIds = array_map(static fn ($account): int => (int) $account->getId(), $accounts);

        if ([] === $accountIds) {
            return $this->json(['pending' => 0]);
        }

        $mailboxIds = array_map(
            static fn ($mailbox): int => (int) $mailbox->getId(),
            $this->mailboxRepository->findBy(['account' => $accounts]),
        );

        $needles = [];

        foreach ($accountIds as $id) {
            $needles[] = 'accountId";i:' . $id . ';';
        }

        foreach ($mailboxIds as $id) {
            $needles[] = 'mailboxId";i:' . $id . ';';
        }

        $clauses    = [];
        $parameters = [];

        foreach ($needles as $i => $needle) {
            // POSITION, not LIKE: the body is quote-escaped (…\";i:4;) and
            // Postgres LIKE would read those backslashes as escape characters.
            // Both the escaped and plain spellings are probed so the check does
            // not hinge on how the driver wrote the row.
            $clauses[]             = 'POSITION(:pe' . $i . ' IN body) > 0 OR POSITION(:pp' . $i . ' IN body) > 0';
            $parameters['pe' . $i] = str_replace('"', '\\"', $needle);
            $parameters['pp' . $i] = $needle;
        }

        $pending = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM messenger_messages
             WHERE queue_name <> 'failed' AND (" . implode(' OR ', $clauses) . ')',
            $parameters,
        );

        return $this->json(['pending' => $pending]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @return list<Account>
     */
    private function activeAccounts(): array
    {
        return array_values(array_filter(
            $this->accountRepository->findForUserOrdered($this->getUser()),
            static fn ($account): bool => true === $account->isActive(),
        ));
    }
}
