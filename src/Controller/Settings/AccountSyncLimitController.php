<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Account;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Per-account cap on how many of the newest messages a sync run pulls.
 *
 * Exists for large mailboxes: backfilling 60k messages takes hours before the
 * UI is useful, and the newest few thousand are what gets read. Raising or
 * clearing the cap lets later runs walk further back; app:reset re-fetches
 * everything from scratch.
 *
 * Not offered for Microsoft — see Account::supportsSyncLimit().
 *
 * Turbo-native, mirroring AccountLabelSyncController: the control re-renders
 * its own frame rather than the whole accounts list.
 */
#[IsGranted('ROLE_USER')]
final class AccountSyncLimitController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/settings/accounts/{id}/sync-limit', name: 'settings_account_sync_limit', methods: ['POST'])]
    public function update(Request $request, Account $account): Response
    {
        $this->assertOwnership($account);

        $token = (string) $request->request->get('_token');

        if (false === $this->isCsrfTokenValid('account_sync_limit_' . $account->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }

        if (false === $account->supportsSyncLimit()) {
            throw $this->createNotFoundException('This account provider cannot cap synced messages.');
        }

        $limit = (int) $request->request->get('limit');

        // Whitelist rather than clamp: an arbitrary number from a tampered form
        // would still be honoured by the syncers, but the UI only ever offers
        // these, so anything else is a bug or an attack, not a preference.
        if (false === in_array($limit, Account::SYNC_LIMIT_CHOICES, true)) {
            throw $this->createNotFoundException('Unsupported sync limit.');
        }

        // Only affects future runs. Lowering it does not delete already-synced
        // mail — that is what app:reset is for.
        $account->setSyncLimit($limit);
        $this->em->flush();

        return $this->render('settings/accounts/_sync_limit_control.html.twig', [
            'account' => $account,
        ]);
    }

    private function assertOwnership(Account $account): void
    {
        if ($account->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }
}
