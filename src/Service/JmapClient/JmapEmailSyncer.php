<?php

declare(strict_types=1);

namespace App\Service\JmapClient;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\DTO\Mail\RemoteFlagState;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Exception\JmapApiException;
use App\Domain\Exception\JmapResyncRequiredException;
use App\Domain\Helper\MessageIdHelper;
use App\Domain\Helper\MessageParser;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Infrastructure\JmapClient\JmapCapability;
use App\Infrastructure\JmapClient\JmapClient;
use App\Infrastructure\Messaging\Message\SyncJmapMessageBatchMessage;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\HarvestContactsService;
use App\Service\Imap\MessageSyncer;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Mail\MessageEraser;
use App\Service\Mail\PostIngestPipeline;
use App\Service\Mail\SyncNotifier;
use App\Service\Mail\SyncOrigin;
use App\Service\Mail\ThreadStatusUpdater;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * A JMAP account's Emails: what is new, what changed, what went.
 *
 * ## One road in, shared with IMAP and POP3
 *
 * An Email is stored from its raw RFC 822 bytes, not from JMAP's parsed body
 * properties. The server hands them over as the Email's blob, and the bytes go
 * through MessageParser and MessageSyncer::buildFromParsed() — the parser and
 * builder POP3 and IMAP use — so the same message makes the same row whichever
 * protocol delivered it: the same body sanitising, attachment handling, crypto
 * detection and header normalisation, and the original source stored for the
 * raw view and for signature checks. What JMAP adds on top is only what the
 * bytes cannot say: the Email id, its thread id, which mailboxes it is in and
 * its keywords. From there the row goes through PostIngestPipeline like every
 * other provider's.
 *
 * ## Changes
 *
 * Email/changes from the state the account holds, as Gmail reads its history
 * feed: created ids are fetched in batches on the ingest queue; updated ids
 * are re-read for their mailboxes and keywords and applied in place; destroyed
 * ids are erased. An id reported as updated that is not stored here is fetched
 * as new — it was created before the state this sync started from, during the
 * first import, and the import may not have reached it.
 *
 * `cannotCalculateChanges` means the state has aged out. The state is dropped
 * and the import starts again: it lists every Email, fetches the ones that are
 * missing and re-reads the mailboxes and keywords of the ones that are not,
 * which is what a fresh state needs to be trusted. Deletions inside the lost
 * window are not found by that listing — nothing names an Email that no longer
 * exists — and stay here until the user removes them; the same is true of
 * Gmail's expired-history recovery, and the count recorded by
 * Account::recordFullResync() is what tells an administrator it happens.
 */
