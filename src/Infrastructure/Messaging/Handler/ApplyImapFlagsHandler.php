<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Helper\ImapConnectionFactory;
use App\Domain\Helper\MessageIdHelper;
use App\Service\Imap\ImapFolderProvisioner;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\ApplyImapFlagsMessage;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message as ImapMessage;

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
 * destination. The write-back is checked against the Message-ID first —
 * webklex predicts the new UID from the folder's UIDNEXT, which is a
 * prediction, and adopting an unverified prediction would point the row at
 * some other message that happened to land in the meantime. Unverified, the
 * row simply stays unlocated, which costs a sync and nothing else.
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
 *   delete                  — expunge
 */
#[AsMessageHandler]
final class ApplyImapFlagsHandler
{
    // 'restore' is a move like the other two, and it is spelled out here
    // rather than folded into 'move' because the destination is resolved from
    // the account's special-use folders below rather than named by the caller
    // — the same reason 'archive' and 'trash' are their own actions.
    private const array MOVE_ACTIONS = ['archive', 'trash', 'restore', 'move'];

    public function __construct(
        private readonly MessageRepository      $messageRepository,
        private readonly MailboxRepository      $mailboxRepository,
        private readonly LoggerInterface        $logger,
        private readonly ImapConnectionFactory  $imapConnectionFactory,
        private readonly EntityManagerInterface $em,
        private readonly ImapFolderProvisioner  $folders,
    ) {}

