<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Exception\FolderActionRefusedException;
use App\Domain\Exception\ImapFolderRefusedException;
use App\Domain\Exception\InvalidFolderNameException;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Repository\Mail\MailboxRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Imap\FolderTree;
use App\Service\Imap\ImapFolderManager;
use App\Service\Imap\ImapPermanentFlags;
use App\Service\Label\LabelPlaces;
use App\Domain\Helper\ImapConnectionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

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
 *
 * ## The folder actions (#221)
 *
 * The same list is where folders are made — a folder is a place on the
 * account's server, so it is made where that account's tree is shown, never
 * as a side effect of making a label. ImapFolderManager does the work; this
 * only turns its answers into a sentence. Every answer re-renders the modal
 * frame, so the list stays open on the result.
 */
#[IsGranted('ROLE_USER')]
final class AccountFoldersController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly MailboxRepository      $mailboxes,
        private readonly FolderTree             $tree,
        private readonly EntityManagerInterface $em,
        private readonly ImapFolderManager      $folders,
        private readonly TranslatorInterface    $translator,
        private readonly ImapConnectionFactory  $connections,
        private readonly ImapPermanentFlags     $permanentFlags,
        private readonly LabelPlaces            $places,
    ) {}

    #[Route('/settings/accounts/{id}/folders', name: 'settings_account_folders', methods: ['GET'])]
    public function list(Account $account): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);
        $this->assertSyncsOverImap($account);

        return $this->renderList($account);
    }

    /**
     * The New folder form: a name, and where it goes among the folders the
     * server listed. `?parent=` preselects one.
     */
    #[Route('/settings/accounts/{id}/folders/new', name: 'settings_account_folder_new', methods: ['GET'])]
    public function new(Request $request, Account $account): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);
        $this->assertSyncsOverImap($account);

        return $this->renderNewForm($account, self::idOrNull($request->query->get('parent')), '', null);
    }

    #[Route('/settings/accounts/{id}/folders/new', name: 'settings_account_folder_create', methods: ['POST'])]
    public function create(Request $request, Account $account): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);
        $this->assertSyncsOverImap($account);
        $this->assertCsrf($request, 'account_folder_new_' . $account->id);

        $name     = (string) $request->request->get('name', '');
        $parentId = self::idOrNull($request->request->get('parent'));
        $parent   = null;

        if (null !== $parentId) {
            $parent = $this->mailboxes->find($parentId);

            // Checked against THIS account for the reason toggle() gives.
            if (null === $parent || $parent->account !== $account) {
                throw $this->createNotFoundException();
            }
        }

        try {
            $mailbox = $this->folders->create($account, $parent, $name);
        } catch (InvalidFolderNameException $e) {
            return $this->renderNewForm($account, $parentId, $name, $this->invalidName($e));
        } catch (ImapFolderRefusedException $e) {
            return $this->renderNewForm($account, $parentId, $name, $this->serverRefused($e));
        } catch (Throwable $e) {
            return $this->renderNewForm($account, $parentId, $name, $this->unreachable($e));
        }

        return $this->done($account, $this->translator->trans(
            'settings.accounts.folders.created',
            ['%folder%' => (string) $mailbox->name],
        ));
    }

    #[Route('/settings/accounts/{id}/folders/{mailbox}/rename', name: 'settings_account_folder_rename_form', methods: ['GET'])]
    public function renameForm(Account $account, Mailbox $mailbox): Response
    {
        $this->assertOwnFolder($account, $mailbox);

        return $this->renderRenameForm($account, $mailbox, (string) $mailbox->name, null);
    }

    /**
     * Rename with IMAP RENAME, in the same place. The inbox and the other
     * special-use folders are refused (FolderActionRefusedException says why),
     * and the row offers no button for them in the first place.
     */
    #[Route('/settings/accounts/{id}/folders/{mailbox}/rename', name: 'settings_account_folder_rename', methods: ['POST'])]
    public function rename(Request $request, Account $account, Mailbox $mailbox): Response
    {
        $this->assertOwnFolder($account, $mailbox);
        $this->assertCsrf($request, 'account_folder_rename_' . $mailbox->id);

        $name = (string) $request->request->get('name', '');

        try {
            $this->folders->rename($account, $mailbox, $name);
        } catch (InvalidFolderNameException $e) {
            return $this->renderRenameForm($account, $mailbox, $name, $this->invalidName($e));
        } catch (FolderActionRefusedException $e) {
            return $this->renderRenameForm($account, $mailbox, $name, $this->refused($e));
        } catch (ImapFolderRefusedException $e) {
            return $this->renderRenameForm($account, $mailbox, $name, $this->serverRefused($e));
        } catch (Throwable $e) {
            return $this->renderRenameForm($account, $mailbox, $name, $this->unreachable($e));
        }

        return $this->done($account, $this->translator->trans(
            'settings.accounts.folders.renamed',
            ['%folder%' => (string) $mailbox->name],
        ));
    }

    /**
     * Delete an empty folder with no folders inside it. Whatever stops it is
     * said above the list, which stays as it was.
     */
    #[Route('/settings/accounts/{id}/folders/{mailbox}/delete', name: 'settings_account_folder_delete', methods: ['POST'])]
    public function delete(Request $request, Account $account, Mailbox $mailbox): Response
    {
        $this->assertOwnFolder($account, $mailbox);
        $this->assertCsrf($request, 'account_folder_delete_' . $mailbox->id);

        $name = (string) $mailbox->name;

        try {
            $this->folders->delete($account, $mailbox);
        } catch (FolderActionRefusedException $e) {
            return $this->renderList($account, $this->refused($e));
        } catch (ImapFolderRefusedException $e) {
            return $this->renderList($account, $this->serverRefused($e));
        } catch (Throwable $e) {
            return $this->renderList($account, $this->unreachable($e));
        }

        return $this->done($account, $this->translator->trans(
            'settings.accounts.folders.deleted',
            ['%folder%' => $name],
        ), keepList: true);
    }

    /**
     * Labels as IMAP keywords on this account, on or off (#221 step 2).
     *
     * Switching it on asks each folder plMail fetches whether it keeps
     * keywords, so the list and the label dialog can say so at once instead of
     * after the first label is written. A server that cannot be reached does
     * not stop the switch: the folders are asked again as keywords are written.
     * Only changes made from now on are written; labels that are already on
     * mail are not pushed in bulk.
     */
    #[Route('/settings/accounts/{id}/folders/keywords', name: 'settings_account_folder_keywords', methods: ['POST'])]
    public function keywords(Request $request, Account $account): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);
        $this->assertSyncsOverImap($account);
        $this->assertCsrf($request, 'account_folder_keywords_' . $account->id);

        $account->labelsAsKeywords = false === $account->labelsAsKeywords;

        if (true === $account->labelsAsKeywords) {
            $this->askFolders($account);
        }

        $this->em->flush();

        return $this->renderList($account);
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

    /**
     * The list, with why the last action did not work when it did not.
     *
     * Always a 200, a refused delete included. The delete button sits inside
     * its row's own frame and targets the modal; Turbo draws a failed (4xx)
     * answer into the frame the form is IN, so a 422 replaced the row with
     * itself and the reason never showed.
     */
    private function renderList(Account $account, ?string $error = null): Response
    {
        return $this->render('settings/accounts/_folders_modal.html.twig', [
            'account'         => $account,
            'rows'            => $this->tree->rows($this->mailboxes->findForAccountOrdered($account)),
            'error'           => $error,
            'keywordsAllowed' => $this->places->inboxAllows($account),
        ]);
    }

    /**
     * A folder action that worked: the app's toast, and both sidebars drawn
     * again so the folder is where it now is — under its account and under
     * LABELS — without a reload. A turbo-stream, so the New folder and rename
     * forms' modal closes on it like any other form's. A delete, pressed inside
     * the list, keeps the list open: it gets the list back without the folder,
     * with the same stream inside it.
     */
    private function done(Account $account, string $toast, bool $keepList = false): Response
    {
        $stream = $this->renderView('settings/accounts/_folder_done.stream.html.twig', ['toast' => $toast]);

        if (true === $keepList) {
            return $this->render('settings/accounts/_folders_modal.html.twig', [
                'account' => $account,
                'rows'    => $this->tree->rows($this->mailboxes->findForAccountOrdered($account)),
                'error'   => null,
                'done'    => $stream,
                'keywordsAllowed' => $this->places->inboxAllows($account),
            ]);
        }

        return new Response($stream, headers: ['Content-Type' => 'text/vnd.turbo-stream.html']);
    }

    private function renderRenameForm(Account $account, Mailbox $mailbox, string $name, ?string $error): Response
    {
        return $this->render('settings/accounts/_folder_form.html.twig', [
            'account'  => $account,
            'mode'     => 'rename',
            'rows'     => [],
            'parentId' => null,
            'mailbox'  => $mailbox,
            'name'     => $name,
            'error'    => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function refused(FolderActionRefusedException $e): string
    {
        return $this->translator->trans(
            'settings.accounts.folders.refused.' . $e->reason,
            ['%count%' => $e->count],
        );
    }

    private function serverRefused(ImapFolderRefusedException $e): string
    {
        return $this->translator->trans('settings.accounts.folders.refused.server', ['%reason%' => $e->reason]);
    }

    /**
     * The server could not be reached at all. Said as such, not as a 500: the
     * person can try again, and nothing was changed.
     */
    private function unreachable(Throwable $e): string
    {
        return $this->translator->trans('settings.accounts.folders.unreachable', ['%reason%' => $e->getMessage()]);
    }

    /**
     * The account is the caller's, plain IMAP, and the folder is ITS folder —
     * the pair in the path has to agree with itself, as toggle() explains.
     */
    private function assertOwnFolder(Account $account, Mailbox $mailbox): void
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);
        $this->assertSyncsOverImap($account);

        if ($mailbox->account !== $account) {
            throw $this->createNotFoundException();
        }
    }

    private function renderNewForm(Account $account, ?int $parentId, string $name, ?string $error): Response
    {
        return $this->render('settings/accounts/_folder_form.html.twig', [
            'account'  => $account,
            'mode'     => 'new',
            'rows'     => $this->tree->rows($this->mailboxes->findForAccountOrdered($account)),
            'parentId' => $parentId,
            'mailbox'  => null,
            'name'     => $name,
            'error'    => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function invalidName(InvalidFolderNameException $e): string
    {
        return $this->translator->trans(
            'settings.accounts.folders.invalid.' . $e->reason,
            ['%separator%' => $e->separator],
        );
    }

    /**
     * The parent picker's value: an id, or "" for the top level. getInt()
     * cannot be used, because it refuses "" outright with a 400.
     */
    private static function idOrNull(mixed $value): ?int
    {
        return is_string($value) && 1 === preg_match('/^[1-9]\d*$/', $value) ? (int) $value : null;
    }

    private function askFolders(Account $account): void
    {
        try {
            $client = $this->connections->connect($account);
        } catch (Throwable) {
            return;
        }

        try {
            foreach ($this->mailboxes->findForAccountOrdered($account) as $mailbox) {
                if (true === $mailbox->isSelectable && true === $mailbox->isSyncEnabled) {
                    $this->permanentFlags->probe($client, $mailbox);
                }
            }
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
            }
        }
    }

    private function assertSyncsOverImap(Account $account): void
    {
        if (false === $account->isPlainImap()) {
            throw $this->createNotFoundException('This account has no IMAP folders to choose from: it syncs over a provider API or over POP3.');
        }
    }
}
