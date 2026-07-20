<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Helper\MessageIdHelper;
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
        // Used to attribute Gmailify sent AND received messages to the correct account.
        $siblingAccounts = $this->buildSiblingAccountMap($account);

        // Dedup inside the batch too — batches can overlap across runs/retries.
        // USER-scoped, not account-scoped: Gmailify attribution stores messages
        // under sibling accounts, so a carrier-scoped lookup would miss them
        // and re-insert on every retry. Enriched IMAP rows get their gmailId
        // set, so they are covered here too and enrichment runs exactly once.
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

        /** @var array<int, Account> $affectedAccounts */
        $affectedAccounts = [];
        $enriched         = 0;

        foreach ($payloads as $payload) {
            $labelIds = array_values(array_map('strval', $payload['labelIds'] ?? []));
            $headers  = $this->indexHeaders($payload['payload']['headers'] ?? []);
            $gmailId  = (string) ($payload['id'] ?? '');

            // ── Address ownership filter ──────────────────────────────────────
            $targetAccount = $this->resolveOwningAccount(
                $labelIds,
                $headers,
                $account,
                $siblingAccounts,
            );

            if (null === $targetAccount) {
                $this->logger->debug('SyncGmailMessageBatch: skipping message not attributable to any known account', [
                    'gmailId'   => '' !== $gmailId ? $gmailId : '(unknown)',
                    'accountId' => $account->getId(),
                ]);
                continue;
            }

            // ── Gmailify dedup: merge, don't skip ─────────────────────────────
            // When the sibling's own IMAP sync already holds this message
            // (same canonical RFC Message-ID), the IMAP row keeps ownership of
            // location/flags — but it still gains everything the Gmail copy
            // knows: gmailId, gmailLabelIds, and the translated labels.
            if ($targetAccount !== $account) {
                $rfcMessageId = MessageIdHelper::normalise($headers['message-id'] ?? '');

                if ('' !== $rfcMessageId) {
                    $existing = $this->messageRepository->findOneForAccountByMessageId($targetAccount, $rfcMessageId);

                    if (null !== $existing) {
                        $this->enrichExisting($existing, $labelIds, $gmailId, $targetAccount, $account);

                        $enriched++;
                        $affectedAccounts[(int) $targetAccount->getId()] = $targetAccount;
                        continue;
                    }
                }
            }

            // ── Build entity ──────────────────────────────────────────────────
            // Label resolution runs against the CARRIER account (this Gmail
            // account owns the labelIds), then translates onto the attributed
            // account: system labels by role, custom labels by name chain.
            try {
                $entity = $this->messageBuilder->build($payload, $targetAccount, $account);
                $this->em->persist($entity);

                $built[] = [
                    'message' => $entity,
                    'account' => $targetAccount,
                ];

                $affectedAccounts[(int) $targetAccount->getId()] = $targetAccount;
            } catch (\Throwable $e) {
                $this->logger->error('SyncGmailMessageBatch: build failed', [
                    'gmailId' => '' !== $gmailId ? $gmailId : '(unknown)',
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if (count($built) === 0 && 0 === $enriched) {
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

        if (count($built) > 0) {
            $this->harvestService->harvestMessages(
                $account->getUsr(),
                array_column($built, 'message'),
            );
        }

        // One Mercure event per affected account — there are no mailboxes to
        // update counts on anymore; sidebar counts are thread/label queries.
        foreach ($affectedAccounts as $affectedAccount) {
            $this->syncNotifier->publishAccountSynced($affectedAccount);
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Merge the Gmail copy's knowledge onto an existing IMAP-synced row:
     * gmailId (covers it under the user-scoped dedup from now on),
     * gmailLabelIds, and the carrier's labels translated onto the target
     * account, propagated to the thread. Flags/read state stay untouched —
     * the IMAP copy owns those.
     *
     * @param list<string> $labelIds
     */
    private function enrichExisting(
        Message $existing,
        array   $labelIds,
        string  $gmailId,
        Account $target,
        Account $carrier,
    ): void {
        if ('' !== $gmailId) {
            $existing->setGmailId($gmailId);
        }

        $existing->setGmailLabelIds($labelIds);

        $this->messageBuilder->applyTranslatedLabels($existing, $labelIds, $target, $carrier);

        $thread = $existing->getThread();

        if (null !== $thread) {
            foreach ($existing->getLabels() as $label) {
                $thread->addLabel($label);
            }
        }
    }

    /**
     * Determine which account owns this message.
     *
     * Received mail (no SENT label):
     *   - Delivered-To / To matches this account         → this account.
     *   - Delivered-To / To / Cc matches a sibling AND the carrier has
     *     gmailSyncGmailifyEnabled                       → the sibling
     *     (the loop then decides between enriching the sibling's existing
     *     IMAP row and importing a new one).
     *   - Otherwise skip (return null).
     *
     * Sent mail (SENT label):
     *   - From matches this account's own address        → this account.
     *   - From matches a Gmailify sibling AND the carrier has
     *     gmailSyncGmailifyEnabled                       → the sibling.
     *   - Otherwise skip (return null).
     *
     * @param list<string>          $labelIds
     * @param array<string,string>  $headers          lower-cased header name → value
     * @param array<string,Account> $siblingAccounts  normalisedEmail → Account
     */
    private function resolveOwningAccount(
        array   $labelIds,
        array   $headers,
        Account $account,
        array   $siblingAccounts,
    ): ?Account {
        $isSent           = true === in_array('SENT', $labelIds, true);
        $gmailifyEnabled  = true === $account->getSetting('gmailSyncGmailifyEnabled', true);

        if (false === $isSent) {
            // Received mail — addressed to this account directly?
            if (true === $this->addressFilter->isAddressedToAccount($headers, $account)) {
                return $account;
            }

            if (false === $gmailifyEnabled) {
                return null;
            }

            // Gmailify: delivered to a sibling account.
            return $this->addressFilter->resolveRecipientAccount($headers, $siblingAccounts);
        }

        // Sent mail — check From against this account first.
        if (true === $this->addressFilter->isSentByAccount($headers, $account)) {
            return $account;
        }

        // Gmailify sent: check if From matches a sibling account.
        if (false === $gmailifyEnabled) {
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
