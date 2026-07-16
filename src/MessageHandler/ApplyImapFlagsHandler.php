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

#[AsMessageHandler]
final class ApplyImapFlagsHandler
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly MailboxRepository $mailboxRepository,
        private readonly LoggerInterface   $logger,
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
            $accountId       = $msg->getMailbox()->getAccount()->getId();
            $sourceMailboxId = $message->messageIds[$msg->getId()];
            $byAccount[$accountId][$sourceMailboxId][] = $msg;
        }

        foreach ($byAccount as $accountId => $byMailbox) {
            $account = array_values($byMailbox)[0][0]->getMailbox()->getAccount();


            try {
                $client = $this->imapConnectionFactory->connect($account);
                $this->processAccount($client, $account, $byMailbox, $message->action);
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
    ): void {
        $destinationPath = null;

        if ($action === 'archive' || $action === 'trash') {
            $destinationPath = $this->resolveDestinationPath($client, $account, $action);

            if ($destinationPath === null) {
                $this->logger->warning('ApplyImapFlagsHandler: destination mailbox not found', [
                    'accountId' => $account->getId(),
                    'action'    => $action,
                ]);

                return;
            }
        }

        foreach ($byMailbox as $sourceMailboxId => $messages) {
            $sourceMailbox = $this->mailboxRepository->find($sourceMailboxId);

            if ($sourceMailbox === null) {
                $this->logger->warning('ApplyImapFlagsHandler: source mailbox not found', [
                    'mailboxId' => $sourceMailboxId,
                ]);
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
        Client   $client,
        Mailbox  $sourceMailbox,
        array    $messages,
        string   $action,
        ?string  $destinationPath,
    ): void {
        $folder = $client->getFolder($sourceMailbox->getName());

        if ($folder === null) {
            $this->logger->warning('ApplyImapFlagsHandler: source folder not found on server', [
                'mailbox' => $sourceMailbox->getName(),
            ]);

            return;
        }

        foreach ($messages as $msg) {
            if ($msg->getImapUid() === null) {
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
        Folder $folder,
        int    $uid,
        string $action,
        ?string $destinationPath,
    ): void {
        $imapMessage = $folder->messages()
            ->whereUid($uid)
            ->get()
            ->first();

        if ($imapMessage === null) {
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
            'delete'  => $imapMessage->delete(expunge: true),
            default   => $this->logger->warning('ApplyImapFlagsHandler: unknown action', ['action' => $action]),
        };
    }

    private function resolveDestinationPath(Client $client, Account $account, string $action): ?string
    {
        $specialUse = ($action === 'archive') ? '\\Archive' : '\\Trash';

        $mailbox = $this->mailboxRepository->findOneBy([
            'account'    => $account,
            'specialUse' => $specialUse,
        ]);

        if ($mailbox !== null) {
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

                if ($folder !== null) {
                    return $folder->path;
                }
            } catch (Throwable) {
                // Not found — try next.
            }
        }

        return null;
    }
}