    public function __invoke(ApplyImapFlagsMessage $message): void
    {
        $messages = $this->messageRepository->findBy(['id' => array_keys($message->messageIds)]);

        if (count($messages) === 0) {
            $this->logger->warning('ApplyImapFlagsHandler: no messages found', [
                'ids'    => array_keys($message->messageIds),
                'action' => $message->action,
            ]);

            return;
        }

        /** @var array<int, array<int, Message[]>> $byAccount  accountId → sourceMailboxId → Message[] */
        $byAccount = [];

        foreach ($messages as $msg) {
            $sourceMailboxId = $message->messageIds[$msg->id];
            $sourceMailbox   = $this->mailboxRepository->find($sourceMailboxId);

            if (null === $sourceMailbox) {
                $this->logger->warning('ApplyImapFlagsHandler: source mailbox not found', [
                    'mailboxId' => $sourceMailboxId,
                ]);
                continue;
            }

            $accountId = $sourceMailbox->account->id;

            $byAccount[$accountId][$sourceMailboxId][] = $msg;
        }

        foreach ($byAccount as $accountId => $byMailbox) {
            $firstMailboxId = array_key_first($byMailbox);
            $account        = $this->mailboxRepository->find($firstMailboxId)->account;

            try {
                $client = $this->imapConnectionFactory->connect($account);
                $this->processAccount($client, $account, $byMailbox, $message);
                $client->disconnect();
            } catch (ConnectionFailedException | ImapServerErrorException $e) {
                // The one failure worth redelivering for. See the class
                // docblock: nothing was applied, and nothing else will ever
                // come looking for it.
                $this->logger->warning('ApplyImapFlagsHandler: server unreachable, will retry', [
                    'accountId' => $accountId,
                    'action'    => $message->action,
                    'error'     => $e->getMessage(),
                ]);

                throw $e;
            } catch (Throwable $e) {
                $this->logger->error('ApplyImapFlagsHandler: IMAP error', [
                    'accountId' => $accountId,
                    'action'    => $message->action,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        // Whatever the pass learned about where these messages actually are.
        $this->em->flush();
    }

    /**
     * @param array<int, Message[]> $byMailbox  sourceMailboxId → Message[]
     */
    private function processAccount(
        Client                $client,
        Account               $account,
        array                 $byMailbox,
        ApplyImapFlagsMessage $envelope,
    ): void {
        $action          = $envelope->action;
        $destinationPath = $envelope->destinationPath;

        if (true === in_array($action, ['archive', 'trash', 'restore'], true)) {
            $destinationPath = $this->resolveDestinationPath($client, $account, $action);
        }

        // An explicit move names a folder plMail has a row for, which is not
        // the same as one the server still has: another client can delete a
        // folder at any time, and moving into one that is not there fails per
        // message with nothing that says why. Making it is cheaper than losing
        // the move, and it is the same folder the row already describes.
        if ('move' === $action && null !== $destinationPath) {
            $destinationPath = $this->ensureDestinationExists($client, $account, $destinationPath);
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
                $this->processMailbox($client, $sourceMailbox, $messages, $envelope, $destinationPath);
            } catch (Throwable $e) {
                $this->logger->error('ApplyImapFlagsHandler: mailbox error', [
                    'mailboxId' => $sourceMailboxId,
                    'action'    => $action,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param Message[] $messages
     */
    private function processMailbox(
        Client                $client,
        Mailbox               $sourceMailbox,
        array                 $messages,
        ApplyImapFlagsMessage $envelope,
        ?string               $destinationPath,
    ): void {
        $folder = $client->getFolder($sourceMailbox->name);

        if (null === $folder) {
            $this->logger->warning('ApplyImapFlagsHandler: source folder not found on server', [
                'mailbox' => $sourceMailbox->name,
            ]);

            return;
        }

        foreach ($messages as $msg) {
            // Envelope first: the row's own UID is cleared the moment a move is
            // queued, so for every move this is the only surviving copy of the
            // source address. The fallback carries envelopes queued before that.
            $uid = $envelope->sourceUidFor((int) $msg->id) ?? $msg->imapUid;

            if (null === $uid) {
                continue;
            }

            try {
                $this->applyToMessage($client, $folder, $msg, $uid, $envelope->action, $destinationPath);
            } catch (Throwable $e) {
                $this->logger->error('ApplyImapFlagsHandler: per-message error', [
                    'messageId' => $msg->id,
                    'uid'       => $uid,
                    'action'    => $envelope->action,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    private function applyToMessage(
        Client  $client,
        Folder  $folder,
        Message $msg,
        int     $uid,
        string  $action,
        ?string $destinationPath,
    ): void {
        $imapMessage = $folder->messages()
            ->whereUid($uid)
            ->setFetchBody(false)
            ->get()
            ->first();

        if (null === $imapMessage) {
            $this->reResolveVanishedUid($client, $msg, $uid, $folder, $action, $destinationPath);

            return;
        }

        $isMove = in_array($action, self::MOVE_ACTIONS, true);

        // A move that a previous attempt got halfway through. Servers without
        // RFC 6851 MOVE make webklex fall back to COPY + STORE \Deleted +
        // EXPUNGE, and a connection that dies between the COPY and the EXPUNGE
        // leaves the copy in the destination and the original still here,
        // flagged. Messenger then redelivers — and moving it again would put a
        // *second* copy in the destination, which is the one way this handler
        // could manufacture duplicates on the server rather than merely in the
        // database. The flag is the evidence that the copy already exists.
        if (true === $isMove && true === $this->isDeleted($imapMessage)) {
            $this->logger->info('ApplyImapFlagsHandler: source already flagged deleted, not copying again', [
                'messageId' => $msg->id,
                'uid'       => $uid,
                'folder'    => $folder->path,
            ]);

            $this->adoptFromDestination($client, $msg, $destinationPath);

            return;
        }

        if (true === $isMove) {
            $this->completeMove($msg, $imapMessage, (string) $destinationPath);

            return;
        }

        match ($action) {
            'flag'   => $imapMessage->setFlag('Flagged'),
            'unflag' => $imapMessage->unsetFlag('Flagged'),
            'seen'   => $imapMessage->setFlag('Seen'),
            'unseen' => $imapMessage->unsetFlag('Seen'),
            'delete' => $imapMessage->delete(expunge: true),
            default  => $this->logger->warning('ApplyImapFlagsHandler: unknown action', ['action' => $action]),
        };

        // The server has the change now, so the inbound pass may read this row
        // again. Until this line runs, Message::$flagsTouchedAt is what keeps
        // ImapFlagReconciler from reading the server's still-old answer and
        // reverting the user — see the entity property, and note that only the
        // flag actions clear it. A move is not a flag change, and 'delete' is
        // on its way to having no row at all.
        if (true === in_array($action, ['flag', 'unflag', 'seen', 'unseen'], true)) {
            $msg->flagsTouchedAt = null;
        }
    }

    /**
     * Move the message, then record where it landed.
     *
     * webklex hands back the message as it now exists in the destination, which
     * is the only cheap way to learn the new UID — but it finds it by
     * predicting UIDNEXT, so the answer is checked against the Message-ID
     * before the row is allowed to believe it. A wrong UID is worse than none:
     * none is reconcilable, wrong is a row pointing at somebody else's mail.
     */
    private function completeMove(Message $msg, ImapMessage $imapMessage, string $destinationPath): void
    {
        $moved = $imapMessage->move($destinationPath);

        if (null === $moved) {
            return;
        }

        $this->adopt($msg, $moved, $destinationPath);
    }

    /**
     * The UID the envelope named is not in the source folder.
     *
     * Ordinary rather than exceptional: the message has already moved. Either
     * an earlier attempt of this very job completed and Messenger redelivered
     * it, or another client moved the mail while the job sat in the queue. What
     * matters is that the row must not be left claiming the address, because a
     * stale address is precisely what makes the destination's next sync insert
     * a second row instead of recognising the first.
     */
    private function reResolveVanishedUid(
        Client  $client,
        Message $msg,
        int     $uid,
        Folder  $folder,
        string  $action,
        ?string $destinationPath,
    ): void {
        $this->logger->info('ApplyImapFlagsHandler: UID has left the source folder, re-resolving', [
            'messageId' => $msg->id,
            'uid'       => $uid,
            'folder'    => $folder->path,
            'action'    => $action,
        ]);

        // Drop the stale claim whatever else happens. A row with no UID is
        // reconcilable by Message-ID on the next sync; a row with a UID the
        // server disowns is the ghost that gets counted twice.
        if ($msg->imapUid === $uid) {
            $msg->relocateTo($msg->mailbox);
        }

        if (false === in_array($action, self::MOVE_ACTIONS, true)) {
            return;
        }

        $this->adoptFromDestination($client, $msg, $destinationPath);
    }

    /**
     * Look for this message in the folder it was supposed to move to, and take
     * that address if it is there.
     *
     * Matched on the Message-ID alone — the identity that survives a move, the
     * one v0.0.23 established for the Sent copies. Never on subject or sender.
     */
    private function adoptFromDestination(Client $client, Message $msg, ?string $destinationPath): void
    {
        $wanted = MessageIdHelper::normalise((string) $msg->messageId);

        if ('' === $wanted || null === $destinationPath) {
            return;
        }

        try {
            $destination = $client->getFolder($destinationPath);

            if (null === $destination) {
                return;
            }

            $candidates = $destination->messages()
                ->where('HEADER Message-ID', '<' . $wanted . '>')
                ->setFetchBody(false)
                ->get();

            foreach ($candidates as $candidate) {
                $this->adopt($msg, $candidate, $destinationPath);

                return;
            }
        } catch (Throwable $e) {
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
     * Give the row the address of a server copy, once that copy has proved it
     * is the same message.
     *
     * The row's own mailbox pointer has to already name the destination — the
     * caller re-pointed it before queueing the job. If it does not, something
     * else has moved this message since and the sync layer is better placed to
     * work out where it belongs than this handler is.
     */
    private function adopt(Message $msg, ImapMessage $candidate, string $destinationPath): void
    {
        $wanted = MessageIdHelper::normalise((string) $msg->messageId);
        $found  = MessageIdHelper::normalise((string) $candidate->getMessageId());

        if ('' === $wanted || $wanted !== $found) {
            return;
        }

        if ($msg->mailbox?->fullPath !== $destinationPath) {
            return;
        }

        $msg->relocateTo($msg->mailbox, $candidate->getUid());
    }

    private function isDeleted(ImapMessage $imapMessage): bool
    {
        foreach ($imapMessage->getFlags()->toArray() as $flag) {
            if ('deleted' === strtolower(ltrim((string) $flag, '\\'))) {
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
    private function ensureDestinationExists(Client $client, Account $account, string $destinationPath): ?string
    {
        try {
            if (null !== $client->getFolder($destinationPath)) {
                return $destinationPath;
            }
        } catch (Throwable) {
            // Not found, or the server would not say. Either way the create
            // below settles it, and a CREATE for a folder that does exist is
            // rejected harmlessly.
        }

        return $this->folders->ensureExactPath($account, $client, $destinationPath);
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
    private function resolveDestinationPath(Client $client, Account $account, string $action): ?string
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

        foreach ($candidates as $candidate) {
            try {
                $folder = $client->getFolder($candidate);

                if (null !== $folder) {
                    return $folder->path;
                }
            } catch (Throwable) {
                // Not found — try next.
            }
        }

        return $this->folders->ensureSpecialUse($account, $client, $specialUse);
    }
}
