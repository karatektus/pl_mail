<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Domain\DTO\Mail\RemoteFlagState;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Infrastructure\Messaging\Message\SyncGraphMessageBatchMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\GraphApiClient;
use App\Service\Mail\MessageEraser;
use App\Service\Mail\SyncOrigin;
use App\Service\Mail\ThreadStatusUpdater;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Plans Graph sync work and fans it out to SyncGraphMessageBatchMessage jobs.
 *
 * Graph itself has no initial/incremental split: a delta query with no stored
 * deltaLink enumerates the whole folder and hands back a link, and the same
 * call with a link returns only changes. plMail used to take it at its word
 * and run both through one code path, which made the first sync of an account
 * a listing of every folder to its end followed by every message it held, on
 * the queue new mail arrives on (#42).
 *
 * So the two are separate here although they are one call there. sync()
 * follows the folders that have a link. A folder without one is PLANNED — see
 * Account::$graphImport — and its enumeration is importPage()'s, a page at a
 * time on the import queue, newest mail first and the Inbox before anything
 * else. Until that ends the sync asks such a folder only what it received
 * since it was planned, which is the one thing that cannot wait.
 *
 * Delta state is per FOLDER, not per account (Gmail's single historyId has no
 * equivalent here), so it lives in a folderId => deltaLink map on the Account.
 *
 * Message movement is visible but ambiguous: moving a message out of a folder
 * shows up as `@removed` on the source folder's delta and as an addition on
 * the destination folder's delta, and no body refetch is needed because delta
 * already carries parentFolderId.
 *
 * The two halves were assumed to reconcile — detach on one side, attach on the
 * other — which holds only where both carry the same id. Immutable ids are
 * exactly what a personal outlook.com mailbox does not reliably give (see
 * GraphApiClient), so the detach half regularly matched nothing and old
 * location labels accumulated: deleted drafts sat in Drafts and Trash at once.
 * So the attach is exclusive on its own and does not depend on its partner
 * arriving — the last folder a message was seen in is the folder it is in,
 * which is what Exchange means anyway.
 */
final class GraphApiSyncer
{
    /** Graph message ids per fan-out batch — the $batch sub-request ceiling. */
    private const int BATCH_SIZE = GraphApiClient::BATCH_LIMIT;

    /** Where an import's batches go. See messenger.yaml. */
    public const string IMPORT_QUEUE = 'ingest_backlog';

    /**
     * Messages asked for per page of a folder's first enumeration. The same
     * fifty an IMAP import page is, for the same reason: small enough that a
     * page is seconds and a restart loses little, large enough that the
     * listing is not most of the work.
     */
    public const int IMPORT_PAGE_SIZE = 50;

    /**
     * How many newly received messages a folder still being imported is asked
     * for per sync. More than this between two syncs is the import's to find.
     */
    private const int RECEIVED_LIMIT = 50;

    /**
     * How many unjudged removals are kept. See Account::$graphRemovals. Far
     * more than an import sees deleted under it; a bound all the same, because
     * the list lives in the account's settings.
     */
    private const int REMOVALS_KEPT = 5000;

    public function __construct(
        private readonly GraphApiClient         $apiClient,
        private readonly GraphFolderResolver    $folderResolver,
        private readonly GraphLabelPolicy       $labelPolicy,
        private readonly MessageRepository      $messageRepository,
        private readonly EntityManagerInterface $em,
        private readonly StateManager           $stateManager,
        private readonly MessageBusInterface    $bus,
        private readonly LoggerInterface        $logger,
        private readonly MessageEraser          $eraser,
        private readonly ThreadStatusUpdater    $status,
        private readonly SyncOrigin             $origin,
    ) {}

    /**
     * @param list<string> $folderIds
     */
    public function sync(Account $account, array $folderIds): void
    {
        $deltaLinks = $account->graphDeltaLinks;
        $pending    = [];

        // Which folders are the import's, and since when. Planned before
        // anything is asked, so a folder met for the first time is never
        // enumerated here.
        $planned   = array_column($account->graphImport, 'since', 'folder');
        $importing = array_column($this->plan($account, $folderIds, $deltaLinks), 'since', 'folder');

        // The two halves of "is this message anywhere". A move shows up as
        // `@removed` on the folder it left and as an ordinary item on the one
        // it arrived in, so a removal on its own means nothing until every
        // folder has had its say — which is what this run is doing anyway, one
        // folder at a time. A deletion is a removal with no arrival to pair it
        // with.
        $removed = [];
        $present = [];

        // And the coverage guard. A folder whose delta failed has not told us
        // whether it received anything, so this run cannot conclude that a
        // message is nowhere — exactly the reasoning
        // MailboxRepository::earliestSweepAcross() applies on the IMAP side.
        // A folder that is still being imported has not told us either.
        $everyFolderAnswered = [] === $importing;

        foreach ($folderIds as $folderId) {
            if (true === array_key_exists($folderId, $importing)) {
                // Planned a moment ago: nothing can have arrived since.
                if (true === array_key_exists($folderId, $planned)) {
                    foreach ($this->receivedSince($account, $folderId, (int) $importing[$folderId]) as $graphId) {
                        $pending[$graphId] = true;
                    }
                }

                continue;
            }

            try {
                $result = $this->apiClient->deltaMessages($account, $folderId, $deltaLinks[$folderId]);
            } catch (\Throwable $e) {
                $this->logger->error('GraphApiSyncer: delta failed', [
                    'accountId' => $account->id,
                    'folderId'  => $folderId,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);

                $everyFolderAnswered = false;

                continue;
            }

            if (true === $result['resyncRequired']) {
                $this->logger->warning('GraphApiSyncer: delta token expired, folder handed back to the import', [
                    'accountId' => $account->id,
                    'folderId'  => $folderId,
                ]);

                // The link is dead and the folder has to be enumerated again,
                // which is the import's work and used to be done right here:
                // the whole folder, in the sync, in front of new mail. It is
                // planned instead — from the last sync that went through,
                // because that is how far back "what arrived since" has to
                // reach for a folder that was being followed until now.
                unset($deltaLinks[$folderId]);

                $plan   = $account->graphImport;
                $plan[] = [
                    'folder' => $folderId,
                    'since'  => ($account->lastSyncedAt ?? new DateTimeImmutable())->getTimestamp(),
                    'next'   => null,
                ];

                $account->graphImport     = $plan;
                $account->graphDeltaLinks = $deltaLinks;

                // Same count as the Gmail path keeps, and it matters more here:
                // a Graph re-enumeration re-reads the whole folder and, on a
                // mailbox without immutable ids, re-downloads every body only
                // to discard them as duplicates. Once is nothing; every hour is
                // an account quietly costing a great deal.
                $account->recordFullResync();

                $this->em->flush();

                $everyFolderAnswered = false;

                continue;
            }

            foreach ($result['items'] as $item) {
                $graphId = (string) ($item['id'] ?? '');

                if ('' === $graphId) {
                    continue;
                }

                if (true === array_key_exists('@removed', $item)) {
                    $removed[$graphId] = true;

                    continue;
                }

                $present[$graphId] = true;
            }

            foreach ($this->partition($account, $folderId, $result['items']) as $graphId) {
                $pending[$graphId] = true;
            }

            if (null !== $result['deltaLink']) {
                $deltaLinks[$folderId] = $result['deltaLink'];
            }
        }

        $account->graphDeltaLinks = $deltaLinks;

        // strval, because array_keys() on an id-keyed array does not return
        // strings: PHP converts a decimal-integer-like key to an int on the way
        // in. Graph ids are long base64-ish strings and almost never all digits,
        // which is exactly what makes this the kind of bug that waits — the
        // Gmail equivalent killed whole batches with
        // `urlencode(): Argument #1 must be of type string, int given`.
        $unpaired = array_map(strval(...), array_keys(array_diff_key($removed, $present)));

        $this->settleRemovals($account, $unpaired, $everyFolderAnswered);

        $this->em->flush();

        $this->dispatchBatches($account, array_map(strval(...), array_keys($pending)));
    }

    /**
     * One page of the history of the folder whose turn it is.
     *
     * The first folder in Account::$graphImport is read until it is done, so
     * the Inbox is whole before the archive is begun. A page is listed, what
     * it names that is not stored yet is sent to be fetched on the import
     * queue, and the link to the next page is left on the account for the
     * next call. The page that ends the enumeration carries the folder's
     * delta link instead, and with that the folder is the sync's.
     *
     * A folder that cannot be read goes to the back and the answer is
     * Waiting. Thrown, the failure would take the chain of pages with it and
     * hold every folder behind this one for as long as it stays shut.
     */
    public function importPage(Account $account): GraphImportStep
    {
        $plan = $account->graphImport;

        if ([] === $plan) {
            return GraphImportStep::Finished;
        }

        $entry    = array_shift($plan);
        $folderId = $entry['folder'];

        try {
            $page = $this->apiClient->deltaMessagesPage($account, $folderId, $entry['next'], self::IMPORT_PAGE_SIZE);
        } catch (\Throwable $e) {
            $this->logger->warning('GraphApiSyncer: import page failed, folder passed over for now', [
                'accountId' => $account->id,
                'folderId'  => $folderId,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            $account->graphImport = [...$plan, $entry];
            $this->em->flush();

            return GraphImportStep::Waiting;
        }

        if (true === $page['resyncRequired']) {
            // The server has forgotten the enumeration it was part-way
            // through. From the top: what is already stored is recognised and
            // not fetched again, so this costs listings and nothing else.
            $account->graphImport = [['folder' => $folderId, 'since' => $entry['since'], 'next' => null], ...$plan];
            $this->em->flush();

            return GraphImportStep::More;
        }

        $this->dispatchBatches($account, $this->partition($account, $folderId, $page['items']), true);

        if (null !== $page['deltaLink']) {
            $deltaLinks            = $account->graphDeltaLinks;
            $deltaLinks[$folderId] = $page['deltaLink'];

            $account->graphDeltaLinks = $deltaLinks;
            $account->graphImport     = $plan;

            $this->logger->info('GraphApiSyncer: folder imported', [
                'accountId' => $account->id,
                'folderId'  => $folderId,
                'remaining' => count($plan),
            ]);
        } elseif (null !== $page['nextLink']) {
            $account->graphImport = [['folder' => $folderId, 'since' => $entry['since'], 'next' => $page['nextLink']], ...$plan];
        } else {
            // Neither link. Graph ends every page with one or the other, so
            // this is a response that was not what was asked for; there is
            // nowhere to continue from, and starting over at once would ask
            // the same thing again.
            $this->logger->warning('GraphApiSyncer: import page carried no link, folder starts over', [
                'accountId' => $account->id,
                'folderId'  => $folderId,
            ]);

            $account->graphImport = [...$plan, ['folder' => $folderId, 'since' => $entry['since'], 'next' => null]];
            $this->em->flush();

            return GraphImportStep::Waiting;
        }

        $this->em->flush();

        return [] === $account->graphImport ? GraphImportStep::Finished : GraphImportStep::More;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Work out which folders are the import's, in the order it reads them, and
     * leave that on the account.
     *
     * A folder is the import's exactly when it has no delta link. One that is
     * already planned keeps its entry — its place in an enumeration and the
     * moment it was planned — and one met for the first time is planned as of
     * now. A planned folder that has gone from the mailbox drops out.
     *
     * Sorted every time rather than once, because roles are found by the
     * folder sync and a folder can be planned before its role is known.
     *
     * @param list<string>          $folderIds
     * @param array<string, string> $deltaLinks
     * @return list<array{folder: string, since: int, next: string|null}>
     */
    private function plan(Account $account, array $folderIds, array $deltaLinks): array
    {
        $known = array_column($account->graphImport, null, 'folder');
        $now   = time();
        $plan  = [];

        foreach ($folderIds as $folderId) {
            if (true === array_key_exists($folderId, $deltaLinks)) {
                continue;
            }

            $plan[] = $known[$folderId] ?? ['folder' => $folderId, 'since' => $now, 'next' => null];
        }

        // Stable, so folders of one rank keep the order Graph listed them in
        // and the one being read stays the one being read.
        usort(
            $plan,
            fn (array $a, array $b): int => $this->rank($account, $a['folder']) <=> $this->rank($account, $b['folder']),
        );

        if ($plan !== $account->graphImport) {
            $account->graphImport = $plan;
            $this->em->flush();
        }

        return $plan;
    }

    private function rank(Account $account, string $folderId): int
    {
        return LabelRole::importRank($this->folderResolver->resolveFolder($folderId, $account)?->role);
    }

    /**
     * What a folder still being imported has received since it was planned
     * that is not stored yet.
     *
     * Failing is not worth more than a line in the log: the folder is being
     * read by the import anyway, and the next sync asks again.
     *
     * @return list<string>
     */
    private function receivedSince(Account $account, string $folderId, int $since): array
    {
        try {
            $items = $this->apiClient->listReceivedSince(
                $account,
                $folderId,
                new DateTimeImmutable('@' . $since),
                self::RECEIVED_LIMIT,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('GraphApiSyncer: could not ask an importing folder for new mail', [
                'accountId' => $account->id,
                'folderId'  => $folderId,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            return [];
        }

        return $this->partition($account, $folderId, $items);
    }

    /**
     * Act on the removals that arrived in no folder, or keep them until that
     * can be known.
     *
     * A removal is reported once. Dropping the ones a run could not judge —
     * which is what happened whenever one folder's delta failed — left the
     * rows of deleted mail behind for good: no folder label, so in no list,
     * and still in every count. They are kept on the account instead and
     * judged with the next run whose every folder answered; eraseVanished()
     * leaves alone any that turned up in a folder in the meantime.
     *
     * @param list<string> $unpaired
     */
    private function settleRemovals(Account $account, array $unpaired, bool $everyFolderAnswered): void
    {
        $kept = array_values(array_unique([...$account->graphRemovals, ...$unpaired]));

        if ([] === $kept) {
            return;
        }

        if (false === $everyFolderAnswered) {
            $account->graphRemovals = array_slice($kept, -self::REMOVALS_KEPT);

            return;
        }

        $this->eraseVanished($account, array_fill_keys($kept, true));

        $account->graphRemovals = [];
    }

    /**
     * Remove the rows for messages that left a folder and arrived in none.
     *
     * `@removed` was already handled — by taking the folder's label off — and
     * that was the whole of it, which meant a message deleted in Outlook lost
     * its label and kept its row. An Exchange message is in exactly one folder,
     * so a row wearing no folder label at all is not in the mailbox: it was
     * invisible in every list and still counted in every total, and nothing
     * ever collected it.
     *
     * The pairing this relies on is the one the class docblock already
     * describes: a move is `@removed` on the source and an ordinary item on the
     * destination, both inside this run because this run walks every folder. So
     * a removal with no arrival is a deletion — provided every folder actually
     * answered, which the caller checks, because a folder whose delta threw has
     * not said whether it received anything.
     *
     * The folder-label check is the second guard and covers the case the ids
     * cannot: a mailbox without immutable ids reports `@removed` under an id
     * the destination does not use, so the arrival is real but unrecognisable.
     * Such a row has been given the destination's label by attachFolderLabel()
     * and is therefore not label-less, and is left alone.
     *
     * @param array<string,true> $graphIds
     */
    private function eraseVanished(Account $account, array $graphIds): void
    {
        if (0 === count($graphIds)) {
            return;
        }

        $erased = 0;

        foreach (array_keys($graphIds) as $graphId) {
            // Cast for the same reason, and here the symptom would be silent:
            // an int compared against a stored string id matches nothing, so
            // the message is simply not erased and nothing is raised.
            $message = $this->messageRepository->findOneBy(['graphId' => (string) $graphId, 'account' => $account]);

            if (null === $message) {
                continue;
            }

            if (0 !== count($this->labelPolicy->folderLabels($message))) {
                // It is in a folder after all. See above.
                continue;
            }

            $this->eraser->erase($message);
            ++$erased;
        }

        if ($erased > 0) {
            $this->logger->info('GraphApiSyncer: messages removed from every folder were deleted here', [
                'accountId' => $account->id,
                'reported'  => count($graphIds),
                'erased'    => $erased,
            ]);
        }
    }

    /**
     * Split a folder's delta payload into work that needs a full fetch and work
     * that can be settled inline.
     *
     * Returns only the ids that still need their bodies pulled; relabelling of
     * already-synced messages is applied here and then flushed by the caller.
     *
     * @param list<array<string,mixed>> $items
     * @return list<string>
     */
    private function partition(Account $account, string $folderId, array $items): array
    {
        $known = array_flip(
            $this->messageRepository->findSyncedGraphIdsForUser($account->usr)
        );

        $needsFetch = [];
        $flagStates = [];

        // Before the loop, because the echo guard is measured from the moment
        // the provider was asked and the delta was already in hand when this
        // was called.
        $readAt = new DateTimeImmutable();

        foreach ($items as $item) {
            $graphId = (string) ($item['id'] ?? '');

            if ('' === $graphId) {
                continue;
            }

            if (true === array_key_exists('@removed', $item)) {
                $this->detachFolderLabel($account, $graphId, $folderId);
                continue;
            }

            if (false === array_key_exists($graphId, $known)) {
                $needsFetch[] = $graphId;
                continue;
            }

            // Already synced and still present — where it lives, and what state
            // it is in.
            $this->attachFolderLabel($account, $graphId, (string) ($item['parentFolderId'] ?? $folderId));

            // Read state used to stop here. The comment above this block said
            // the only cheap thing that could have changed was location, and it
            // was wrong: a Graph delta entry carries the whole message resource,
            // isRead and flag included, so a message read in Outlook announced
            // itself in this very payload and plMail dropped it. Mail read on a
            // phone stayed unread here, exactly as it did on IMAP, and for the
            // same reason — nothing ever re-read the state of a row it already
            // had.
            $state = $this->flagStateOf($account, $graphId, $item);

            if (null !== $state) {
                $flagStates[] = $state;
            }
        }

        // One call for the batch: it recounts threads and writes the JMAP
        // change log, and it is where the echo guard keeps an unconfirmed local
        // change from being reverted by a delta that predates it.
        if ([] !== $flagStates) {
            $this->status->applyRemoteFlags($flagStates, $readAt);
        }

        return $needsFetch;
    }

    /**
     * What one delta entry says about a stored message's flags, or null if it
     * does not say anything usable.
     *
     * Graph has no flag list, so the two booleans it does answer are turned
     * into one: RemoteFlagState::storedFlags() synthesises the mirror. \Draft
     * is deliberately not carried here — isDraft is a property of the message
     * that GraphMessageBuilder sets at ingest and that a delta on a stored
     * message does not change without the message being rewritten anyway.
     *
     * @param array<string,mixed> $item
     */
    private function flagStateOf(Account $account, string $graphId, array $item): ?RemoteFlagState
    {
        if (false === array_key_exists('isRead', $item)) {
            // A delta entry that does not mention read state is not asserting
            // that the message is unread. Absence is not an answer — the same
            // rule the IMAP listing works to.
            return null;
        }

        $message = $this->messageRepository->findOneBy(['graphId' => $graphId, 'account' => $account]);

        if (null === $message) {
            return null;
        }

        $flagStatus = $item['flag']['flagStatus'] ?? null;

        return new RemoteFlagState(
            $message,
            true === $item['isRead'],
            null === $flagStatus
                // Exchange omits `flag` on a message that has never carried
                // one. That is "not flagged", not "unknown" — unlike isRead,
                // whose absence would be a delta that simply did not mention
                // it — so the row's current star is preserved instead.
                ? null !== $message->starredAt
                : 'notFlagged' !== (string) $flagStatus,
        );
    }

    private function attachFolderLabel(Account $account, string $graphId, string $folderId): void
    {
        $message = $this->messageRepository->findOneBy(['graphId' => $graphId, 'account' => $account]);

        if (null === $message) {
            return;
        }

        $label = $this->folderResolver->resolveFolder($folderId, $account);

        if (null === $label) {
            return;
        }

        // Exclusive, not additive. An Exchange message is in exactly one
        // folder, and this half of a move used to rely on the other half —
        // the source folder's `@removed` — arriving to take the old label off.
        //
        // That pairing does not hold. The `@removed` entry carries the id the
        // source folder knew, and on a mailbox without immutable ids (personal
        // outlook.com, which is where this was found) that is not the id
        // stored here, so detachFolderLabel silently matches nothing. Deleted
        // drafts kept their Drafts label beside Trash, which is a state
        // Exchange cannot represent — ApplyGraphChangesHandler warned about it
        // on every push.
        //
        // Applying the destination exclusively makes the last move win, which
        // is exactly what the server means. Non-folder labels are untouched:
        // categories are the many-to-many axis, and Snoozed is plMail's own.
        foreach ($this->labelPolicy->folderLabels($message) as $current) {
            if ($current !== $label) {
                $message->removeLabel($current);
            }
        }

        $message->addLabel($label);

        // A folder move on the Microsoft side changes Email.mailboxIds, which
        // is the single most visible thing a JMAP client watches for. Without
        // this the message silently moves in Outlook and never in ltt.rs.
        $this->recordMoved($account, $message);

        $thread = $message->thread;

        if (null !== $thread) {
            $thread->addLabel($label);
        }
    }

    private function detachFolderLabel(Account $account, string $graphId, string $folderId): void
    {
        $message = $this->messageRepository->findOneBy(['graphId' => $graphId, 'account' => $account]);

        if (null === $message) {
            return;
        }

        $label = $this->folderResolver->resolveFolder($folderId, $account);

        if (null === $label) {
            return;
        }

        $message->removeLabel($label);
        $this->recordMoved($account, $message);
    }

    /**
     * The message's mailbox membership changed, so both it and its thread moved
     * as far as a JMAP client is concerned. record() only persists; these rows
     * commit on the caller's existing flush.
     */
    private function recordMoved(Account $account, Message $message): void
    {
        $accountId = (int) $account->id;

        $this->stateManager->recordUpdated($accountId, JmapObjectType::Email, (string) $message->id);

        $thread = $message->thread;

        if (null !== $thread) {
            $this->stateManager->recordThreadsTouched($accountId, [(int) $thread->id]);
        }
    }

    /**
     * @param list<string> $graphIds
     */
    private function dispatchBatches(Account $account, array $graphIds, bool $toImportQueue = false): void
    {
        if (count($graphIds) === 0) {
            $this->logger->info('GraphApiSyncer: nothing new to fetch', [
                'accountId' => $account->id,
            ]);

            return;
        }

        $chunks = array_chunk($graphIds, self::BATCH_SIZE);

        foreach ($chunks as $chunk) {
            // An import's batches go to the import queue and its worker, so a
            // folder's history is never in front of the mail arriving now. By
            // stamp, for the reason GmailApiSyncer gives: the message is the
            // same one either way and only this method knows which it is.
            $this->bus->dispatch(
                new SyncGraphMessageBatchMessage(
                    (int) $account->id,
                    array_values($chunk),
                    $this->origin->current(),
                ),
                true === $toImportQueue ? [new TransportNamesStamp([self::IMPORT_QUEUE])] : [],
            );
        }

        $this->logger->info('GraphApiSyncer: dispatched message batches', [
            'accountId' => $account->id,
            'messages'  => count($graphIds),
            'batches'   => count($chunks),
        ]);
    }
}
