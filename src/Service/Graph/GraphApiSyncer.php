<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Infrastructure\Messaging\Message\SyncGraphMessageBatchMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\GraphApiClient;
use App\Service\Mail\MessageEraser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Plans Graph sync work and fans it out to SyncGraphMessageBatchMessage jobs.
 *
 * Unlike Gmail there is no initial/incremental split: a delta query with no
 * stored deltaLink enumerates the whole folder and hands back a link, and the
 * same call with a link returns only changes. One code path covers both.
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
    ) {}

    /**
     * @param list<string> $folderIds
     */
    public function sync(Account $account, array $folderIds): void
    {
        $deltaLinks = $account->graphDeltaLinks;
        $pending    = [];

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
        $everyFolderAnswered = true;

        foreach ($folderIds as $folderId) {
            $storedLink = $deltaLinks[$folderId] ?? null;

            try {
                $result = $this->apiClient->deltaMessages($account, $folderId, $storedLink);
            } catch (\Throwable $e) {
                $this->logger->error('GraphApiSyncer: delta failed', [
                    'accountId' => $account->id,
                    'folderId'  => $folderId,
                    'error'     => $e->getMessage(),
                ]);

                $everyFolderAnswered = false;

                continue;
            }

            if (true === $result['resyncRequired']) {
                $this->logger->warning('GraphApiSyncer: delta token expired, re-enumerating folder', [
                    'accountId' => $account->id,
                    'folderId'  => $folderId,
                ]);

                unset($deltaLinks[$folderId]);
                $account->graphDeltaLinks = $deltaLinks;
                $this->em->flush();

                try {
                    $result = $this->apiClient->deltaMessages($account, $folderId, null);
                } catch (\Throwable $e) {
                    $this->logger->error('GraphApiSyncer: re-enumeration failed', [
                        'accountId' => $account->id,
                        'folderId'  => $folderId,
                        'error'     => $e->getMessage(),
                    ]);

                    $everyFolderAnswered = false;

                    continue;
                }
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

        if (true === $everyFolderAnswered) {
            $this->eraseVanished($account, array_diff_key($removed, $present));
        }

        $this->em->flush();

        $this->dispatchBatches($account, array_keys($pending));
    }

    // ── Private ───────────────────────────────────────────────────────────────

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
            $message = $this->messageRepository->findOneBy(['graphId' => $graphId]);

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

            // Already synced and still present — the only thing that can have
            // changed cheaply is where it lives.
            $this->attachFolderLabel($account, $graphId, (string) ($item['parentFolderId'] ?? $folderId));
        }

        return $needsFetch;
    }

    private function attachFolderLabel(Account $account, string $graphId, string $folderId): void
    {
        $message = $this->messageRepository->findOneBy(['graphId' => $graphId]);

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
        $message = $this->messageRepository->findOneBy(['graphId' => $graphId]);

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
    private function dispatchBatches(Account $account, array $graphIds): void
    {
        if (count($graphIds) === 0) {
            $this->logger->info('GraphApiSyncer: nothing new to fetch', [
                'accountId' => $account->id,
            ]);

            return;
        }

        $chunks = array_chunk($graphIds, self::BATCH_SIZE);

        foreach ($chunks as $chunk) {
            $this->bus->dispatch(new SyncGraphMessageBatchMessage(
                (int) $account->id,
                array_values($chunk),
            ));
        }

        $this->logger->info('GraphApiSyncer: dispatched message batches', [
            'accountId' => $account->id,
            'messages'  => count($graphIds),
            'batches'   => count($chunks),
        ]);
    }
}
