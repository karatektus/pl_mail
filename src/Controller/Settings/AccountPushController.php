<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Account;
use App\Service\Push\PushSubscriptionRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Per-account delivery mode toggle: push or scheduled polling.
 *
 * Provider-agnostic — the registry resolves Gmail (users.watch + Cloud Pub/Sub)
 * or Microsoft (Graph subscriptions). IMAP accounts have no push manager and
 * never render the control.
 *
 * Push needs the instance to be reachable from the internet, which for most
 * self-hosted deployments means a correctly configured reverse proxy. When
 * registration fails, the flag is rolled back so the UI never claims push is on
 * while nothing is being delivered.
 *
 * Turbo-native: the toggle re-renders its own frame, matching the existing
 * enable/disable control in the accounts pane.
 */
#[IsGranted('ROLE_USER')]
final class AccountPushController extends AbstractController
{
    public function __construct(
        private readonly PushSubscriptionRegistry $registry,
        private readonly EntityManagerInterface   $em,
    ) {}

    #[Route('/settings/accounts/{id}/push', name: 'settings_account_push_toggle', methods: ['POST'])]
    public function toggle(Request $request, Account $account): Response
    {
        $this->assertOwnership($account);

        $token = (string) $request->request->get('_token');

        if (false === $this->isCsrfTokenValid('account_push_' . $account->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }

        $manager = $this->registry->resolve($account);

        if (null === $manager) {
            throw $this->createNotFoundException('This account provider does not support push delivery.');
        }

        $failed = false;

        if (false === $account->isPushEnabled()) {
            // subscribe() reads the flag, so it has to be set and flushed first.
            $account->setPushEnabled(true);
            $this->em->flush();

            if (false === $manager->subscribe($account)) {
                $account->setPushEnabled(false);
                $this->em->flush();

                $failed = true;
            }
        } else {
            $manager->unsubscribe($account);

            $account->setPushEnabled(false);
            $this->em->flush();
        }

        return $this->render('settings/accounts/_push_toggle.html.twig', [
            'account'      => $account,
            'health'       => $manager->health($account),
            'expiresAt'    => $manager->expiresAt($account),
            'isConfigured' => $manager->isConfigured(),
            'failed'       => $failed,
        ]);
    }

    /**
     * Re-register push without toggling — the user-facing counterpart to
     * `app:push:renew --repair`, for the Degraded state where the toggle looks
     * on but nothing is arriving.
     */
    #[Route('/settings/accounts/{id}/push/repair', name: 'settings_account_push_repair', methods: ['POST'])]
    public function repair(Request $request, Account $account): Response
    {
        $this->assertOwnership($account);

        $token = (string) $request->request->get('_token');

        if (false === $this->isCsrfTokenValid('account_push_' . $account->getId(), $token)) {
            throw $this->createAccessDeniedException();
        }

        $manager = $this->registry->resolve($account);

        if (null === $manager) {
            throw $this->createNotFoundException('This account provider does not support push delivery.');
        }

        $failed = false === $manager->renew($account);

        return $this->render('settings/accounts/_push_toggle.html.twig', [
            'account'      => $account,
            'health'       => $manager->health($account),
            'expiresAt'    => $manager->expiresAt($account),
            'isConfigured' => $manager->isConfigured(),
            'failed'       => $failed,
        ]);
    }

    private function assertOwnership(Account $account): void
    {
        if ($account->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }
}
