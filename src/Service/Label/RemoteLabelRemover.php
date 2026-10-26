<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Domain\Exception\GmailApiException;
use App\Domain\Exception\GmailThrottledException;
use App\Domain\Exception\GraphApiException;
use App\Domain\Exception\GraphThrottledException;
use App\Domain\Exception\LabelDeleteRefusedException;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\ImapFolderProvisioner;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\GraphApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes what one label binding is on its mail server — an IMAP folder, an
 * Exchange folder, a Gmail label, an Exchange category — and forgets it here.
 *
 * ## A folder is only deleted empty
 *
 * Deleting a folder on IMAP or Exchange takes the mail in it with it. The mail
 * has been moved out by the time this runs — DeleteLabelHandler's first phase
 * does that — but those moves are jobs on another queue, and "moved" here
 * means "asked to move". So the server is asked first, every time, and a
 * folder that still holds anything is left standing and reported as not done.
 * The caller tries again later; nothing here ever deletes mail to make
 * progress.
 *
 * ## A refusal is final
 *
 * The delete command itself is the one call whose failure is not waited out.
 * By then the server has been reached and has said the folder is empty, so a
 * "no" to the delete — or a server that drops the connection on it — is its
 * answer, not a passing outage, and it is thrown as LabelDeleteRefusedException
 * for the caller to end the job with. See refusable() for the exceptions.
 *
 * The same question is asked of this database before an IMAP folder's Mailbox
 * row goes, for a reason that has nothing to do with the server:
 * message.mailbox_id cascades on delete, so removing the row of a folder that
 * mail here still points at would delete that mail locally.
 *
 * ## Each step is written down as it lands
 *
 * The binding's ids are cleared, and the Mailbox row removed, as soon as the
 * server half succeeds, and flushed. A retry after a failure part-way through
 * a label's bindings then starts from what is left, rather than deleting a
 * Gmail label twice or asking about a folder that is already gone.
 *
 * Not final, as ImapFolderProvisioner is not: DeleteLabelHandlerTest stands a
 * double in for it, because the alternative is a mail server in the suite.
 */
