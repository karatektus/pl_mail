<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Repository\Mail\MailboxRepository;

/**
 * Where a new folder on a plain IMAP account goes, when nobody says otherwise
 * (#221): inside the folder chosen last time, or at the top level.
 *
 * One answer for both doors that make a folder, New folder in the account's
 * folders and "Make it a folder" in the label dialog, so choosing `Inbox` once
 * in either puts the next folder there from both. The top level is the
 * server's own (NAMESPACE, ImapFolderManager::create()); on Stalwart that is
 * beside the system folders, while FranklyMail's users keep theirs under the
 * inbox, and only the person can say which they want.
 */
final readonly class NewFolderPlace
{
    public function __construct(
        private MailboxRepository $mailboxes,
    ) {
    }

    /**
     * The folder chosen last time, if the account still has it; null for the
     * top level.
     */
    public function defaultFor(Account $account): ?Mailbox
    {
        $path = $account->newFolderParentPath;

        if (null === $path) {
            return null;
        }

        return $this->mailboxes->findOneBy(['account' => $account, 'fullPath' => $path, 'missingSince' => null]);
    }

    /**
     * The folder the person picked, as a Mailbox of THIS account; null for the
     * top level. An id of another account's folder, or of none, is refused.
     *
     * @throws \InvalidArgumentException
     */
    public function chosen(Account $account, ?int $mailboxId): ?Mailbox
    {
        if (null === $mailboxId) {
            return null;
        }

        $mailbox = $this->mailboxes->find($mailboxId);

        if (null === $mailbox || $mailbox->account !== $account) {
            throw new \InvalidArgumentException('Not a folder of this account.');
        }

        return $mailbox;
    }

    /** Remember the choice for next time. The caller flushes. */
    public function remember(Account $account, ?Mailbox $parent): void
    {
        $account->newFolderParentPath = $parent?->fullPath;
    }
}
