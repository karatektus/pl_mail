<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Domain\Enum\Account\MailProvider;
use App\Entity\User\User;
use App\Service\Onboarding\OnboardingFlow;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Service\OAuth\MicrosoftOAuthErrorTranslator;
use App\Service\OAuth\OAuthAccountLinker;
use App\Service\OAuth\OAuthProviderFactory;
use App\Service\OAuth\OAuthStateStore;

#[IsGranted('ROLE_USER')]
#[Route('/oauth', name: 'app_oauth_')]
class OAuthController extends AbstractController
{
    private const string SESSION_NAMESPACE = 'oauth2';

    public function __construct(
        private readonly OAuthProviderFactory   $providerFactory,
        private readonly OAuthStateStore        $stateStore,
        private readonly OAuthAccountLinker     $accountLinker,
        private readonly OnboardingFlow $onboarding,
        private readonly MicrosoftOAuthErrorTranslator $microsoftErrorTranslator,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/{provider}/connect', name: 'connect', methods: ['GET'])]
    public function connect(string $provider, Request $request): RedirectResponse
    {
        $mailProvider = $this->resolveProvider($provider);
        $client       = $this->providerFactory->create($mailProvider);

        $options = array_merge(
            ['scope' => $mailProvider->scopes()],
            $mailProvider->authorizationUrlOptions(),
        );

        $authUrl = $client->getAuthorizationUrl($options);

        $this->stateStore->remember($request->getSession(), self::SESSION_NAMESPACE, $client->getState());

        return new RedirectResponse($authUrl);
    }

    /**
     * Where to land after the round trip through the provider.
     *
     * Settings, normally. But a user still in setup started this from the
     * wizard, and dropping them on the settings page means the wizard reopens
     * over a page they never asked for. The mail shell is where they were.
     */
    private function landingRoute(): string
    {
        $user = $this->getUser();

        if ($user instanceof User && true === $this->onboarding->isPending($user)) {
            return 'app_default_index';
        }

        return 'app_settings_index';
    }

    #[Route('/{provider}/callback', name: 'callback', methods: ['GET'])]
    public function callback(string $provider, Request $request): Response
    {
        $mailProvider = $this->resolveProvider($provider);

        $state         = $request->query->get('state');
        $expectedState = $this->stateStore->consume($request->getSession(), self::SESSION_NAMESPACE)['state'];
        $error = $request->query->get('error');

        if (null !== $error) {
            $description = (string) $request->query->get('error_description', '');
            $translated  = $this->microsoftErrorTranslator->translate($description);

            $this->logger->warning('OAuth callback returned an error', [
                'provider'    => $provider,
                'error'       => $error,
                'aadstsCode'  => $translated['code'],
                'description' => $description,
            ]);

            $this->addFlash('error', $this->translator->trans($translated['key']));

            return $this->redirectToRoute($this->landingRoute());
        }

        if (null === $state || $state !== $expectedState) {
            throw $this->createAccessDeniedException('Invalid OAuth state.');
        }

        $code = $request->query->get('code');

        if (null === $code) {
            throw $this->createAccessDeniedException('Missing authorization code.');
        }

        $client = $this->providerFactory->create($mailProvider);

        try {
            $token = $client->getAccessToken('authorization_code', ['code' => $code]);
        } catch (IdentityProviderException $e) {
            $raw        = $e->getMessage() . ' ' . json_encode($e->getResponseBody());
            $translated = $this->microsoftErrorTranslator->translate($raw);

            $this->logger->warning('OAuth token exchange failed', [
                'provider'   => $provider,
                'error'      => $e->getMessage(),
                'aadstsCode' => $translated['code'],
            ]);

            $this->addFlash('error', $this->translator->trans($translated['key']));

            return $this->redirectToRoute($this->landingRoute());
        }

        $ownerData = $client->getResourceOwner($token)->toArray();
        $email     = $this->accountLinker->mailboxAddress($ownerData);

        if (null === $email) {
            throw $this->createAccessDeniedException(
                'Could not determine the account email from the provider.'
            );
        }

        /** @var User $user */
        $user = $this->getUser();

        $this->accountLinker->link($user, $mailProvider, $email, $token);

        return $this->redirectToRoute($this->landingRoute());
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function resolveProvider(string $provider): MailProvider
    {
        $mailProvider = MailProvider::tryFrom($provider);

        if (null === $mailProvider) {
            throw $this->createNotFoundException(
                sprintf('Unknown OAuth provider "%s".', $provider)
            );
        }

        return $mailProvider;
    }
}
