<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Repository\Mail\GmailSkippedMessageRepository;
use App\Entity\Mail\GmailSkippedMessage;
use App\Domain\Enum\Mail\GmailSkipReason;
use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\DTO\Mail\RemoteFlagState;
use App\Domain\Helper\MessageIdHelper;
use DateTimeImmutable;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Infrastructure\Messaging\Message\SyncGmailMessageBatchMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Gmail\GmailAddressFilter;
use App\Service\Gmail\GmailApiSyncer;
use App\Service\Gmail\GmailMessageBuilder;
use App\Service\Gmail\GmailQuotaPacer;
use App\Service\HarvestContactsService;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Imap\MessageThreader;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\MessageCategorizer;
use App\Service\Mail\PostIngestPipeline;
use App\Service\Mail\SyncNotifier;
use App\Service\Mail\SyncOrigin;
use App\Service\Mail\ThreadStatusUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsMessageHandler]
final readonly class SyncGmailMessageBatchHandler
{
    /**
     * Delay before re-fetching sub-requests that failed for a reason other
     * than quota — a 5xx, a part missing from the response. One refused on
     * quota waits as long as the pacer says the account is being left alone.
     */
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
        private SyncOrigin              $origin,
        private GmailQuotaPacer         $pacer,
        private GmailSkippedMessageRepository $skipped,
    ) {}

    public function __invoke(SyncGmailMessageBatchMessage $message): void
    {
        // Everything stored from here on is marked with what started this
        // sync. See SyncOrigin, and SyncAccountMessage for the `??`.
        $this->origin->during($message->trigger ?? null, fn () => $this->handle($message));
    }

    private function handle(SyncGmailMessageBatchMessage $message): void
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
        $toFetch     = array_values($message->gmailIds);
        $onlyMissing = true === ($message->onlyMissing ?? false);

        // EXCEPT A LISTING'S BATCH, which names what was missing when the
        // listing ran and has nothing to say about labels. An import that
        // takes longer than the hour between listings has every outstanding
        // id on the queue once per listing: seventy thousand messages named a
        // second time, a third, each fetched in full again at sixty quota
        // units apiece on the quota the import was already short of (#90).
        if (true === $onlyMissing) {
            // And not what has been looked at and declined, for the same
            // reason: a listing that ran before the first of these batches
            // was handled named those too. See GmailSkippedMessage.
            $stored  = array_flip([
                ...$this->messageRepository->findStoredGmailIdsForUser($account->usr, $toFetch),
                ...$this->skipped->findGmailIdsAmong($account, array_map(strval(...), $toFetch)),
            ]);
            $toFetch = array_values(array_filter(
                $toFetch,
                static fn (string|int $id): bool => false === isset($stored[(string) $id]),
            ));
        }

        if (count($toFetch) === 0) {
            return;
        }

        // Google has just refused this account, here or in another process.
        // Asking now is a request that will be refused too and counted
        // against the minute all the same, so the batch is put back whole to
        // come round when the refusal has cleared. Not an error and not a
        // retry: nothing went wrong, and there is no ladder to climb.
        if ($this->pacer->waitSeconds($account) > 0) {
            $this->putBack($account, $toFetch, $onlyMissing);

            return;
        }

        $fetch    = $this->apiClient->getMessages($account, $toFetch);
        $payloads = $fetch['payloads'];

        // ── Fetch accountability ──────────────────────────────────────────────
        // Every id we asked for is either processed below, re-queued (transient
        // sub-request failure — typically 429 mid-batch on initial syncs), or
        // logged as permanently gone. Nothing gets dropped silently.
        // What this account has already declined among the ids in hand: one
        // read, so a message is neither recorded twice nor left on the record
        // once it is stored after all.
        $alreadySkipped = array_flip($this->skipped->findGmailIdsAmong($account, array_map(strval(...), $toFetch)));
        $nowStored      = [];
        $newlySkipped   = 0;

        if (count($fetch['gone']) > 0) {
            $this->logger->warning('SyncGmailMessageBatch: messages permanently unfetchable, skipping', [
                'accountId' => $account->id,
                'gmailIds'  => $fetch['gone'],
            ]);

            // Written down, so the next listing does not name them again. A
            // message Gmail lists and answers 404 for is not going to arrive
            // by being asked for every hour.
            foreach ($fetch['gone'] as $goneId) {
                $goneId = (string) $goneId;

                if ('' !== $goneId && false === isset($alreadySkipped[$goneId])) {
                    $this->em->persist(new GmailSkippedMessage($account, $goneId, GmailSkipReason::Unfetchable));
                    $alreadySkipped[$goneId] = true;
                    ++$newlySkipped;
                }
            }
        }

        if (count($fetch['retryable']) > 0) {
            $this->logger->info('SyncGmailMessageBatch: re-queueing failed sub-requests', [
                'accountId' => $account->id,
                'count'     => count($fetch['retryable']),
            ]);

            $this->putBack($account, $fetch['retryable'], $onlyMissing);
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

                // The debug line above used to be the only trace, and nothing
                // reads a debug line. The import knew a message only as
                // stored or missing, so this one stayed missing: listed again
                // every hour, fetched again in full, declined again (#90).
                // With what the decision was made on, so `app:gmail:skipped`
                // can say what kind of message it was.
                if ('' !== $gmailId && false === isset($alreadySkipped[$gmailId])) {
                    $this->em->persist(new GmailSkippedMessage(
                        $account,
                        $gmailId,
                        GmailSkipReason::Unattributable,
                        $labelIds,
                        $headers,
                    ));
                    $alreadySkipped[$gmailId] = true;
                    ++$newlySkipped;
                }

                continue;
            }

            // Declined once and attributable now — another account has been
            // connected since, or the rule has changed. It is about to be
            // stored or merged, so it comes off the record.
            if (true === isset($alreadySkipped[$gmailId])) {
                $nowStored[] = $gmailId;
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
            //
            // FIRST, ahead of the two merges below, which find a row by
            // Message-ID. A Message-ID can be on more than one row of an
            // account and the id cannot, so a merged row looked up by
            // Message-ID again was sometimes the other one — given this id
            // while its twin still held it, a duplicate key, and the whole
            // batch failed and was fetched again on every retry (#168).
            if ('' !== $gmailId) {
                $known = $this->messageRepository->findOneByGmailIdForUser($account->usr, $gmailId);

                if (null !== $known) {
                    // A native Gmail row: Gmail is the authority on its flags,
                    // and UNREAD/STARRED are in the very labelIds this re-fetch
                    // was made to re-read. A merged one is another account's
                    // copy, whose own sync owns its flags; the carrier column
                    // says which this is.
                    $this->enrichExisting($known, $labelIds, $gmailId, $known->account, $account, null === $known->gmailCarrierAccount);

                    $enriched++;
                    $affectedAccounts[(int) $known->account->id] = $known->account;
                    continue;
                }
            }

            // ── Claimed by nobody: is it another account's copy? ──────────────
            // A message whose headers name neither this account nor any other
            // is this account's — it is in its mailbox. But Gmail may have
            // fetched it from another connected account whose own sync holds
            // it already: a newsletter Bcc'd to that address, collected by
            // Gmail with nothing of the address left in its headers. Stored
            // here as well, it would be listed twice. So before it is taken
            // as this account's, the user's other accounts are asked for the
            // same Message-ID, and a copy found there is enriched instead,
            // exactly as a Gmailify sibling's is below.
            if ($targetAccount === $account && false === $this->namesCarrier($labelIds, $headers, $account)) {
                $copy = $this->copyOnAnotherAccount($headers, $siblingAccounts);

                if (null !== $copy) {
                    if (false === $this->mayTakeGmailId($copy, $account)) {
                        $newlySkipped += $this->recordHeldElsewhere($account, $gmailId, $labelIds, $headers, $alreadySkipped, $nowStored);
                        continue;
                    }

                    $this->enrichExisting($copy, $labelIds, $gmailId, $copy->account, $account, false);
                    $enriched++;
                    $affectedAccounts[(int) $copy->account->id] = $copy->account;
                    continue;
                }
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

                    if (null !== $existing && false === $this->mayTakeGmailId($existing, $account)) {
                        $newlySkipped += $this->recordHeldElsewhere($account, $gmailId, $labelIds, $headers, $alreadySkipped, $nowStored);
                        continue;
                    }

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

        $this->skipped->forget($account, $nowStored);

        if (count($built) === 0 && 0 === $enriched) {
            // Nothing to store, but possibly something to remember: a batch
            // made up entirely of messages that were declined still has to
            // write that down, or it is fetched again in an hour.
            if ($newlySkipped > 0) {
                $this->em->flush();
            }

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
     * Queue these ids to be fetched later: after the account's quota refusal
     * has cleared, if it is under one, and after a short pause otherwise.
     *
     * A few seconds are added to a quota wait, and not the same few for every
     * batch. An import has dozens of batches queued behind the one that was
     * refused, each of which is put back in turn; all given the same delay
     * they would all come due in the same second.
     *
     * Back onto the queue this kind of work belongs on. Left to the routing
     * table it lands on the live queue, and while an account is importing
     * that is its history jumping in front of the mail arriving now.
     *
     * @param list<string> $gmailIds
     */
    private function putBack(Account $account, array $gmailIds, bool $onlyMissing): void
    {
        $wait  = $this->pacer->waitSeconds($account);
        $delay = $wait > 0 ? 1000 * ($wait + random_int(1, 15)) : self::RETRY_DELAY_MS;

        $stamps = [new DelayStamp($delay)];

        if (true === $account->needsBackfill()) {
            $stamps[] = new TransportNamesStamp([GmailApiSyncer::IMPORT_QUEUE]);
        }

        $this->bus->dispatch(
            new SyncGmailMessageBatchMessage((int) $account->id, $gmailIds, $this->origin->current(), $onlyMissing),
            $stamps,
        );

        $this->logger->info('SyncGmailMessageBatch: put back to be fetched later', [
            'accountId'    => $account->id,
            'count'        => count($gmailIds),
            'delaySeconds' => intdiv($delay, 1000),
            'quota'        => $wait > 0,
        ]);
    }

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

        // WHO CAN ACT ON THIS ID LATER.
        //
        // The line above gives an IMAP row a Gmail identity, and an identity
        // nobody can use is worse than none: LabelChangePropagator asked the
        // row's own account whether to tell Gmail about a change, and for a
        // Gmailified row that account is the IMAP one, so the answer was always
        // no. Archive, trash, restore, star and every label change stopped at
        // the local database and at IMAP.
        //
        // Recorded here because here is the only place that knows. The carrier
        // is the account whose sync recognised this message; it cannot be
        // re-derived afterwards, since a user may have two Gmail accounts and a
        // gmailId means something to exactly one of them.
        //
        // Only when the carrier is somebody else. A native Gmail row's own
        // account is already the one that can act, and pointing the column at
        // it would be a second copy of account_id to keep in step.
        if ($carrier !== $target) {
            $existing->gmailCarrierAccount = $carrier;
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
        // The reader's own preference, which is what decides whether Gmail's
        // labels are authoritative here at all. Without it this path would
        // re-derive the category from the shipped default and quietly undo the
        // choice of anybody who asked plMail to overrule Google — on the very
        // account where they asked for it.
        $existing->category = $this->categorizer->categorize(
            $existing,
            [],
            $existing->account->usr?->categorySorting,
        );

        if (true === $ownsFlags) {
            // A stored draft whose message now carries SENT and no longer
            // DRAFT was sent (Gmail swaps one label for the other on the same
            // message). Only SENT says so, as only Sent Items does on Graph:
            // a draft with neither label is not known to be sent. Ahead of the
            // refresh below, so that it sees the row as it now is.
            if (true === in_array('SENT', $labelIds, true) && false === in_array('DRAFT', $labelIds, true)) {
                $existing->settleAsSent(new DateTimeImmutable());
            }

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
     * Whether a row another account holds may be given this carrier's Gmail
     * id — what both merges above do to the copy they find.
     *
     * Both were written for a sibling whose own sync is IMAP: its rows have no
     * Gmail id, and the carrier's is the only one they will ever get. But the
     * sibling map is every active account, and a user with several Gmail
     * accounts has siblings that sync Gmail themselves. Mail forwarded from
     * one to another reaches the second still Delivered-To the first, and is
     * attributed there; the row it is merged into already has an id, its own
     * mailbox's (#168). Overwritten, that row stopped answering to its own
     * sync, which stored the message again under its own id — two rows with
     * one Message-ID, a duplicate key the next time either was merged into.
     *
     * So: never a row of a Gmail account, whose ids are its own, and never a
     * row an id from somewhere else has already reached. A row this carrier
     * merged before is found by its id before either merge is tried.
     */
    private function mayTakeGmailId(Message $row, Account $carrier): bool
    {
        if (true === $row->account->isGmail()) {
            return false;
        }

        return null === $row->gmailId || '' === $row->gmailId || $row->gmailCarrierAccount === $carrier;
    }

    /**
     * Write down a message another account already holds, so the import
     * counts it as dealt with and the next listing does not fetch it again.
     *
     * @param list<string>         $labelIds
     * @param array<string,string> $headers
     * @param array<string,mixed>  $alreadySkipped
     * @param list<string>         $nowStored      what comes off the record; this does not
     *
     * @return int 1 when it was written down now, 0 when it already was
     */
    private function recordHeldElsewhere(
        Account $account,
        string  $gmailId,
        array   $labelIds,
        array   $headers,
        array   &$alreadySkipped,
        array   &$nowStored,
    ): int {
        $nowStored = array_values(array_diff($nowStored, [$gmailId]));

        if ('' === $gmailId || true === isset($alreadySkipped[$gmailId])) {
            return 0;
        }

        $this->em->persist(new GmailSkippedMessage($account, $gmailId, GmailSkipReason::HeldElsewhere, $labelIds, $headers));
        $alreadySkipped[$gmailId] = true;

        return 1;
    }

    /**
     * Determine which account owns this message.
     *
     * The message is in THIS account's Gmail mailbox, so it is this account's
     * unless another connected account has a better claim to it. That is the
     * rule; everything below is the "better claim".
     *
     * It used to be the other way round: a message was stored only when its
     * headers named this account, and anything else was dropped. Measured on
     * a real mailbox (#90, `app:gmail:skipped`), what that dropped was 202
     * messages from the user's own Sent — written from "send mail as"
     * addresses — 89 delivered to a forwarding address that is not connected
     * here, 43 drafts and 4 addressed to the `googlemail.com` spelling of the
     * account. All of it is in Gmail, in the folders the user would look for
     * it in, and none of it belongs to any other account.
     *
     * Received mail (no SENT, no DRAFT label):
     *   - With Gmailify attribution, GmailAddressFilter::resolveReceivedAccount
     *     picks a sibling when one is named; the carrier otherwise.
     *   - Without it, mail genuinely addressed to this account only. Switching
     *     Gmailify attribution off is a deliberate "only what was sent to this
     *     address", and it stays that.
     *
     * Sent mail and drafts (SENT or DRAFT):
     *   - From a Gmailify sibling, with attribution on → the sibling.
     *   - Otherwise → this account. A message in this account's Sent was sent
     *     by its owner, whichever of their addresses it went out as.
     *
     * Chats (CHAT) → nobody. A chat is not mail and has nowhere to go here.
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
        if (true === in_array('CHAT', $labelIds, true)) {
            return null;
        }

        $written          = true === in_array('SENT', $labelIds, true) || true === in_array('DRAFT', $labelIds, true);
        $gmailifyEnabled  = true === $account->getSetting('gmailSyncGmailifyEnabled', true);

        if (false === $written) {
            if (false === $gmailifyEnabled) {
                return true === $this->addressFilter->isAddressedToAccount($headers, $account) ? $account : null;
            }

            return $this->addressFilter->resolveReceivedAccount($headers, $account, $siblingAccounts) ?? $account;
        }

        if (true === $this->addressFilter->isSentByAccount($headers, $account) || false === $gmailifyEnabled) {
            return $account;
        }

        $from = $headers['from'] ?? '';

        if ('' !== $from) {
            $normFrom = $this->addressFilter->normalise($from);

            if (true === isset($siblingAccounts[$normFrom])) {
                return $siblingAccounts[$normFrom];
            }
        }

        return $account;
    }

    /**
     * Whether the headers themselves tie the message to this account, as
     * opposed to its having landed here because nobody else claimed it.
     *
     * @param list<string>         $labelIds
     * @param array<string,string> $headers
     */
    private function namesCarrier(array $labelIds, array $headers, Account $account): bool
    {
        $written = true === in_array('SENT', $labelIds, true) || true === in_array('DRAFT', $labelIds, true);

        return true === $written
            ? $this->addressFilter->isSentByAccount($headers, $account)
            : $this->addressFilter->isAddressedToAccount($headers, $account);
    }

    /**
     * The same message already stored on another of the user's accounts, by
     * RFC Message-ID, or null.
     *
     * @param array<string,string>  $headers
     * @param array<string,Account> $siblingAccounts normalisedEmail → Account; one account can appear under several keys
     */
    private function copyOnAnotherAccount(array $headers, array $siblingAccounts): ?Message
    {
        $rfcMessageId = MessageIdHelper::normalise($headers['message-id'] ?? '');

        if ('' === $rfcMessageId) {
            return null;
        }

        $asked = [];

        foreach ($siblingAccounts as $sibling) {
            if (true === isset($asked[(int) $sibling->id])) {
                continue;
            }

            $asked[(int) $sibling->id] = true;

            $existing = $this->messageRepository->findOneForAccountByMessageId($sibling, $rfcMessageId);

            if (null !== $existing) {
                return $existing;
            }
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
