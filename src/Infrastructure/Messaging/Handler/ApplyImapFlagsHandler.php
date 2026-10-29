<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Infrastructure\ImapClient\Exception\ImapAuthenticationException;
use App\Infrastructure\ImapClient\Exception\ImapCommandException;
use App\Infrastructure\ImapClient\Exception\ImapConnectionException;
use App\Infrastructure\ImapClient\ImapFolder;
use App\Infrastructure\ImapClient\ImapSession;
use App\Infrastructure\Messaging\Message\ApplyImapFlagsMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\ImapConnector;
use App\Service\Imap\ImapFolderProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Outgoing IMAP state sync.
 *
 * Per-message and per-mailbox failures are logged and swallowed: a UID that
 * has already moved, or a folder that no longer exists, is about one message
 * and must not cost the other forty-nine in the batch.
 *
 * A failure to *reach the server* is not that, and used to be treated as
 * though it were. Nothing was applied, the user's archive or flag existed only
 * in the local database, and the comment here said incoming sync would
 * reconcile it — which is not true of an outgoing change. Sync reads the
 * server's state into plMail, so a change that never arrived has nothing to
 * reconcile *from*: the next pass reads the unchanged mailbox and overwrites
 * the local value. The mutation is reverted, not retried.
 *
 * So a connection failure now propagates and Messenger redelivers. This is a
 * product whose server is frequently a NAS that is asleep or rebooting, which
 * makes "could not connect" the single most likely failure here and the least
 * acceptable one to discard. Redelivery re-applies the whole envelope, which
 * is safe: setting a flag that is already set is a no-op, and a move whose UID
 * has already left the source folder is skipped.
 *
 * An authentication failure is deliberately NOT retried. It is a credential
 * problem rather than a transient one, and hammering an IMAP server with five
 * more rejected logins is how an account ends up locked out or the host
 * banned by fail2ban — a worse outcome than the lost flag.
 *
 * ## Where the UID comes from, and where it goes
 *
 * The source UID is read off the envelope, not off the row. It has to be: a
 * row being moved no longer carries a UID at all, because the UID it used to
 * carry belonged to the folder it is leaving (Message::relocateTo()). The
 * envelope captured the address at the moment the user acted, which is the
 * only moment it was true. Envelopes queued before that change carry no UIDs
 * and fall back to the row, exactly as they were queued to behave.
 *
 * A successful move ends by writing the destination UID back onto the row, so
 * the row is addressable again immediately rather than at the next sync of the
 * destination. The UID is the server's own, from COPYUID (RFC 4315), which
 * MOVE and COPY answer with on every server with UIDPLUS. Where a server does
 * not say, the destination is searched for the Message-ID and a copy is only
 * adopted once its Message-ID is seen to match: a row pointing at somebody
 * else's mail is worse than one with no address, which costs a sync and
 * nothing else.
 *
 * ## A UID that is not there any more
 *
 * This used to warn and stop, once per attempt, forever — the warning the
 * duplicate-trash report arrived with. It is not an error condition: it is the
 * ordinary result of the message having already moved, by our own earlier
 * attempt or by another client. Warning about it left the row still claiming
 * an address the server had disowned, which is the state that makes the next
 * sync insert a duplicate.
 *
 * So the UID is re-resolved instead. For a move, the destination is searched
 * for our Message-ID: finding it means the move already happened and the row
 * takes the address it found. Failing that, the row is left *unlocated* rather
 * than stale — a row with no UID is the one shape SentCopyReconciler::claim()
 * can reconcile onto the real copy when sync meets it, and a row with a wrong
 * UID is the one shape that guarantees it cannot.
 *
 * Actions:
 *   flag/unflag/seen/unseen — flag mutations in place
 *   archive/trash           — move, destination resolved here from labels/folders
 *   move                    — move to the explicit destinationPath computed by
 *                             the LabelChangePropagator (custom location-label
 *                             replacement)
 *   delete                  — flag \Deleted and UID EXPUNGE that one UID (UIDPLUS
 *                             only; see deleteOne())
 */
