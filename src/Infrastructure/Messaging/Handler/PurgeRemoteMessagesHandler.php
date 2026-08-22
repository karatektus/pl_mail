<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\PurgeRemoteMessagesMessage;
use App\Repository\Mail\AccountRepository;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\GraphApiClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Destroy at the provider what plMail has already destroyed locally.
 *
 * The one propagation handler that cannot look anything up. By the time it
 * runs, the rows are gone — that is the whole point of the operation — so
 * every address it needs travels in the envelope. See
 * PurgeRemoteMessagesMessage for why that shape was necessary rather than
 * merely tidy.
 *
 * ## Failure is per message, and never fatal to the batch
 *
 * A message that has already been deleted by another client answers 404, and a
 * folder somebody renamed is simply not there. Neither is an error worth
 * retrying or worth taking the other twenty messages down with, because the
 * desired end state — that message not existing — is already true. What is
 * worth surfacing is the case that will not fix itself: Gmail refusing for want
 * of the full mail scope. GmailApiClient::batchDelete() classifies that as
 * unrecoverable, so it lands in the log once instead of retrying until the
 * transport gives up.
 *
 * ## Ordering
 *
 * Queued from MessagePurger BEFORE the local rows are removed, onto the same
 * export queue every other propagation uses — so any relabel already queued for
 * these messages is ahead of this and cannot arrive after the delete.
 */
#[AsMessageHandler]
final readonly class PurgeRemoteMessagesHandler
{
    public function __construct(
        private AccountRepository     $accountRepository,
        private ImapConnectionFactory $imapConnectionFactory,
        private GmailApiClient        $gmail,
        private GraphApiClient        $graph,
        private LoggerInterface       $logger,
    ) {
    }

    public function __invoke(PurgeRemoteMessagesMessage $message): void
    {
        if (true === $message->isEmpty()) {
            return;
        }

        $account = $this->accountRepository->find($message->accountId);

        if (null === $account) {
            // The account went with the mail — a disconnect, or a reset. There
            // is nothing left to authenticate as, and nothing to be done about
            // it, so this is not an error.
            $this->logger->info('PurgeRemoteMessages: account is gone, nothing to purge', [
                'accountId' => $message->accountId,
            ]);

            return;
        }

        if ([] !== $message->imapUids) {
            $this->purgeImap($account, $message->imapUids);
        }

        if ([] !== $message->gmailIds) {
            $this->gmail->batchDelete($account, $message->gmailIds);
        }

        if ([] !== $message->graphIds) {
            $this->purgeGraph($account, $message->graphIds);
        }
    }

    /**
     * \Deleted plus an EXPUNGE, folder by folder.
     *
     * Expunged per folder rather than per message: EXPUNGE renumbers, and doing
     * it once at the end of each folder is both fewer round trips and the
     * ordering the protocol is happiest with.
     *
     * @param array<string, list<int>> $byFolder
     */
    private function purgeImap(Account $account, array $byFolder): void
    {
        $client = $this->imapConnectionFactory->connect($account);

        foreach ($byFolder as $path => $uids) {
            $folder = $client->getFolder($path);

            if (null === $folder) {
                $this->logger->warning('PurgeRemoteMessages: folder not found on the server', [
                    'accountId' => $account->id,
                    'folder'    => $path,
                ]);

                continue;
            }

            foreach ($uids as $uid) {
                try {
                    // setFetchBody(false): the body is about to stop existing,
                    // and fetching it to mark it deleted would download every
                    // attachment of every message being purged.
                    $folder->messages()
                        ->whereUid($uid)
                        ->setFetchBody(false)
                        ->get()
                        ->first()
                        ?->delete(expunge: false);
                } catch (Throwable $e) {
                    // Already gone is the common case, and it is a success:
                    // the end state this job wants is that the message does
                    // not exist.
                    $this->logger->info('PurgeRemoteMessages: could not delete by UID', [
                        'accountId' => $account->id,
                        'folder'    => $path,
                        'uid'       => $uid,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }

            // Once per folder, after the whole batch. EXPUNGE renumbers, so
            // expunging per message would be both more round trips and a
            // sequence the server is entitled to reorder underneath us.
            try {
                $client->expunge();
            } catch (Throwable $e) {
                $this->logger->warning('PurgeRemoteMessages: expunge failed', [
                    'accountId' => $account->id,
                    'folder'    => $path,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    /** @param list<string> $graphIds */
    private function purgeGraph(Account $account, array $graphIds): void
    {
        foreach ($graphIds as $graphId) {
            try {
                $this->graph->deleteMessage($account, $graphId);
            } catch (Throwable $e) {
                $this->logger->info('PurgeRemoteMessages: Graph delete failed', [
                    'accountId' => $account->id,
                    'graphId'   => $graphId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }
}
