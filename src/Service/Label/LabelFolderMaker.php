<?php

declare(strict_types=1);

namespace App\Service\Label;

use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Service\Imap\ImapFolderManager;
use App\Service\Mail\GraphApiClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Make a label a folder on one account, from the label dialog (#221).
 *
 * A label is a tag until someone asks otherwise, and only for the account they
 * asked about: "Work" can become an Outlook folder and stay a keyword on an
 * IMAP account beside it. A label nested under another needs its parent to be
 * a folder there too, because that is where the folder goes.
 *
 * This makes the folder and binds it. The mail already wearing the label moves
 * afterwards, in MakeLabelFolderHandler.
 */
final readonly class LabelFolderMaker
{
    public function __construct(
        private ImapFolderManager      $imapFolders,
        private GraphApiClient         $graph,
        private LabelResolver          $labels,
        private EntityManagerInterface $em,
    ) {
    }

    public function canMake(Label $label, Account $account): bool
    {
        // Mail switched off (#575) makes no folders: the folder is made on the
        // server, and nothing goes there while the switch is off.
        return null === $label->role
            && false === $label->isSystem
            && true === $account->mailEnabled
            && (true === $account->isPlainImap() || true === $account->isMicrosoft())
            && false === $this->isFolder($label, $account);
    }

    public function isFolder(Label $label, Account $account): bool
    {
        $binding = $label->bindingFor($account);

        return null !== $binding?->mailbox || null !== $binding?->graphFolderId;
    }

    /**
     * Where the folder will go on this plain IMAP account: inside its parent
     * label's folder when the parent is one there already, which is not the
     * person's to choose; null when it goes where they pick (the label has no
     * parent, or its parent becomes a folder too and takes the place).
     */
    public function fixedPlace(Label $label, Account $account): ?Mailbox
    {
        return $label->parent?->bindingFor($account)?->mailbox;
    }

    /**
     * $place is where the folder goes on a plain IMAP account when its parent
     * label does not decide (fixedPlace()): inside that folder, or at the top
     * level for null. A parent label that becomes a folder first takes it, and
     * the folder goes inside the parent's.
     *
     * @throws \Throwable with the server's reason when it refuses
     */
    public function make(Label $label, Account $account, ?Mailbox $place = null): void
    {
        if (true === $this->isFolder($label, $account)) {
            return;
        }

        if (null !== $label->parent && null === $label->parent->role) {
            $this->make($label->parent, $account, $place);
        }

        $binding = $this->labels->binding($label, $account);

        if (true === $account->isMicrosoft()) {
            $created = $this->graph->createFolder(
                $account,
                (string) $label->name,
                $label->parent?->bindingFor($account)?->graphFolderId,
            );

            $binding->graphFolderId = (string) $created['id'];
            $this->em->flush();

            return;
        }

        $mailbox = $this->imapFolders->create($account, $this->fixedPlace($label, $account) ?? $place, (string) $label->name);

        // The row a sync would make binds by path, which leads back here for
        // every name but one differing only in case. The person chose this label.
        if ($mailbox->labelBinding !== $binding) {
            if (null !== $mailbox->labelBinding) {
                $mailbox->labelBinding->mailbox = null;
            }

            $binding->mailbox      = $mailbox;
            $mailbox->labelBinding = $binding;
        }

        $this->em->flush();
    }
}
