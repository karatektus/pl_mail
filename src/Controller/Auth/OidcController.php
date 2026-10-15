<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\User\User;
use App\Security\OidcAuthenticator;
use App\Service\Oidc\OidcClient;
use App\Service\Oidc\OidcLoginFailed;
use App\Service\Oidc\OidcSettings;
use App\Service\Setup\InstallGuard;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * The two ends of a sign-in through the OpenID Connect provider.
 *
 * Both under /login so that the existing PUBLIC_ACCESS rule covers them: the
 * person arriving here has, by definition, no session to show.
 */
#[Route('/login/oidc', name: 'app_login_oidc')]
final class OidcController extends AbstractController
{
    public function __construct(
        private readonly OidcSettings $settings,
        private readonly OidcClient $client,
        private readonly InstallGuard $installGuard,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * A GET, and so reachable from any page on the internet — which is fine,
     * because all it does is start a sign-in the visitor then has to finish
     * at the provider themselves. The state minted here is what stops the
     * OTHER end from being forged.
     */
    #[Route('', name: '', methods: ['GET'])]
    public function start(Request $request): RedirectResponse
    {
        if (false === $this->settings->current()->isEnabled()) {
            throw $this->createNotFoundException();
        }

        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('app_default_index');
        }

        // An install with no users yet has to create its administrator first,
        // and the login page already sends visitors there. Without the same
        // check here, auto-creation would let the first person through the
        // provider make an ordinary account and close the install screen on
        // whoever was meant to be the administrator.
        if (true === $this->installGuard->isAvailable()) {
            return $this->redirectToRoute('app_install');
        }

        try {
            return new RedirectResponse($this->client->begin($request->getSession()));
        } catch (OidcLoginFailed $e) {
            $this->logger->warning('Single sign-on could not start: ' . $e->getMessage(), ['exception' => $e]);

            $request->getSession()->set(
                SecurityRequestAttributes::AUTHENTICATION_ERROR,
                new CustomUserMessageAuthenticationException($e->messageKey),
            );

            return $this->redirectToRoute('app_login');
        }
    }

    /**
     * Never runs: OidcAuthenticator claims the request first, and answers it
     * with a redirect either way. The route exists so there is a URL to
     * register at the provider and for the authenticator to recognise. When
     * single sign-on is off the authenticator declines, and this 404s.
     */
    #[Route('/callback', name: '_callback', methods: ['GET'])]
    public function callback(): never
    {
        if (false === $this->settings->current()->isEnabled()) {
            throw $this->createNotFoundException();
        }

        throw new LogicException(sprintf('%s should have handled this request.', OidcAuthenticator::class));
    }
}