final readonly class JmapEmailSyncer
{
    /** The type name state is recorded under. See Account::jmapState(). */
    public const string STATE = 'Email';

    /** Emails per ingest batch, and per page of an import. */
    public const int BATCH_SIZE = 50;

    /** How many changes one Email/changes call may report. */
    private const int MAX_CHANGES = 500;

    /**
     * How many Email/changes calls one sync makes before it stops and lets
     * the next sync carry on from the state it reached. A mailbox that has
     * changed this much since its last sync is better served by several short
     * syncs than by one that holds the ingest queue.
     */
    private const int MAX_ROUNDS = 20;

    /** What is read of an Email: everything but its content. */
    private const array PROPERTIES = ['id', 'blobId', 'threadId', 'mailboxIds', 'keywords', 'receivedAt', 'size'];

    /** What is re-read of an Email that is already stored. */
    private const array CHANGE_PROPERTIES = ['id', 'mailboxIds', 'keywords'];

    public function __construct(
        private JmapClient              $jmap,
        private JmapMailboxSyncer       $mailboxes,
        private JmapLabelResolver       $labels,
        private JmapLabelPolicy         $policy,
        private MessageSyncer           $builder,
        private MessageRepository       $messages,
        private AccountRepository       $accounts,
        private PostIngestPipeline      $postIngest,
        private ThreadStatusUpdater     $status,
        private ThreadLabelSynchronizer $threadLabels,
        private StateManager            $stateManager,
        private MessageEraser           $eraser,
        private HarvestContactsService  $harvest,
        private SyncNotifier            $notifier,
        private SyncOrigin              $origin,
        private MessageBusInterface     $bus,
        private EntityManagerInterface  $em,
        private ManagerRegistry         $registry,
        private LoggerInterface         $logger,
    ) {
    }

    /**
     * The state the account's Emails are at now, recorded as where changes
     * are read from.
     *
     * Taken before an import starts, so everything that happens while the
     * history is being read — mail arriving, mail read on a phone — is in the
     * changes the first live sync reads. Email/get with no ids costs nothing
     * and answers with the state.
     *
     * Not flushed: the caller writes the import plan in the same flush, so an
     * account never holds a baseline with no import behind it — which would
     * read changes from now on and never fetch its history.
     *
     * @throws JmapApiException
     */
    public function baseline(Account $account): void
    {
        $accountId = $this->mailboxes->mailAccountId($account);

        $response = $this->jmap->call($account, [JmapCapability::MAIL], [
            ['Email/get', ['accountId' => $accountId, 'ids' => [], 'properties' => ['id']], 'e'],
        ])->get('e', 'Email/get');

        $account->rememberJmapState(self::STATE, is_string($response['state'] ?? null) ? $response['state'] : null);
    }

    /**
     * Read and act on everything that changed since the recorded state.
     *
     * @return bool false when the state could not be used and the account
     *              needs its import run again — see the class docblock
     *
     * @throws JmapApiException
     */
    public function syncChanges(Account $account): bool
    {
        $state = $account->jmapState(self::STATE);

        if (null === $state) {
            return false;
        }

        $accountId = $this->mailboxes->mailAccountId($account);
        $created   = [];
        $updated   = [];
        $destroyed = [];

        for ($round = 0; $round < self::MAX_ROUNDS; ++$round) {
            try {
                $changes = $this->jmap->call($account, [JmapCapability::MAIL], [
                    ['Email/changes', [
                        'accountId'  => $accountId,
                        'sinceState' => $state,
                        'maxChanges' => self::MAX_CHANGES,
                    ], 'c'],
                ])->get('c', 'Email/changes');
            } catch (JmapResyncRequiredException) {
                $this->logger->warning('JmapEmailSyncer: the server cannot say what changed, importing again', [
                    'accountId' => $account->id,
                ]);

                $account->rememberJmapState(self::STATE, null);
                $account->recordFullResync();
                $this->em->flush();

                return false;
            }

            foreach (self::ids($changes['created'] ?? []) as $id) {
                $created[$id] = true;
            }

            foreach (self::ids($changes['updated'] ?? []) as $id) {
                $updated[$id] = true;
            }

            foreach (self::ids($changes['destroyed'] ?? []) as $id) {
                $destroyed[$id] = true;
            }

            $state = is_string($changes['newState'] ?? null) ? $changes['newState'] : $state;

            if (true !== ($changes['hasMoreChanges'] ?? false)) {
                break;
            }
        }

        // Destroyed wins: an Email created and destroyed inside the window is
        // not fetched at all, since the server has nothing left to hand over.
        // By key, for the reason GmailApiSyncer::syncIncremental() gives.
        foreach (array_keys($destroyed) as $id) {
            unset($created[$id], $updated[$id]);
        }

        $this->erase($account, array_map(strval(...), array_keys($destroyed)));

        $missing = $this->refresh($account, $accountId, array_map(strval(...), array_keys($updated)));

        foreach ($missing as $id) {
            $created[$id] = true;
        }

        $this->dispatch($account, $this->unstored($account, array_map(strval(...), array_keys($created))));

        // Last, so a sync that failed part way reads the same window again.
        // Every step above is safe to repeat: erasing an erased id finds
        // nothing, a re-read sets what is already set, and a batch skips what
        // is already stored.
        $account->rememberJmapState(self::STATE, $state);
        $this->em->flush();

        return true;
    }

    /**
     * Store the Emails that are new and bring the stored ones up to date.
     *
     * What a batch from the queue does, and what an import page does with the
     * ids it listed. The stored ones are only re-read for their mailboxes and
     * keywords; the new ones are fetched whole.
     *
     * @param list<string> $emailIds
     *
     * @return int how many were stored as new rows
     *
     * @throws JmapApiException
     */
    public function ingest(Account $account, array $emailIds): int
    {
        $emailIds = array_values(array_unique(array_map(strval(...), $emailIds)));

        if ([] === $emailIds) {
            return 0;
        }

        $accountId = $this->mailboxes->mailAccountId($account);
        $ownId     = (int) $account->id;
        $known     = array_flip($this->messages->findStoredJmapIdsForAccount($account, $emailIds));
        $fresh     = array_values(array_filter($emailIds, static fn (string $id): bool => false === isset($known[$id])));

        if ([] !== $known) {
            $this->refresh($account, $accountId, array_map(strval(...), array_keys($known)));
        }

        if ([] === $fresh) {
            return 0;
        }

        $storedIds = [];
        $raws      = [];

        foreach ($this->get($account, $accountId, $fresh, self::PROPERTIES) as $email) {
            $account = $this->accounts->find($ownId) ?? $account;
            $stored  = $this->store($account, $accountId, $email);

            if (null !== $stored) {
                $storedIds[] = $stored[0];
                $raws[]      = $stored[1];
            }
        }

        if ([] === $storedIds) {
            return 0;
        }

        $account = $this->accounts->find($ownId);

        if (null === $account) {
            return 0;
        }

        // Found again by id: a reset part way through the batch detached the
        // rows written before it, and the pipeline writes onto them. The same
        // step Pop3AccountSyncer::fetchBatch() takes, for the same reason.
        $ingested = [];

        foreach ($storedIds as $index => $id) {
            $message = $this->messages->find($id);

            if (null !== $message) {
                $ingested[] = new IngestedMessage($message, $account, $raws[$index]);
            }
        }

        $result = $this->postIngest->run($account, $ingested);
        $this->em->flush();

        $user = $account->usr;

        if (null !== $user) {
            $this->harvest->harvestMessages($user, $result->messages, (string) $account->email);
        }

        $this->notifier->publishAccountSynced($account);

        return count($storedIds);
    }

    /**
     * Put a stored row in line with what the server says about its Email.
     *
     * The JMAP counterpart of SyncGmailMessageBatchHandler::enrichExisting():
     * labels from the mailboxes the Email is in, applied authoritatively for
     * the labels this server owns and not at all for plMail's own; read and
     * starred through ThreadStatusUpdater, which declines to revert a local
     * change the server has not confirmed yet; and the JMAP change recorded
     * for plMail's own clients when the labels moved.
     *
     * @param array<string, mixed> $email at least `mailboxIds` and `keywords`
     */
    public function apply(Message $row, array $email, Account $account): void
    {
        $mailboxIds = is_array($email['mailboxIds'] ?? null) ? $email['mailboxIds'] : [];
        $keywords   = is_array($email['keywords'] ?? null) ? $email['keywords'] : [];
        $resolved   = $this->labels->resolve($mailboxIds, $account);
        $before     = self::labelIds($row);

        $keep = [];

        foreach ($resolved as $label) {
            $keep[(int) $label->id] = true;
        }

        // Off first, so a label moving between two of this message's
        // mailboxes in one change is not added and then removed by its own
        // pass. The same order GmailMessageBuilder::applyTranslatedLabels()
        // keeps.
        foreach ($this->policy->providerLabels($row, $account) as $current) {
            if (false === isset($keep[(int) $current->id])) {
                $row->removeLabel($current);
            }
        }

        foreach ($resolved as $label) {
            $row->addLabel($label);
        }

        // A stored draft the server now files in Sent without $draft was sent
        // — elsewhere, or by this account's own submission. Only Sent says so,
        // as only Sent Items does on Graph: a draft that merely lost $draft is
        // not known to have gone anywhere. Ahead of the flag pass below, which
        // reads the row as it now is.
        if (false === JmapKeywords::has($keywords, MessageFlag::DRAFT) && true === self::inRole($resolved, LabelRole::Sent)) {
            $row->settleAsSent(new DateTimeImmutable());
        }

        $this->status->applyRemoteFlags([
            new RemoteFlagState(
                $row,
                JmapKeywords::has($keywords, MessageFlag::SEEN),
                JmapKeywords::has($keywords, MessageFlag::FLAGGED),
                JmapKeywords::toFlags($keywords),
            ),
        ]);

        if ($before === self::labelIds($row)) {
            return;
        }

        $this->stateManager->recordUpdated((int) $account->id, JmapObjectType::Email, (string) $row->id);

        $thread = $row->thread;

        if (null !== $thread) {
            // Re-derived, not accumulated — see ThreadLabelSynchronizer. A
            // conversation whose last message left the Inbox on the server has
            // to leave the Inbox here too.
            $this->threadLabels->sync($thread);
            $this->stateManager->recordThreadsTouched((int) $account->id, [(int) $thread->id]);
        }
    }

    /**
     * Re-read the mailboxes and keywords of Emails we hold, and apply them.
     *
     * @param list<string> $emailIds
     *
     * @return list<string> the ids that are not stored here after all
     */
    private function refresh(Account $account, string $accountId, array $emailIds): array
    {
        if ([] === $emailIds) {
            return [];
        }

        $rows = [];

        foreach ($this->messages->findByJmapIdsForAccount($account, $emailIds) as $row) {
            $rows[(string) $row->jmapId] = $row;
        }

        $missing = array_values(array_filter($emailIds, static fn (string $id): bool => false === isset($rows[$id])));

        if ([] === $rows) {
            return $missing;
        }

        foreach ($this->get($account, $accountId, array_map(strval(...), array_keys($rows)), self::CHANGE_PROPERTIES) as $email) {
            $row = $rows[(string) ($email['id'] ?? '')] ?? null;

            if (null !== $row) {
                $this->apply($row, $email, $account);
            }
        }

        $this->em->flush();

        return $missing;
    }

    /**
     * Fetch, parse and store one Email, or recognise it as one we hold.
     *
     * Committed one at a time, as POP3 commits: a message the database refuses
     * is that message's problem, and the rows before it are already safe.
     *
     * @param array<string, mixed> $email
     *
     * @return array{0: int, 1: string}|null the new row's id and its raw bytes,
     *                                       or null when nothing new was stored
     */
    private function store(Account $account, string $accountId, array $email): ?array
    {
        $emailId = (string) ($email['id'] ?? '');
        $blobId  = (string) ($email['blobId'] ?? '');

        if ('' === $emailId || '' === $blobId) {
            return null;
        }

        try {
            $raw    = $this->jmap->download($account, $accountId, $blobId, 'message/rfc822', 'message.eml');
            $parsed = MessageParser::fromString($raw, MessageParser::config());

            // Already here under another name: a copy this account sent, or
            // a row the IMAP sync stored before the account switched to JMAP.
            // Keyed on the RFC Message-ID, as the Graph batch is, and given
            // the JMAP id so the next change finds it. Only a row with no JMAP
            // id yet: one that has one is a different Email on this server —
            // the copy in Sent and the copy that came back to the sender are
            // two Emails with one Message-ID — and stays its own row.
            $rfcId = MessageIdHelper::normalise((string) $parsed->getMessageId());

            if ('' !== $rfcId) {
                $existing = $this->messages->findOneForAccountByMessageId($account, $rfcId);

                if (null !== $existing && null === $existing->jmapId) {
                    $existing->jmapId = $emailId;
                    $existing->providerThreadKey ??= is_string($email['threadId'] ?? null) ? $email['threadId'] : null;

                    $this->apply($existing, $email, $account);
                    $this->em->flush();

                    return null;
                }
            }

            $message = $this->builder->buildFromParsed($parsed, $account, null);
            $this->describe($message, $email, $account);

            $this->em->persist($message);

            foreach ($message->messageParts as $part) {
                $this->em->persist($part);
            }

            $this->em->flush();

            return [(int) $message->id, $raw];
        } catch (JmapApiException $refused) {
            // The server would not hand over this one blob. Anything wider —
            // the session gone, the server throttling — is the batch's
            // problem, and it is in the exception the next call throws.
            $this->logger->warning('JmapEmailSyncer: an Email could not be fetched', [
                'accountId' => $account->id,
                'emailId'   => $emailId,
                'error'     => $refused->getMessage(),
            ]);

            return null;
        } catch (Throwable $failure) {
            // A refused write closes the manager; reopen it so the rest of the
            // batch is not blamed for it.
            if (false === $this->em->isOpen()) {
                $this->registry->resetManager();
            }

            $this->logger->error('JmapEmailSyncer: an Email could not be stored', [
                'accountId' => $account->id,
                'emailId'   => $emailId,
                'error'     => $failure->getMessage(),
                'exception' => $failure,
            ]);

            return null;
        }
    }

    /**
     * What a new row learns from the Email rather than from its bytes.
     *
     * The arrival time is JMAP's `receivedAt`, which is the server's
     * INTERNALDATE: when the message reached this mailbox, which is what every
     * other provider's receivedAt means too. The headers' Date is when the
     * sender's client claimed to send it. A draft has not been sent, whatever
     * either says — see Message::$sentAt.
     *
     * @param array<string, mixed> $email
     */
    private function describe(Message $message, array $email, Account $account): void
    {
        $keywords = is_array($email['keywords'] ?? null) ? $email['keywords'] : [];
        $now      = new DateTimeImmutable();

        $message->imapUid           = null;
        $message->jmapId            = (string) $email['id'];
        $message->providerThreadKey = is_string($email['threadId'] ?? null) ? $email['threadId'] : null;
        $message->flags             = JmapKeywords::toFlags($keywords);
        $message->seenAt            = true === JmapKeywords::has($keywords, MessageFlag::SEEN) ? $now : null;
        $message->starredAt         = true === JmapKeywords::has($keywords, MessageFlag::FLAGGED) ? $now : null;

        $receivedAt = $email['receivedAt'] ?? null;

        if (true === is_string($receivedAt) && '' !== $receivedAt) {
            try {
                $message->receivedAt = new DateTimeImmutable($receivedAt);
            } catch (\Exception) {
                // Kept from the headers, which is what the builder chose.
            }
        }

        $message->sentAt = true === $message->isDraft() ? null : $message->receivedAt;

        foreach ($this->labels->resolve(is_array($email['mailboxIds'] ?? null) ? $email['mailboxIds'] : [], $account) as $label) {
            $message->addLabel($label);
        }
    }

    /**
     * Remove what the server destroyed.
     *
     * @param list<string> $emailIds
     */
    private function erase(Account $account, array $emailIds): void
    {
        if ([] === $emailIds) {
            return;
        }

        $rows = $this->messages->findByJmapIdsForAccount($account, $emailIds);

        if ([] === $rows) {
            return;
        }

        $erased = $this->eraser->eraseAll($rows);

        $this->logger->info('JmapEmailSyncer: Emails destroyed on the server were removed here', [
            'accountId' => $account->id,
            'reported'  => count($emailIds),
            'erased'    => $erased,
        ]);
    }

    /**
     * Queue the new ids for fetching, a batch at a time, on the ingest queue.
     *
     * @param list<string> $emailIds
     */
    private function dispatch(Account $account, array $emailIds): void
    {
        foreach (array_chunk($emailIds, self::BATCH_SIZE) as $batch) {
            $this->bus->dispatch(new SyncJmapMessageBatchMessage((int) $account->id, $batch, $this->origin->current()));
        }
    }

    /**
     * @param list<string> $emailIds
     *
     * @return list<string>
     */
    private function unstored(Account $account, array $emailIds): array
    {
        if ([] === $emailIds) {
            return [];
        }

        $known = array_flip($this->messages->findStoredJmapIdsForAccount($account, $emailIds));

        return array_values(array_filter($emailIds, static fn (string $id): bool => false === isset($known[$id])));
    }

    /**
     * Email/get, chunked to what the server allows in one call.
     *
     * @param list<string> $emailIds
     * @param list<string> $properties
     *
     * @return list<array<string, mixed>>
     *
     * @throws JmapApiException
     */
    private function get(Account $account, string $accountId, array $emailIds, array $properties): array
    {
        $limit  = max(1, $this->jmap->session($account)->coreLimit('maxObjectsInGet'));
        $emails = [];

        foreach (array_chunk($emailIds, $limit) as $chunk) {
            $response = $this->jmap->call($account, [JmapCapability::MAIL], [
                ['Email/get', ['accountId' => $accountId, 'ids' => $chunk, 'properties' => $properties], 'g'],
            ])->get('g', 'Email/get');

            foreach (is_array($response['list'] ?? null) ? $response['list'] : [] as $email) {
                if (true === is_array($email)) {
                    $emails[] = $email;
                }
            }
        }

        return $emails;
    }

    /**
     * @return list<string>
     */
    private static function ids(mixed $list): array
    {
        return array_values(array_filter(is_array($list) ? $list : [], 'is_string'));
    }

    /**
     * @return list<int>
     */
    private static function labelIds(Message $message): array
    {
        $ids = [];

        foreach ($message->labels as $label) {
            $ids[] = (int) $label->id;
        }

        sort($ids);

        return $ids;
    }

    /**
     * @param list<\App\Entity\Label\Label> $labels
     */
    private static function inRole(array $labels, LabelRole $role): bool
    {
        foreach ($labels as $label) {
            if ($role === $label->role) {
                return true;
            }
        }

        return false;
    }
}
