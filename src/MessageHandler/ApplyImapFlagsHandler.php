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
use Webklex\PHPIMAP\Client;

#[AsMessageHandler]
final class ApplyImapFlagsHandler
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly MailboxRepository $mailboxRepository,
        private readonly LoggerInterface   $logger,
    ) {}

    public function __invoke(ApplyImapFlagsMessage $message): void
    {
        $messages = $this->messageRepository->findBy(['id' => $message->messageIds]);

        if (count($messages) === 0) {
            $this->logger->warning('ApplyImapFlagsHandler: no messages found', [
                'ids'    => $message->messageIds,
                'action' => $message->action,
            ]);

            return;
        }

        // Group messages by account so we open one IMAP connection per account,
        // then sub-group by mailbox within each account.
        /** @var array<int, array<int, Message[]>> $byAccount  accountId → mailboxId → Message[] */
        $byAccount = [];

        foreach ($messages as $msg) {
            $accountId = $msg->getMailbox()->getAccount()->getId();
            $mailboxId = $msg->getMailbox()->getId();
            $byAccount[$accountId][$mailboxId][] = $msg;
        }

        foreach ($byAccount as $accountId => $byMailbox) {
            // All messages in the group share the same account object.
            $firstMessage = array_values($byMailbox)[0][0];
            $account      = $firstMessage->getMailbox()->getAccount();

            try {
                $client = ImapConnectionFactory::connect($account);
                $this->processAccount($client, $account, $byMailbox, $message->action);
                $client->disconnect();
            } catch (\Throwable $e) {
                $this->logger->error('ApplyImapFlagsHandler: IMAP error', [
                    'accountId' => $accountId,
                    'action'    => $message->action,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param array<int, Message[]> $byMailbox  mailboxId → Message[]
     */
    private function processAccount(
        Client  $client,
        Account $account,
        array   $byMailbox,
        string  $action,
    ): void {
        // Resolve destination mailbox once per account for move actions.
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

        foreach ($byMailbox as $mailboxId => $messages) {
            $mailbox = $this->mailboxRepository->find($mailboxId);

            if ($mailbox === null) {
                continue;
            }

            try {
                $this->processMailbox($client, $mailbox, $messages, $action, $destinationPath);
            } catch (\Throwable $e) {
                $this->logger->error('ApplyImapFlagsHandler: mailbox error', [
                    'mailboxId' => $mailboxId,
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
        Mailbox  $mailbox,
        array    $messages,
        string   $action,
        ?string  $destinationPath,
    ): void {
        $folder = $client->getFolder($mailbox->getName());

        if ($folder === null) {
            $this->logger->warning('ApplyImapFlagsHandler: folder not found', [
                'mailbox' => $mailbox->getName(),
            ]);

            return;
        }

        foreach ($messages as $msg) {
            // Messages synced from IMAP have an imapUid; locally-composed
            // messages that were never appended to IMAP do not.
            if ($msg->getImapUid() === null) {
                continue;
            }

            try {
                $this->applyToMessage($folder, $msg->getImapUid(), $action, $destinationPath);
            } catch (\Throwable $e) {
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
        \Webklex\PHPIMAP\Folder $folder,
        int    $uid,
        string $action,
        ?string $destinationPath,
    ): void {
        // Fetch the live message from IMAP by UID so we get the Webklex
        // Message object with its write methods.
        $imapMessage = $folder->messages()
            ->whereUid($uid)
            ->get()
            ->first();

        if ($imapMessage === null) {
            $this->logger->warning('ApplyImapFlagsHandler: UID not found on server', [
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

    /**
     * Resolve the IMAP folder path for the destination mailbox.
     * For archive: prefers \Archive, falls back to \Sent (unusual but safe).
     * For trash:   uses \Trash.
     */
    private function resolveDestinationPath(Client $client, Account $account, string $action): ?string
    {
        $specialUse = ($action === 'archive') ? '\\Archive' : '\\Trash';

        // Look up in our DB first — fastest path.
        $mailbox = $this->mailboxRepository->findOneBy([
            'account'    => $account,
            'specialUse' => $specialUse,
        ]);

        if ($mailbox !== null) {
            return $mailbox->getFullPath();
        }

        // Fall back to scanning server folders by name.
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
            } catch (\Throwable) {
                // Not found — try next.
            }
        }

        return null;
    }
}
