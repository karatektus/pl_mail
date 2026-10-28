<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Helper\ThrowableSeverity;
use App\Entity\Mail\Account;
use App\Infrastructure\ImapClient\Exception\ImapCommandException;
use App\Infrastructure\ImapClient\ImapSession;
use App\Infrastructure\JmapClient\JmapCapability;
use App\Infrastructure\JmapClient\JmapClient;
use App\Infrastructure\Messaging\Message\PurgeRemoteMessagesMessage;
use App\Repository\Mail\AccountRepository;
use App\Service\Imap\ImapConnector;
use App\Service\JmapClient\JmapMailboxSyncer;
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
        private ImapConnector         $connector,
        private GmailApiClient        $gmail,
        private GraphApiClient        $graph,
        private LoggerInterface       $logger,
        private JmapClient            $jmap,
        private JmapMailboxSyncer     $jmapMailboxes,
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

        if ([] !== $message->jmapIds) {
            $this->purgeJmap($account, $message->jmapIds);
        }
    }

    /**
     * \Deleted plus an EXPUNGE, folder by folder.
     *
     * One STORE and one EXPUNGE per folder rather than per message: EXPUNGE
     * renumbers, and doing it once at the end of each folder is both fewer
     * round trips and the ordering the protocol is happiest with. A UID that
     * is already gone is the common case and a success — the end state wanted
     * is that the message does not exist — and UID STORE passes over a UID
     * the folder no longer has without complaint.
     *
     * UID EXPUNGE where the server has UIDPLUS, so only these messages go and
     * not whatever else another client has marked \Deleted and may still
     * undelete. Without it, the folder's EXPUNGE, as before.
     *
     * @param array<string, list<int>> $byFolder
     */
    private function purgeImap(Account $account, array $byFolder): void
    {
        $session = $this->connector->session($account);

        try {
            foreach ($byFolder as $path => $uids) {
                $this->purgeFolder($session, $account, (string) $path, $uids);
            }
        } finally {
            $session->logout();
        }
    }

    /** @param list<int> $uids */
    private function purgeFolder(ImapSession $session, Account $account, string $path, array $uids): void
    {
        if ([] === $uids) {
            return;
        }

        $set = ImapSession::uidSet($uids);

        try {
            $session->uidStore($path, $set, '+', ['\\Deleted']);
            $session->expunge($path, $set);
        } catch (ImapCommandException $e) {
            // The folder is gone, or the server will not let it be changed.
            // Either way not this job's to retry: the rows are gone already.
            $this->logger->warning('PurgeRemoteMessages: could not purge a folder', [
                'accountId' => $account->id,
                'folder'    => $path,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /** @param list<string> $graphIds */
    /**
     * Email/set destroy, in one call: JMAP takes the whole list at once and
     * answers for each id, and an id it no longer has is notFound — already
     * the end state wanted, so not reported.
     *
     * @param list<string> $jmapIds
     */
    private function purgeJmap(Account $account, array $jmapIds): void
    {
        if (false === $account->isJmap()) {
            return;
        }

        try {
            $result = $this->jmap->call($account, [JmapCapability::MAIL], [
                ['Email/set', ['accountId' => $this->jmapMailboxes->mailAccountId($account), 'destroy' => $jmapIds], 's'],
            ])->get('s', 'Email/set');
        } catch (Throwable $e) {
            $this->logger->log(ThrowableSeverity::level($e), 'PurgeRemoteMessages: JMAP destroy failed', [
                'accountId' => $account->id,
                'count'     => count($jmapIds),
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            return;
        }

        foreach (is_array($result['notDestroyed'] ?? null) ? $result['notDestroyed'] : [] as $id => $error) {
            $type = is_array($error) && is_string($error['type'] ?? null) ? $error['type'] : 'unknown';

            if ('notFound' !== $type) {
                $this->logger->warning('PurgeRemoteMessages: JMAP refused to destroy an Email', [
                    'accountId' => $account->id,
                    'emailId'   => $id,
                    'type'      => $type,
                ]);
            }
        }
    }

    private function purgeGraph(Account $account, array $graphIds): void
    {
        foreach ($graphIds as $graphId) {
            try {
                $this->graph->deleteMessage($account, $graphId);
            } catch (Throwable $e) {
                $this->logger->log(ThrowableSeverity::level($e), 'PurgeRemoteMessages: Graph delete failed', [
                    'accountId' => $account->id,
                    'graphId'   => $graphId,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }
}
