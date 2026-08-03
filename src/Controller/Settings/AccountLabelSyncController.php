<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Mail\Account;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Per-account toggle for mirroring label structure to the provider.
 *
 * Off by default. With it on, creating, renaming or deleting a label — from the
 * web UI or from a JMAP client via Mailbox/set — is pushed to Gmail or
 * Microsoft asynchronously by LabelStructurePropagator.
 *
 * Only offered for Gmail and Microsoft: on plain IMAP a label is a physical
 * folder, so the same operations would move real mail on the server.
 *
 * Turbo-native, mirroring AccountPushController: the toggle re-renders its own
 * frame rather than the whole accounts list.
 */
#[IsGranted('ROLE_USER')]
final class AccountLabelSyncController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/settings/accounts/{id}/label-sync', name: 'settings_account_label_sync_toggle', methods: ['POST'])]
    public function toggle(Request $request, Account $account): Response
    {
        $this->assertOwnership($account);

        $token = (string) $request->request->get('_token');

        if (false === $this->isCsrfTokenValid('account_label_sync_' . $account->id, $token)) {
            throw $this->createAccessDeniedException();
        }

        if (false === $account->supportsLabelSync()) {
            throw $this->createNotFoundException('This account provider cannot mirror label structure.');
        }

        // Only ever affects labels changed from now on. Existing labels are not
        // retroactively pushed — that would mean bulk-creating the user's whole
        // local tree on the provider from a single click.
        $account->setSetting(Account::SETTING_LABEL_SYNC, false === $account->isLabelSyncEnabled());
        $this->em->flush();

        return $this->render('settings/accounts/_label_sync_control.html.twig', [
            'account' => $account,
        ]);
    }

    private function assertOwnership(Account $account): void
    {
        if ($account->usr !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }
}