#[AsMessageHandler]
final class ApplyImapFlagsHandler
{
    // 'restore' is a move like the other two, and it is spelled out here
    // rather than folded into 'move' because the destination is resolved from
    // the account's special-use folders below rather than named by the caller
    // — the same reason 'archive' and 'trash' are their own actions.
    private const array MOVE_ACTIONS = ['archive', 'trash', 'restore', 'move'];

    /** The flag each in-place action sets or clears. */
    private const array FLAG_ACTIONS = [
        'flag'   => ['+', '\\Flagged'],
        'unflag' => ['-', '\\Flagged'],
        'seen'   => ['+', '\\Seen'],
        'unseen' => ['-', '\\Seen'],
    ];

    public function __construct(
        private readonly MessageRepository      $messageRepository,
        private readonly MailboxRepository      $mailboxRepository,
        private readonly LoggerInterface        $logger,
        private readonly ImapConnector          $connector,
        private readonly EntityManagerInterface $em,
        private readonly ImapFolderProvisioner  $folders,
        private readonly AccountRepository      $accountRepository,
    ) {}

    /**
     * What the server refused during THIS run, for the account it belonged to.
     *
     * An instance property because the refusals happen three levels down and
     * threading a counter through would be more invasive than the record is
     * worth. Reset at the top of every invocation: a Messenger handler is a
     * shared service, and a count carried over from the last message would
     * report a fault that has already been dealt with.
     *
     * @var array<int|string, string> accountId => the first refusal, in the server's own words
     */
    private array $refusedThisRun = [];

