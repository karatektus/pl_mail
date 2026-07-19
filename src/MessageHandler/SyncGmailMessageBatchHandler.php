<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Account;
use App\Entity\Message;
use App\Message\SyncGmailMessageBatchMessage;
use App\Repository\AccountRepository;
use App\Repository\MessageRepository;
use App\Service\Gmail\GmailAddressFilter;
use App\Service\Gmail\GmailMessageBuilder;
use App\Service\HarvestContactsService;
use App\Service\Imap\MessageThreader;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\SyncNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncGmailMessageBatchHandler
{
    public function __construct(
        private MessageRepository      $messageRepository,
        private AccountRepository      $accountRepository,
        private GmailApiClient         $apiClient,
        private GmailMessageBuilder    $messageBuilder,
        private GmailAddressFilter     $addressFilter,
        private MessageThreader        $messageThreader,
        private HarvestContactsService $harvestService,
        private SyncNotifier           $syncNotifier,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    public function __invoke(SyncGmailMessageBatchMessage $message): void
    {
        $account = $this->accountRepository->find($message->accountId);

        if (null === $account) {
            $this->logger->warning('SyncGmailMessageBatch: account not found', [
                'accountId' => $message->accountId,
            ]);

            return;
        }

        // Build a normalised-address → Account map for all active sibling accounts.
        // Used to attribute Gmailify sent messages to the correct account.
        $siblingAccounts = $this->buildSiblingAccountMap($account);

        // Dedup inside the batch too — batches can overlap across runs/retries.
        // USER-scoped, not account-scoped: Gmailify attribution stores messages
        // under sibling accounts, so a carrier-scoped lookup would miss them
        // and re-insert on every retry.
        $syncedGmailIds = array_flip(
            $this->messageRepository->findSyncedGmailIdsForUser($account->getUsr())
        );

        $toFetch = [];

        foreach ($message->gmailIds as $gmailId) {
            if (true === isset($syncedGmailIds[$gmailId])) {
                continue;
            }

            $toFetch[] = $gmailId;
        }

        if (count($toFetch) === 0) {
            return;
        }

        $payloads = $this->apiClient->getMessages($account, $toFetch);

        /** @var list<array{message: Message, account: Account}> $built */
        $built = [];

        foreach ($payloads as $payload) {
            $labelIds = array_values(array_map('strval', $payload['labelIds'] ?? []));
            $headers  = $this->indexHeaders($payload['payload']['headers'] ?? []);

            // ── Address ownership filter ──────────────────────────────────────
            $targetAccount = $this->resolveOwningAccount(
                $labelIds,
                $headers,
                $account,
                $siblingAccounts,
            );

            if (null === $targetAccount) {
                $this->logger->debug('SyncGmailMessageBatch: skipping message not addressed to any known account', [
                    'gmailId'   => $payload['id'] ?? '(unknown)',
                    'accountId' => $account->getId(),
                ]);
                continue;
            }

            // ── Build entity ──────────────────────────────────────────────────
            // Label resolution inside the builder runs against the ATTRIBUTED
            // account — a Gmailify sent message gets the sibling's Sent label,
            // exactly like the old mailbox routing attributed the mailbox.
            try {
                $entity = $this->messageBuilder->build($payload, $targetAccount);
                $this->em->persist($entity);

                $built[] = [
                    'message' => $entity,
                    'account' => $targetAccount,
                ];
            } catch (\Throwable $e) {
                $this->logger->error('SyncGmailMessageBatch: build failed', [
                    'gmailId' => $payload['id'] ?? '(unknown)',
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if (count($built) === 0) {
            return;
        }

        $this->em->flush();

        foreach ($built as $item) {
            try {
                $this->messageThreader->assignThread($item['message'], $item['account']);
            } catch (\Throwable $e) {
                $this->logger->error('SyncGmailMessageBatch: threading failed', [
                    'messageId' => $item['message']->getId(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $this->em->flush();

        $this->harvestService->harvestMessages(
            $account->getUsr(),
            array_column($built, 'message'),
        );

        // One Mercure event per affected account — there are no mailboxes to
        // update counts on anymore; sidebar counts are thread/label queries.
        /** @var array<int, Account> $affectedAccounts */
        $affectedAccounts = [];

        foreach ($built as $item) {
            $affectedAccounts[(int) $item['account']->getId()] = $item['account'];
        }

        foreach ($affectedAccounts as $affectedAccount) {
            $this->syncNotifier->publishAccountSynced($affectedAccount);
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Determine which account owns this message.
     *
     * For received mail (no SENT label): the Delivered-To / To address must
     * match the account's normalised Gmail address.
     *
     * For sent mail (SENT label):
     *   - If From matches the account's own address → attribute to this account.
     *   - If From matches a Gmailify sibling account AND that account has
     *     gmailSyncGmailifyEnabled → attribute to that sibling.
     *   - Otherwise skip (return null).
     *
     * @param list<string>              $labelIds
     * @param array<string,string>      $headers          lower-cased header name → value
     * @param array<string,Account>     $siblingAccounts  normalisedEmail → Account
     */
    private function resolveOwningAccount(
        array   $labelIds,
        array   $headers,
        Account $account,
        array   $siblingAccounts,
    ): ?Account {
        $isSent = true === in_array('SENT', $labelIds, true);

        if (false === $isSent) {
            // Received mail — must be addressed to this account.
            if (true === $this->addressFilter->isAddressedToAccount($headers, $account)) {
                return $account;
            }

            return null;
        }

        // Sent mail — check From against this account first.
        if (true === $this->addressFilter->isSentByAccount($headers, $account)) {
            return $account;
        }

        // Gmailify sent: check if From matches a sibling account that has the
        // feature enabled.
        if (false === $account->isGmailSyncGmailifyEnabled()) {
            return null;
        }

        $from = $headers['from'] ?? '';

        if ('' === $from) {
            return null;
        }

        $normFrom = $this->addressFilter->normalise($from);

        if (true === isset($siblingAccounts[$normFrom])) {
            return $siblingAccounts[$normFrom];
        }

        return null;
    }

    /**
     * Build a map of normalised email address → Account for all active
     * accounts belonging to the same user as $account, excluding $account itself.
     *
     * @return array<string, Account>
     */
    private function buildSiblingAccountMap(Account $account): array
    {
        $user     = $account->getUsr();
        $siblings = $this->accountRepository->findBy(['usr' => $user, 'isActive' => true]);
        $map      = [];

        foreach ($siblings as $sibling) {
            if ($sibling->getId() === $account->getId()) {
                continue;
            }

            $email = (string) $sibling->getEmail();

            if ('' === $email) {
                continue;
            }

            $map[$this->addressFilter->normalise($email)] = $sibling;
        }

        return $map;
    }

    /**
     * @param list<array{name: string, value: string}> $headers
     * @return array<string,string>  lower-cased name => value
     */
    private function indexHeaders(array $headers): array
    {
        $index = [];

        foreach ($headers as $h) {
            $index[strtolower((string) ($h['name'] ?? ''))] = (string) ($h['value'] ?? '');
        }

        return $index;
    }
}
