<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\DTO\Mail\RemoteFlagState;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Infrastructure\Messaging\Message\SyncGmailMessageBatchMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Gmail\GmailAddressFilter;
use App\Service\Gmail\GmailMessageBuilder;
use App\Service\HarvestContactsService;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Imap\MessageThreader;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\MessageCategorizer;
use App\Service\Mail\PostIngestPipeline;
use App\Service\Mail\SyncNotifier;
use App\Service\Mail\ThreadStatusUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final readonly class SyncGmailMessageBatchHandler
{
    /** Delay before re-fetching sub-requests that hit the rate limit. */
    private const int RETRY_DELAY_MS = 30000;

    public function __construct(
        private MessageRepository      $messageRepository,
        private AccountRepository      $accountRepository,
        private GmailApiClient         $apiClient,
        private GmailMessageBuilder    $messageBuilder,
        private GmailAddressFilter     $addressFilter,
        private HarvestContactsService $harvestService,
        private SyncNotifier           $syncNotifier,
        private MessageBusInterface    $bus,
        private PostIngestPipeline     $postIngest,
        private EntityManagerInterface $em,
        private StateManager           $stateManager,
        private LoggerInterface        $logger,
        private ThreadLabelSynchronizer $threadLabels,
        private ThreadStatusUpdater     $status,
        private MessageCategorizer      $categorizer,
        private MessageThreader         $messageThreader,
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

        // Everything the planner asked for, including ids already stored.
        //
        // This used to drop those, and dropping them silently defeated the one
        // case that most needs them. GmailApiSyncer sends two sets: new ids,
        // which it has already filtered against what is stored, and RELABELLED
        // ids, which are stored by definition and are sent precisely because
        // something about them changed. Its own comment says they "bypass that
        // filter and the batch handler recognises them and enriches rather than
        // inserting" — and then this method re-applied the identical filter and
        // returned before fetching anything. A label change in Gmail, read
        // state included, reached this handler and stopped here.
        //
        // Idempotency does not depend on this filter and never did: the loop
        // below looks each id up before building, and enriches when it finds a
        // row. A redelivered batch therefore costs one re-fetch and updates
        // instead of inserting, which is what it should always have done.
        $toFetch = array_values($message->gmailIds);

        if (count($toFetch) === 0) {
            return;
        }

        $fetch    = $this->apiClient->getMessages($account, $toFetch);
        $payloads = $fetch['payloads'];

        // ── Fetch accountability ──────────────────────────────────────────────
        // Every id we asked for is either processed below, re-queued (transient
        // sub-request failure — typically 429 mid-batch on initial syncs), or
        // logged as permanently gone. Nothing gets dropped silently.
        if (count($fetch['gone']) > 0) {
            $this->logger->warning('SyncGmailMessageBatch: messages permanently unfetchable, skipping', [
                'accountId' => $account->id,
                'gmailIds'  => $fetch['gone'],
            ]);
        }

        if (count($fetch['retryable']) > 0) {
            $this->logger->info('SyncGmailMessageBatch: re-queueing failed sub-requests', [
                'accountId' => $account->id,
                'count'     => count($fetch['retryable']),
            ]);

            $this->bus->dispatch(
                new SyncGmailMessageBatchMessage($account->id, $fetch['retryable']),
                [new DelayStamp(self::RETRY_DELAY_MS)],
            );
        }

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
                    'accountId' => $account->id,
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
                        // Gmailify: the row is the sibling's own IMAP copy, and
                        // the IMAP side owns its flags. Enrich the labels, leave
                        // the read state to the folder listing that can see it.
                        $this->enrichExisting($existing, $labelIds, $gmailId, $targetAccount, $account, false);

                        $enriched++;
                        $affectedAccounts[(int) $targetAccount->id] = $targetAccount;
                        continue;
                    }
                }
            }

            // ── Already ours: a label change, not a new message ───────────────
            // The planner used to hand over none of these — newGmailIds() drops
            // anything already stored, so a message could only arrive here once
            // and building was the only case. Label changes broke that
            // assumption: they are reported for messages plMail has had for
            // months, and the point of re-fetching one is precisely to re-read
            // labels that have changed underneath it.
            //
            // Looking first is also what makes this handler idempotent. A
            // redelivered batch — a worker restart, a retry after a partial
            // failure — used to build a second row for every message in it.
            if ('' !== $gmailId) {
                $known = $this->messageRepository->findOneBy(['gmailId' => $gmailId]);

                if (null !== $known) {
                    // A native Gmail row: Gmail is the authority on its flags,
                    // and UNREAD/STARRED are in the very labelIds this re-fetch
                    // was made to re-read.
                    $this->enrichExisting($known, $labelIds, $gmailId, $targetAccount, $account, true);

                    $enriched++;
                    $affectedAccounts[(int) $targetAccount->id] = $targetAccount;
                    continue;
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

                $affectedAccounts[(int) $targetAccount->id] = $targetAccount;
            } catch (\Throwable $e) {
                $this->logger->error('SyncGmailMessageBatch: build failed', [
                    'gmailId'   => '' !== $gmailId ? $gmailId : '(unknown)',
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        if (count($built) === 0 && 0 === $enriched) {
            return;
        }

        $this->em->flush();

        // Gmailify means the owning account is not always the carrier, so each
        // message carries its own — threading and JMAP state belong to the
        // sibling that owns the address, rules to the account that fetched.
        $ingested = [];

        foreach ($built as $item) {
            $ingested[] = new IngestedMessage($item['message'], $item['account']);
        }

        $result = $this->postIngest->run($account, $ingested);

        if (false === $result->isEmpty()) {
            $this->harvestService->harvestMessages(
                $account->usr,
                $result->messages,
                $account->email
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
     * Merge the Gmail copy's knowledge onto an existing row: gmailId (covers it
     * under the user-scoped dedup from now on), gmailLabelIds, the inbox
     * category those labels decide, and the carrier's labels translated onto
     * the target account, propagated to the thread.
     *
     * Deliberately outside PostIngestPipeline: the row already went through it
     * on the IMAP side, so re-running would record a second create for an id
     * JMAP clients hold, and re-apply rules to mail the user may since have
     * filed by hand. Enrichment adds Gmail's label knowledge, not new content.
     *
     * ## Read state, and who owns it
     *
     * $ownsFlags is the whole of the difference. Gmail models read and starred
     * as the labels UNREAD and STARRED, so they arrive in the very labelIds
     * this re-fetch exists to re-read — and this method used to drop them on
     * the floor for every row alike. On a native Gmail account that was the
     * same gap plain IMAP had: mail read on a phone stayed unread in plMail
     * forever, because nothing ever re-read the state of a message it already
     * had. GmailMessageBuilder reads both at ingest and then never again.
     *
     * False only for the Gmailify case, where the row is a sibling account's
     * own IMAP copy. There the IMAP folder listing is the authority on flags
     * and this carrier is a bystander that happens to see the same mail.
     *
     * @param list<string> $labelIds
     */
    private function enrichExisting(
        Message $existing,
        array   $labelIds,
        string  $gmailId,
        Account $target,
        Account $carrier,
        bool    $ownsFlags,
    ): void {
        if ('' !== $gmailId) {
            $existing->gmailId = $gmailId;
        }

        $existing->gmailLabelIds = $labelIds;

        $this->messageBuilder->applyTranslatedLabels($existing, $labelIds, $target, $carrier);

        // ## Recategorise, because the line above changed the only signal
        //
        // On a Gmail row the categorizer reads nothing but the CATEGORY_*
        // labels, and those have just been overwritten with Gmail's current
        // answer. Without this, category keeps whatever ingest computed —
        // and Gmail re-classifies after delivery, as well as every time the
        // user moves mail between tabs, so that answer goes stale on exactly
        // the mail this re-fetch exists to keep current.
        //
        // The drift was visible: the message view explains the category live
        // from the labels, while the inbox tabs filter on the thread's stored
        // column. A relabelled promotion said "Gmail said so: CATEGORY_
        // PROMOTIONS" in the reading pane and sat in Primary in the list,
        // until the next `app:backfill category` run.
        //
        // Empty correspondent map on purpose: gmailLabelIds is non-null by the
        // line above, so the categorizer takes its Gmail branch and never
        // reaches the correspondence override that map feeds.
        $existing->category = $this->categorizer->categorize($existing, []);

        if (true === $ownsFlags) {
            // One row at a time because enrichment is one row at a time, and
            // this is the call that recounts the thread, updates the unread
            // badge and records the JMAP change — as well as declining to
            // revert a local change Gmail has not confirmed yet.
            $this->status->applyRemoteFlags([
                new RemoteFlagState(
                    $existing,
                    false === in_array('UNREAD', $labelIds, true),
                    true === in_array('STARRED', $labelIds, true),
                ),
            ]);
        }

        // Enrichment rewrites the message's labels, i.e. Email.mailboxIds — a
        // change a JMAP client must see. The row already has an id, so this is
        // safe to record before the batch flush.
        $this->stateManager->recordUpdated(
            (int) $target->id,
            JmapObjectType::Email,
            (string) $existing->id,
        );

        $thread = $existing->thread;

        if (null !== $thread) {
            // Re-derived rather than accumulated. This used to add the
            // message's labels to the thread and never take any off, which was
            // consistent with label application only ever adding — now that a
            // label can genuinely leave a message, a thread that kept the union
            // of everything its messages had *ever* carried would go on showing
            // in the inbox after the last of them was archived.
            $this->threadLabels->sync($thread);

            // Most-recent-wins, the same rule ingest and the backfill apply.
            // The tabs read the thread, not the message, so recategorising the
            // row above only reaches the inbox through here.
            $this->messageThreader->refreshCategory($existing, $thread);

            $this->stateManager->recordThreadsTouched((int) $target->id, [(int) $thread->id]);
        }
    }

    /**
     * Determine which account owns this message.
     *
     * Received mail (no SENT label):
     *   - Without Gmailify attribution: only mail genuinely addressed to
     *     this account is considered.
     *   - With it: GmailAddressFilter::resolveReceivedAccount decides
     *     between carrier and siblings, To/Cc outranking a
     *     carrier-matching Delivered-To (Gmail stamps its own address on
     *     fetched mail). The loop then decides between enriching the
     *     sibling's existing IMAP row and importing a new one.
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
            if (false === $gmailifyEnabled) {
                if (true === $this->addressFilter->isAddressedToAccount($headers, $account)) {
                    return $account;
                }

                return null;
            }

            return $this->addressFilter->resolveReceivedAccount($headers, $account, $siblingAccounts);
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
        $user     = $account->usr;
        $siblings = $this->accountRepository->findBy(['usr' => $user, 'isActive' => true]);
        $map      = [];

        foreach ($siblings as $sibling) {
            if ($sibling->id === $account->id) {
                continue;
            }

            $email = (string) $sibling->email;

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