    public function __invoke(ApplyImapFlagsMessage $message): void
    {
        $this->refusedThisRun = [];

        $addresses = $message->addressesByMailbox();
        $ids       = [];

        foreach ($addresses as $pairs) {
            foreach ($pairs as [$messageId]) {
                $ids[$messageId] = true;
            }
        }

        $messages = [];

        foreach ($this->messageRepository->findBy(['id' => array_keys($ids)]) as $found) {
            $messages[(int) $found->id] = $found;
        }

        if (count($messages) === 0) {
            $this->logger->warning('ApplyImapFlagsHandler: no messages found', [
                'ids'    => array_keys($ids),
                'action' => $message->action,
            ]);

            return;
        }

        // Folder by folder, each copy with the UID it has there: one per
        // message for a move, every copy for a flag (#415). A UID of null is
        // an envelope from before UIDs travelled; see processMailbox().
        /** @var array<int, array<int, list<array{Message, int|null}>>> $byAccount  accountId → sourceMailboxId → [message, UID] */
        $byAccount = [];

        foreach ($addresses as $sourceMailboxId => $pairs) {
            $sourceMailbox = $this->mailboxRepository->find($sourceMailboxId);

            if (null === $sourceMailbox) {
                $this->logger->warning('ApplyImapFlagsHandler: source mailbox not found', [
                    'mailboxId' => $sourceMailboxId,
                ]);
                continue;
            }

            foreach ($pairs as [$messageId, $uid]) {
                $msg = $messages[$messageId] ?? null;

                if (null !== $msg) {
                    $byAccount[$sourceMailbox->account->id][$sourceMailboxId][] = [$msg, 0 === $uid ? null : $uid];
                }
            }
        }

        foreach ($byAccount as $accountId => $byMailbox) {
            $firstMailboxId = array_key_first($byMailbox);
            $account        = $this->mailboxRepository->find($firstMailboxId)->account;

            try {
                $session = $this->connector->session($account);

                try {
                    $this->processAccount($session, $account, $byMailbox, $message);
                } finally {
                    $session->logout();
                }
            } catch (ImapConnectionException $e) {
                // The one failure worth redelivering for. See the class
                // docblock: nothing was applied, and nothing else will ever
                // come looking for it.
                $this->logger->warning('ApplyImapFlagsHandler: server unreachable, will retry', [
                    'accountId' => $accountId,
                    'action'    => $message->action,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);

                throw $e;
            } catch (Throwable $e) {
                // A refused login lands here, deliberately: see the class
                // docblock on why it is not retried.
                $this->logger->error(
                    $e instanceof ImapAuthenticationException ? 'ApplyImapFlagsHandler: login refused' : 'ApplyImapFlagsHandler: IMAP error',
                    [
                        'accountId' => $accountId,
                        'action'    => $message->action,
                        'error'     => $e->getMessage(),
                        'exception' => $e,
                    ],
                );

                $this->refusedThisRun[$accountId] ??= $e->getMessage();
            }

            $this->settle((int) $accountId);
        }

        // Whatever the pass learned about where these messages actually are.
        $this->em->flush();
    }

    /**
     * @param array<int, list<array{Message, int|null}>> $byMailbox  sourceMailboxId → [message, UID]
     */
    private function processAccount(
        ImapSession           $session,
        Account               $account,
        array                 $byMailbox,
        ApplyImapFlagsMessage $envelope,
    ): void {
        $action          = $envelope->action;
        $destinationPath = $envelope->destinationPath;

        if (true === in_array($action, ['archive', 'trash', 'restore'], true)) {
            $destinationPath = $this->resolveDestinationPath($session, $account, $action);
        }

        // An explicit move names a folder plMail has a row for, which is not
        // the same as one the server still has: another client can delete a
        // folder at any time, and moving into one that is not there fails per
        // message with nothing that says why. Making it is cheaper than losing
        // the move, and it is the same folder the row already describes.
        if ('move' === $action && null !== $destinationPath) {
            $destinationPath = $this->ensureDestinationExists($session, $account, $destinationPath);
        }

        $needsDestination = in_array($action, self::MOVE_ACTIONS, true);

        if (true === $needsDestination && null === $destinationPath) {
            $this->logger->warning('ApplyImapFlagsHandler: destination not resolvable', [
                'accountId' => $account->id,
                'action'    => $action,
            ]);

            return;
        }

        foreach ($byMailbox as $sourceMailboxId => $messages) {
            $sourceMailbox = $this->mailboxRepository->find($sourceMailboxId);

            if (null === $sourceMailbox) {
                continue;
            }

            try {
                $this->processMailbox($session, $sourceMailbox, $messages, $envelope, $destinationPath);
            } catch (ImapConnectionException $e) {
                // Not about this folder: every later one would fail the same
                // way. Out to the account level, which redelivers.
                throw $e;
            } catch (Throwable $e) {
                $this->logger->error('ApplyImapFlagsHandler: mailbox error', [
                    'mailboxId' => $sourceMailboxId,
                    'action'    => $action,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * @param list<array{Message, int|null}> $messages each with its UID here
     */
    private function processMailbox(
        ImapSession           $session,
        Mailbox               $sourceMailbox,
        array                 $messages,
        ApplyImapFlagsMessage $envelope,
        ?string               $destinationPath,
    ): void {
        if (true === in_array($envelope->action, self::MOVE_ACTIONS, true) && $sourceMailbox->fullPath === $destinationPath) {
            $this->stayInPlace($sourceMailbox, $messages, $envelope);

            return;
        }

        $path = $sourceMailbox->serverPath();

        try {
            $session->select($path);
        } catch (ImapCommandException $refused) {
            $this->logger->warning('ApplyImapFlagsHandler: source folder not found on server', [
                'mailbox' => $path,
                'error'   => $refused->getMessage(),
            ]);

            return;
        }

        foreach ($messages as [$msg, $uid]) {
            // Envelope first: the row's own UID is cleared the moment a move is
            // queued, so for every move this is the only surviving copy of the
            // source address. The fallback carries envelopes queued before that.
            $uid ??= $msg->imapUid;

            if (null === $uid) {
                continue;
            }

            try {
                $this->applyToMessage($session, $sourceMailbox, $msg, $uid, $envelope->action, $destinationPath);
            } catch (ImapConnectionException $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->logger->error('ApplyImapFlagsHandler: per-message error', [
                    'messageId' => $msg->id,
                    'uid'       => $uid,
                    'action'    => $envelope->action,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);

                // The silent-divergence case, and the reason this is recorded
                // at all: the flag is already set HERE and the server refused
                // it, so plMail and the mailbox now disagree and the next sync
                // decides in the server's favour. Nothing said so.
                $accountId = $msg->account->id;

                if (null !== $accountId) {
                    $this->refusedThisRun[(int) $accountId] ??= $e->getMessage();
                }
            }
        }
    }

    /**
     * A move into the folder the messages are already in: nothing to send.
     * (Sending the MOVE anyway once failed outright, #269, and is a wasted
     * command at best.)
     *
     * A copy keeps its address while its move is queued (#415), so there is
     * nothing to give back either — except to a row from a job queued before
     * that, which was stripped of its UID when the move was queued and gets the
     * envelope's back: the message never left the folder. A row that something
     * else has re-pointed since, or that has a UID again, is left to the sync.
     *
     * @param list<array{Message, int|null}> $messages
     */
    private function stayInPlace(Mailbox $mailbox, array $messages, ApplyImapFlagsMessage $envelope): void
    {
        foreach ($messages as [$msg, $uid]) {

            if (null === $uid || null !== $msg->imapUid || $msg->mailbox?->fullPath !== $mailbox->fullPath) {
                continue;
            }

            $msg->relocateTo($msg->mailbox, $uid);
        }

        $this->logger->info('ApplyImapFlagsHandler: already in the destination, not moving', [
            'mailbox' => $mailbox->fullPath,
            'action'  => $envelope->action,
            'count'   => count($messages),
        ]);
    }

    private function applyToMessage(
        ImapSession $session,
        Mailbox     $mailbox,
        Message     $msg,
        int         $uid,
        string      $action,
        ?string     $destinationPath,
    ): void {
        $path  = $mailbox->serverPath();
        $flags = $session->uidFetch($path, (string) $uid, ['FLAGS'], readOnly: false)[$uid]['FLAGS'] ?? null;

        if (false === is_array($flags)) {
            $this->reResolveVanishedUid($session, $msg, $mailbox, $uid, $action, $destinationPath);

            return;
        }

        $isMove = in_array($action, self::MOVE_ACTIONS, true);

        // A move that a previous attempt got halfway through. A server without
        // RFC 6851 MOVE is sent COPY + STORE \Deleted + EXPUNGE instead, and a
        // connection that dies between the COPY and the EXPUNGE leaves the
        // copy in the destination and the original still here, flagged.
        // Messenger then redelivers — and moving it again would put a *second*
        // copy in the destination, which is the one way this handler could
        // manufacture duplicates on the server rather than merely in the
        // database. The flag is the evidence that the copy already exists.
        if (true === $isMove && true === self::hasFlag($flags, '\\Deleted')) {
            $this->logger->info('ApplyImapFlagsHandler: source already flagged deleted, not copying again', [
                'messageId' => $msg->id,
                'uid'       => $uid,
                'folder'    => $path,
            ]);

            $this->adoptFromDestination($session, $msg, $mailbox, $uid, $destinationPath);

            return;
        }

        if (true === $isMove) {
            $moved = $session->uidMove($path, (string) $uid, (string) $destinationPath);

            if (true === isset($moved[$uid])) {
                $this->adoptUid($msg, $mailbox, $uid, (string) $destinationPath, $moved[$uid]);
            } else {
                $this->adoptFromDestination($session, $msg, $mailbox, $uid, $destinationPath);
            }

            return;
        }

        if ('delete' === $action) {
            $this->deleteOne($session, $path, $uid);

            return;
        }

        $change = self::FLAG_ACTIONS[$action] ?? null;

        if (null === $change) {
            $this->logger->warning('ApplyImapFlagsHandler: unknown action', ['action' => $action]);

            return;
        }

        $session->uidStore($path, (string) $uid, $change[0], [$change[1]]);

        // What this copy now says is our own doing, and the next listing must
        // not read it back as somebody's change — see
        // MessageLocation::$serverFlags (#415).
        $copy = $msg->locationAt($mailbox, $uid);

        if (null !== $copy) {
            $now = array_values(array_filter($flags, is_string(...)));

            $copy->serverFlags = MessageFlag::canonicalList('+' === $change[0]
                ? [...$now, $change[1]]
                : array_values(array_filter($now, static fn (string $flag): bool => 0 !== strcasecmp($flag, $change[1]))));
        }

        // The server has the change now, so the inbound pass may read this row
        // again. Until this line runs, Message::$flagsTouchedAt is what keeps
        // ImapFlagReconciler from reading the server's still-old answer and
        // reverting the user — see the entity property, and note that only the
        // flag actions clear it. A move is not a flag change, and 'delete' is
        // on its way to having no row at all.
        $msg->flagsTouchedAt = null;
    }

    /**
     * Delete this one message, and nothing else in the folder.
     *
     * A plain EXPUNGE removes every message in the folder that carries
     * \Deleted — including mail another client only marked for deletion and
     * may still undelete. UID EXPUNGE (RFC 4315, UIDPLUS) names the one UID. A
     * server without UIDPLUS gets the flag and no expunge: the message stays
     * until that server's own client or policy expunges it, which is the
     * server's call rather than ours.
     */
    private function deleteOne(ImapSession $session, string $path, int $uid): void
    {
        $session->uidStore($path, (string) $uid, '+', ['\\Deleted']);

        if (false === $session->connection->supports('UIDPLUS')) {
            $this->logger->info('ApplyImapFlagsHandler: no UIDPLUS, message flagged \Deleted but not expunged', [
                'uid' => $uid,
            ]);

            return;
        }

        $session->expunge($path, (string) $uid);
    }

    /**
     * The UID the envelope named is not in the source folder.
     *
     * Ordinary rather than exceptional: the message has already moved. Either
     * an earlier attempt of this very job completed and Messenger redelivered
     * it, or another client moved the mail while the job sat in the queue.
     *
     * For a move, the destination is searched and a copy found there is where
     * this one went. Otherwise the copy is left as it is (#415): it used to be
     * stripped of its UID here, which made it a row with a folder and no
     * address — the state a purge could not reach and a sweep could not see
     * (#427). The folder's next sweep is what decides about an absent UID, and
     * a copy of the email that turns up elsewhere is recognised there.
     */
    private function reResolveVanishedUid(
        ImapSession $session,
        Message     $msg,
        Mailbox     $source,
        int         $uid,
        string      $action,
        ?string     $destinationPath,
    ): void {
        $this->logger->info('ApplyImapFlagsHandler: UID has left the source folder, re-resolving', [
            'messageId' => $msg->id,
            'uid'       => $uid,
            'folder'    => $source->fullPath,
            'action'    => $action,
        ]);

        if (false === in_array($action, self::MOVE_ACTIONS, true)) {
            return;
        }

        $this->adoptFromDestination($session, $msg, $source, $uid, $destinationPath);
    }

    /**
     * Look for this message in the folder it was supposed to move to, and take
     * that address if it is there.
     *
     * Matched on the Message-ID alone — the identity that survives a move, the
     * one v0.0.23 established for the Sent copies. Never on subject or sender.
     * HEADER search matches substrings, so each candidate's Message-ID is read
     * back and compared whole before it is adopted.
     */
    private function adoptFromDestination(ImapSession $session, Message $msg, Mailbox $source, int $sourceUid, ?string $destinationPath): void
    {
        $wanted = MessageIdHelper::normalise((string) $msg->messageId);

        if ('' === $wanted || null === $destinationPath) {
            return;
        }

        try {
            $candidates = $session->uidSearchHeader($destinationPath, 'Message-ID', $wanted);

            if ([] === $candidates) {
                return;
            }

            foreach ($session->uidFetchHeader($destinationPath, ImapSession::uidSet($candidates), 'Message-ID') as $uid => $found) {
                if ($wanted === MessageIdHelper::normalise((string) $found)) {
                    $this->adoptUid($msg, $source, $sourceUid, $destinationPath, $uid);

                    return;
                }
            }
        } catch (ImapCommandException $e) {
            // Best effort by design. Failing to find it here costs one sync,
            // which will reconcile the row by Message-ID anyway.
            $this->logger->info('ApplyImapFlagsHandler: could not re-resolve in destination', [
                'messageId'   => $msg->id,
                'destination' => $destinationPath,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Move the copy that was at ($source, $sourceUid) to the address the
     * server says it has now (#415).
     *
     * The copy kept its real address until this moment; nothing re-pointed it
     * when the move was queued. A copy that is no longer where the job found it
     * has been dealt with by something else since — the sync is better placed
     * than this handler to say where it is. So is a destination plMail has no
     * row for yet: the folder's first sync recognises the copy.
     *
     * Jobs queued before #415 find their row re-pointed at the destination
     * already, with no UID, and get one the way they always did.
     */
    private function adoptUid(Message $msg, Mailbox $source, int $sourceUid, string $destinationPath, int $uid): void
    {
        if (null === $msg->imapUid && $msg->mailbox?->fullPath === $destinationPath) {
            $msg->relocateTo($msg->mailbox, $uid);

            return;
        }

        $copy        = $msg->locationAt($source, $sourceUid);
        $destination = $this->mailboxRepository->findOneBy(['account' => $source->account, 'fullPath' => $destinationPath]);

        if (null === $copy || null === $destination) {
            return;
        }

        $msg->moveLocation($copy, $destination, $uid);
    }

    /**
     * @param list<mixed> $flags as the server sent them
     */
    private static function hasFlag(array $flags, string $wanted): bool
    {
        foreach ($flags as $flag) {
            if (true === is_string($flag) && 0 === strcasecmp($flag, $wanted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The destination path if the server still has it, or a freshly created
     * folder at that exact path if it does not.
     *
     * The path is used as-is rather than rebuilt, because it came from a
     * Mailbox row that the server itself described on an earlier LIST — its
     * namespace and separator are already whatever this server uses, which is
     * the thing ImapFolderProvisioner otherwise has to work out.
     */
    private function ensureDestinationExists(ImapSession $session, Account $account, string $destinationPath): ?string
    {
        foreach ($session->folders() as $folder) {
            if ($destinationPath === $folder->path) {
                return $destinationPath;
            }
        }

        return $this->folders->ensureExactPath($account, $session, $destinationPath);
    }

    /**
     * Where an archive or a trash physically goes on this server.
     *
     * The last step is the new one. This used to end by returning null, and the
     * caller answered that with "destination not resolvable" and did nothing —
     * so on an account whose server has no Archive folder, archiving left the
     * message out of the inbox locally and untouched remotely, and the next
     * sync put it back. Gmail creates a label when it needs one and Graph
     * creates a folder; IMAP now creates a folder too. See
     * ImapFolderProvisioner for how the namespace is worked out, which is the
     * part that cannot be guessed.
     */
    private function resolveDestinationPath(ImapSession $session, Account $account, string $action): ?string
    {
        $specialUse = match ($action) {
            'archive' => MailboxSpecialUse::ARCHIVE,
            'restore' => MailboxSpecialUse::INBOX,
            default   => MailboxSpecialUse::TRASH,
        };

        $mailbox = $this->mailboxRepository->findOneBy([
            'account'    => $account,
            'specialUse' => $specialUse,
        ]);

        if (null !== $mailbox) {
            return $mailbox->fullPath;
        }

        $nameMap = [
            MailboxSpecialUse::TRASH->value   => ['Trash', 'Deleted', 'Deleted Items', 'Deleted Messages'],
            MailboxSpecialUse::ARCHIVE->value => ['Archive', 'Archives'],
        ];

        $candidates = $nameMap[$specialUse->value] ?? [];

        if ([] !== $candidates) {
            $byName = [];

            // The first folder of each name, as the server lists them.
            foreach ($session->folders() as $folder) {
                $byName[$folder->name()] ??= $folder;
            }

            foreach ($candidates as $candidate) {
                $folder = $byName[$candidate] ?? null;

                if ($folder instanceof ImapFolder) {
                    return $folder->path;
                }
            }
        }

        return $this->folders->ensureSpecialUse($account, $session, $specialUse);
    }

    /**
     * Write down what the server refused for this account, or clear it.
     *
     * The same shape the Gmail and Graph handlers use, and the same care about
     * what is being claimed: this records that a change could not be pushed,
     * never that it is permanent. An IMAP `NO` can be a mailbox that is
     * genuinely read-only or a NAS that had not finished waking up, and the
     * next run that succeeds clears the record either way — so a temporary
     * cause clears itself and nobody is told to go fix a server that is fine.
     */
    private function settle(int $accountId): void
    {
        $account = $this->accountRepository->find($accountId);

        if (null === $account) {
            return;
        }

        $refusal = $this->refusedThisRun[$accountId] ?? null;
        $stored  = null !== $refusal ? mb_substr($refusal, 0, 500) : null;

        if ($stored === $account->exportRefusedReason) {
            return;
        }

        $account->exportRefusedReason = $stored;
        $this->em->flush();
    }
}
