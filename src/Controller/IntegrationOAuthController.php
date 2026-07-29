<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Enum\Integration\AuthKind;
use App\Domain\Enum\Integration\Provider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The authorise/return pair for OAuth-based integration providers.
 *
 * Exists ahead of any OAuth driver on purpose. Every provider's setup tutorial
 * has to tell the admin a redirect URI to paste into Google Cloud Console or
 * the Azure portal, and that URI has to be generated from a real route or it
 * will be wrong the first time someone relies on it. The route also has to be
 * stable: a redirect URI registered at a provider cannot be changed later
 * without breaking every existing connection.
 *
 * Separate from OAuthController, which owns mail-account OAuth: the two flows
 * share a shape but not a callback. Merging them would mean one callback
 * guessing from session state whether it was asked for a mailbox or a file
 * store.
 *
 * Until a driver exists, connecting says so plainly rather than starting a
 * flow that cannot finish.
 */
#[Route('/integrations/oauth', name: 'app_integration_oauth_')]
#[IsGranted('ROLE_USER')]
final class IntegrationOAuthController extends AbstractController
{
    #[Route('/{provider}/connect', name: 'connect', methods: ['GET'])]
    public function connect(Provider $provider): RedirectResponse
    {
        return $this->notYet($provider);
    }

    #[Route('/{provider}/callback', name: 'callback', methods: ['GET'])]
    public function callback(Provider $provider, Request $request): RedirectResponse
    {
        return $this->notYet($provider);
    }

    private function notYet(Provider $provider): RedirectResponse
    {
        $this->addFlash('error', AuthKind::OAuth2 === $provider->authKind()
            ? 'settings.integrations.not_implemented'
            : 'settings.integrations.not_oauth');

        return $this->redirectToRoute('app_settings_index', ['section' => 'integrations']);
    }
}
