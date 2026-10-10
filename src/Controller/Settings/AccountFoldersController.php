<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Repository\Mail\MailboxRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Imap\FolderTree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Which of an IMAP account's folders plMail fetches.
 *
 * Every folder starts switched on, and a switch here is Mailbox::$isSyncEnabled
 * — the flag the syncers, the IDLE supervisor and the remote-deletion check
 * already read. Nothing else is touched: mail already stored stays where it is,
 * and switching a folder back on resumes from where it stopped.
 *
 * IMAP only. A Gmail or Microsoft account syncs over its provider's API and has
 * no folder rows to choose between, so neither route exists for it — the same
 * condition MailboxSyncer::syncForAccount() uses to skip it.
 *
 * Beside the accounts pane's own controls rather than in AccountController,
 * which is already the size it is; AccountPushController set that precedent.
 * Turbo-native likewise: a toggle answers with the folder's own frame, so the
 * list does not move.
 *
 * The inbox is not offered. A client that has stopped fetching the inbox is
 * not a client, and the answer to wanting that is to disable the account.
 *
 * Nor is a folder the server will not open (Mailbox::$isSelectable): it holds
 * no mail, and MailboxSyncer switches it off again on every folder sync.
 */
#[IsGranted('ROLE_USER')]
final class AccountFoldersController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly MailboxRepository      $mailboxes,
        private readonly FolderTree             $tree,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/settings/accounts/{id}/folders', name: 'settings_account_folders', methods: ['GET'])]
    public function list(Account $account): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);
        $this->assertSyncsOverImap($account);

        return $this->render('settings/accounts/_folders_modal.html.twig', [
            'account' => $account,
            'rows'    => $this->tree->rows($this->mailboxes->findForAccountOrdered($account)),
        ]);
    }

    #[Route('/settings/accounts/{id}/folders/{mailbox}/sync', name: 'settings_account_folder_sync_toggle', methods: ['POST'])]
    public function toggle(Request $request, Account $account, Mailbox $mailbox): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);
        $this->assertSyncsOverImap($account);

        // The path carries two ids and both are the caller's to choose. The
        // account was just checked for ownership; the folder has to be checked
        // against THAT account, or any folder id could be reached by pairing
        // it with an account of one's own.
        if ($mailbox->account !== $account) {
            throw $this->createNotFoundException();
        }

        $this->assertCsrf($request, 'account_folder_sync_' . $mailbox->id);

        if (MailboxSpecialUse::INBOX === $mailbox->specialUse) {
            throw $this->createAccessDeniedException('The inbox is always synced.');
        }

        // A placeholder the server will not open. Switching it on would be
        // undone by the next folder sync, and in between it would be polled
        // and fail — which is the error MailboxSyncer exists to prevent.
        if (false === $mailbox->isSelectable) {
            throw $this->createAccessDeniedException('This folder only groups other folders; there is nothing in it to sync.');
        }

        $mailbox->isSyncEnabled = false === $mailbox->isSyncEnabled;
        $this->em->flush();

        // The row alone, but with its place in the tree: it draws the lines
        // that join it to its parent and its siblings, and those are a fact
        // about the whole list.
        return $this->render('settings/accounts/_folder_row.html.twig', [
            'account' => $account,
            'row'     => $this->tree->rowFor($mailbox, $this->mailboxes->findForAccountOrdered($account)),
        ]);
    }

    private function assertSyncsOverImap(Account $account): void
    {
        if (true === $account->isGmail() || true === $account->isMicrosoft()) {
            throw $this->createNotFoundException('This account syncs over its provider API; it has no IMAP folders to choose from.');
        }
    }
}
