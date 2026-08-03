<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\ApplyImapFlagsMessage;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Folder;

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
 * has already left the source folder is skipped with a warning.
 *
 * An authentication failure is deliberately NOT retried. It is a credential
 * problem rather than a transient one, and hammering an IMAP server with five
 * more rejected logins is how an account ends up locked out or the host
 * banned by fail2ban — a worse outcome than the lost flag.
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
    public function __construct(
        private readonly MessageRepository     $messageRepository,
        private readonly MailboxRepository     $mailboxRepository,
        private readonly LoggerInterface       $logger,
        private readonly ImapConnectionFactory $imapConnectionFactory,
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
            $sourceMailboxId = $message->messageIds[$msg->getId()];
            $sourceMailbox   = $this->mailboxRepository->find($sourceMailboxId);

            if (null === $sourceMailbox) {
                $this->logger->warning('ApplyImapFlagsHandler: source mailbox not found', [
                    'mailboxId' => $sourceMailboxId,
                ]);
                continue;
            }

            $accountId = $sourceMailbox->account->getId();

            $byAccount[$accountId][$sourceMailboxId][] = $msg;
        }

        foreach ($byAccount as $accountId => $byMailbox) {
            $firstMailboxId = array_key_first($byMailbox);
            $account        = $this->mailboxRepository->find($firstMailboxId)->account;

            try {
                $client = $this->imapConnectionFactory->connect($account);
                $this->processAccount($client, $account, $byMailbox, $message->action, $message->destinationPath);
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
    }

    /**
     * @param array<int, Message[]> $byMailbox  sourceMailboxId → Message[]
     */
    private function processAccount(
        Client  $client,
        Account $account,
        array   $byMailbox,
        string  $action,
        ?string $explicitDestinationPath,
    ): void {
        $destinationPath = $explicitDestinationPath;

        if ('archive' === $action || 'trash' === $action) {
            $destinationPath = $this->resolveDestinationPath($client, $account, $action);
        }

        $needsDestination = in_array($action, ['archive', 'trash', 'move'], true);

        if (true === $needsDestination && null === $destinationPath) {
            $this->logger->warning('ApplyImapFlagsHandler: destination not resolvable', [
                'accountId' => $account->getId(),
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
                $this->processMailbox($client, $sourceMailbox, $messages, $action, $destinationPath);
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
        Client  $client,
        Mailbox $sourceMailbox,
        array   $messages,
        string  $action,
        ?string $destinationPath,
    ): void {
        $folder = $client->getFolder($sourceMailbox->name);

        if (null === $folder) {
            $this->logger->warning('ApplyImapFlagsHandler: source folder not found on server', [
                'mailbox' => $sourceMailbox->name,
            ]);

            return;
        }

        foreach ($messages as $msg) {
            if (null === $msg->getImapUid()) {
                continue;
            }

            try {
                $this->applyToMessage($folder, $msg->getImapUid(), $action, $destinationPath);
            } catch (Throwable $e) {
                $this->logger->error('ApplyImapFlagsHandler: per-message error', [
                    'messageId' => $msg->getId(),
                    'uid'       => $msg->getImapUid(),
                    'action'    => $action,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    private function applyToMessage(
        Folder  $folder,
        int     $uid,
        string  $action,
        ?string $destinationPath,
    ): void {
        $imapMessage = $folder->messages()
            ->whereUid($uid)
            ->get()
            ->first();

        if (null === $imapMessage) {
            $this->logger->warning('ApplyImapFlagsHandler: UID not found in source folder', [
                'uid'    => $uid,
                'folder' => $folder->path,
            ]);

            return;
        }

        match ($action) {
            'flag'    => $imapMessage->setFlag('Flagged'),
            'unflag'  => $imapMessage->unsetFlag('Flagged'),
            'seen'    => $imapMessage->setFlag('Seen'),
            'unseen'  => $imapMessage->unsetFlag('Seen'),
            'archive' => $imapMessage->move($destinationPath),
            'trash'   => $imapMessage->move($destinationPath),
            'move'    => $imapMessage->move($destinationPath),
            'delete'  => $imapMessage->delete(expunge: true),
            default   => $this->logger->warning('ApplyImapFlagsHandler: unknown action', ['action' => $action]),
        };
    }

    private function resolveDestinationPath(Client $client, Account $account, string $action): ?string
    {
        $specialUse = '\\Trash';

        if ('archive' === $action) {
            $specialUse = '\\Archive';
        }

        $mailbox = $this->mailboxRepository->findOneBy([
            'account'    => $account,
            'specialUse' => $specialUse,
        ]);

        if (null !== $mailbox) {
            return $mailbox->fullPath;
        }

        $nameMap = [
            '\\Trash'   => ['Trash', 'Deleted', 'Deleted Items', 'Deleted Messages'],
            '\\Archive' => ['Archive', 'Archives'],
        ];

        $candidates = $nameMap[$specialUse] ?? [];

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

        return null;
    }
}
