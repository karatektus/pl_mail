<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Account;
use App\Entity\Mailbox;
use App\Entity\Message;
use App\Message\ApplyImapFlagsMessage;
use App\Repository\MailboxRepository;
use App\Repository\MessageRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;

/**
 * Best-effort outgoing IMAP state sync. Every failure is logged, never
 * rethrown into the local mutation — the DB is the source of truth and
 * incoming sync reconciles drift.
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

            $accountId = $sourceMailbox->getAccount()->getId();

            $byAccount[$accountId][$sourceMailboxId][] = $msg;
        }

        foreach ($byAccount as $accountId => $byMailbox) {
            $firstMailboxId = array_key_first($byMailbox);
            $account        = $this->mailboxRepository->find($firstMailboxId)->getAccount();

            try {
                $client = $this->imapConnectionFactory->connect($account);
                $this->processAccount($client, $account, $byMailbox, $message->action, $message->destinationPath);
                $client->disconnect();
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
        $folder = $client->getFolder($sourceMailbox->getName());

        if (null === $folder) {
            $this->logger->warning('ApplyImapFlagsHandler: source folder not found on server', [
                'mailbox' => $sourceMailbox->getName(),
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
            return $mailbox->getFullPath();
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