readonly class RemoteLabelRemover
{
    public function __construct(
        private ImapConnectionFactory  $imapConnections,
        private GmailApiClient         $gmail,
        private GraphApiClient         $graph,
        private MessageRepository      $messages,
        private ImapFolderProvisioner  $provisioner,
        private LabelResolver          $labels,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    /**
     * Remove everything this binding is on the server.
     *
     * @return bool true when nothing of it is left there; false when a folder
     *              still holds mail and the caller should ask again later
     *
     * @throws LabelDeleteRefusedException when the server says no to the delete
     */
    public function remove(LabelBinding $binding): bool
    {
        $account = $binding->account;

        if (null === $account) {
            return true;
        }

        if (null !== $binding->mailbox && false === $this->removeImapFolder($account, $binding, $binding->mailbox)) {
            return false;
        }

        if (null !== $binding->graphFolderId) {
            $items = $this->graph->folderContentCount($account, $binding->graphFolderId);

            if (null !== $items && 0 < $items) {
                $this->logger->info('RemoteLabelRemover: Exchange folder still holds mail or folders, waiting', [
                    'accountId' => $account->id,
                    'folderId'  => $binding->graphFolderId,
                    'items'     => $items,
                ]);

                return false;
            }

            if (null !== $items) {
                $folderId = $binding->graphFolderId;

                $this->refusable($binding, fn () => $this->graph->deleteFolder($account, $folderId));
            }

            $binding->graphFolderId = null;
            $this->em->flush();
        }

        // The two tags. Nothing lives in either, so there is nothing to wait
        // for: Gmail drops a label from every message it was on, and Exchange
        // keeps a category on each message as loose text that the next push
        // derives from this database, where the label is about to be gone.
        if (null !== $binding->gmailLabelId) {
            $labelId = $binding->gmailLabelId;

            $this->refusable($binding, fn () => $this->gmail->deleteLabel($account, $labelId));
            $binding->gmailLabelId = null;
            $this->em->flush();
        }

        if (null !== $binding->graphCategoryId) {
            $categoryId = $binding->graphCategoryId;

            $this->refusable($binding, fn () => $this->graph->deleteMasterCategory($account, $categoryId));
            $binding->graphCategoryId = null;
            $this->em->flush();
        }

        return true;
    }

    /**
     * Make sure an IMAP account has the system folder a move is about to put
     * mail into, before the move — the one other server call a delete needs.
     *
     * Without it the move goes wrong in a way that only shows afterwards. When
     * the account has no Archive yet, ThreadStatusUpdater::archive() has no
     * local folder to point the moved rows at and leaves them where they were;
     * the export job then creates Archive on the server and moves the mail
     * into it, but the rows go on saying the mail is in the folder being
     * deleted. The server reports that folder empty, the rows say otherwise,
     * and the delete waits for a change that never comes. Created first, the
     * folder is there for the move to point the rows at.
     *
     * Plain IMAP only: Exchange and Gmail have their system folders by
     * definition, and an account that already has this one is left alone.
     *
     * @return bool false when the server would not create it
     */
    public function prepareDestination(Account $account, LabelRole $role): bool
    {
        $specialUse = match ($role) {
            LabelRole::Archive => MailboxSpecialUse::ARCHIVE,
            LabelRole::Trash   => MailboxSpecialUse::TRASH,
            default            => null,
        };

        // A POP3 account is included in this guard for the same reason Gmail
        // is: it has no folders to provision, and the caller's own fallback —
        // a label-only arrival point — is the right answer for it.
        if (null === $specialUse || null === $account->imapHost || false === $account->isPlainImap()) {
            return true;
        }

        if (null !== $this->labels->systemLabel($role, $account)->bindingFor($account)?->mailbox) {
            return true;
        }

        $client = $this->imapConnections->connect($account);

        try {
            return null !== $this->provisioner->ensureSpecialUse($account, $client, $specialUse);
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
            }
        }
    }

    /**
     * Delete an IMAP folder that is empty on the server and empty here.
     *
     * The path is the one the server gave on LIST, stored as it came — still
     * in IMAP's modified UTF-7 — so it is looked up and deleted as it is.
     * A folder the server no longer lists is already what this wants.
     */
    private function removeImapFolder(Account $account, LabelBinding $binding, Mailbox $mailbox): bool
    {
        $path = (string) $mailbox->fullPath;

        $local = $this->messages->countInMailbox($mailbox);

        if (0 < $local) {
            $this->logger->info('RemoteLabelRemover: mail here is still recorded in the folder, waiting', [
                'accountId' => $account->id,
                'folder'    => $path,
                'messages'  => $local,
            ]);

            return false;
        }

        $client = $this->imapConnections->connect($account);

        try {
            if (null !== $client->getFolderByPath($path, utf7: true)) {
                $status   = $client->getConnection()->folderStatus($path, ['MESSAGES'])->validatedData();
                $messages = is_array($status) ? (int) ($status['messages'] ?? 0) : 0;

                if (0 < $messages) {
                    $this->logger->info('RemoteLabelRemover: IMAP folder still holds mail, waiting', [
                        'accountId' => $account->id,
                        'folder'    => $path,
                        'messages'  => $messages,
                    ]);

                    return false;
                }

                $this->refusable($binding, static fn () => $client->getConnection()->deleteFolder($path)->validatedData());

                // Servers that keep subscriptions apart from folders would go
                // on listing a deleted folder as subscribed. Worth trying and
                // not worth failing over: the folder is gone either way.
                try {
                    $client->getConnection()->unsubscribeFolder($path);
                } catch (Throwable $e) {
                    $this->logger->info('RemoteLabelRemover: could not unsubscribe a deleted IMAP folder', [
                        'accountId' => $account->id,
                        'folder'    => $path,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            // A server that dropped the connection on the delete leaves nothing
            // to disconnect from, and the error from trying must not replace
            // the refusal on its way out.
            try {
                $client->disconnect();
            } catch (Throwable) {
            }
        }

        // Counted again, after the server round trip: a sync could have put
        // a message here in the meantime, and the cascade is not a risk worth
        // taking on a count from before it.
        if (0 < $this->messages->countInMailbox($mailbox)) {
            return false;
        }

        // Unhooked first: the binding holds the owning side of the one-to-one,
        // and a flush that removes the row while the binding still points at
        // it is asking Doctrine to write a reference to a deleted entity.
        $binding->mailbox = null;
        $this->em->remove($mailbox);
        $this->em->flush();

        return true;
    }

    /**
     * Run a delete command, turning the server's refusal into
     * LabelDeleteRefusedException.
     *
     * Two kinds of failure are let through unchanged, because they are not
     * an answer to the delete: a provider asking to slow down, and a provider
     * failing on its own side (5xx). Both are waited out like a folder that is
     * not empty yet. Everything else — an IMAP NO or BAD, a Graph 4xx, a
     * connection the server closed on the command — is the server's verdict.
     */
    private function refusable(LabelBinding $binding, callable $delete): void
    {
        try {
            $delete();
        } catch (GraphThrottledException | GmailThrottledException $e) {
            throw $e;
        } catch (GraphApiException | GmailApiException $e) {
            if (500 <= $e->getStatus()) {
                throw $e;
            }

            throw new LabelDeleteRefusedException((string) $binding->label?->fullName, $e);
        } catch (Throwable $e) {
            throw new LabelDeleteRefusedException((string) $binding->label?->fullName, $e);
        }
    }
}
