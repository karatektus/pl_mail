<?php

declare(strict_types=1);

namespace App\Controller\Integration;

use App\Domain\Enum\Integration\AuthKind;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration;
use App\Entity\User;
use App\Repository\IntegrationProviderConfigRepository;
use App\Repository\IntegrationRepository;
use App\Service\Integration\IntegrationOAuthProviderFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The authorise/return pair for OAuth-based integration providers.
 *
 * Separate from App\Controller\OAuthController, which owns mail-account OAuth: the two share a
 * shape but not a callback, and merging them would leave one callback guessing
 * from session state whether it had been asked for a mailbox or a file store.
 * They also do different things on return — this one never touches accounts,
 * mailbox sync or push subscriptions.
 *
 * The route is registered with each provider, so it has to be stable: changing
 * it later breaks every connection already authorised against it. That is why
 * the admin form renders this URL for copying rather than asking anyone to
 * type it, and why the route existed before any driver did.
 */
#[Route('/integrations/oauth', name: 'app_integration_oauth_')]
#[IsGranted('ROLE_USER')]
final class OAuthController extends AbstractController
{
    private const string SESSION_STATE_KEY = 'integration_oauth_state';
    private const string SESSION_PROVIDER_KEY = 'integration_oauth_provider';

    public function __construct(
        private readonly IntegrationOAuthProviderFactory     $providerFactory,
        private readonly IntegrationRepository               $integrationRepository,
        private readonly IntegrationProviderConfigRepository $configRepository,
        private readonly EntityManagerInterface              $em,
    ) {
    }

    #[Route('/{provider}/connect', name: 'connect', methods: ['GET'])]
    public function connect(Provider $provider, Request $request): RedirectResponse
    {
        if (AuthKind::OAuth2 !== $provider->authKind()) {
            return $this->back('settings.integrations.not_oauth', true);
        }

        $config = $this->configRepository->findOneByProvider($provider);

        if (null === $config || false === $config->isConnectable()) {
            return $this->back('settings.integrations.ask_admin', true);
        }

        try {
            $client = $this->providerFactory->create($provider);
        } catch (IntegrationException $e) {
            return $this->raw($e->getMessage());
        }

        $authUrl = $client->getAuthorizationUrl(
            ['scope' => $provider->scopes()] + $provider->authorizationUrlOptions(),
        );

        // State is bound to the session, and the provider is stored beside it:
        // the callback route carries a provider in its path, and without this
        // a state minted for one provider could be replayed against another.
        $session = $request->getSession();
        $session->set(self::SESSION_STATE_KEY, $client->getState());
        $session->set(self::SESSION_PROVIDER_KEY, $provider->value);

        return new RedirectResponse($authUrl);
    }

    #[Route('/{provider}/callback', name: 'callback', methods: ['GET'])]
    public function callback(Provider $provider, Request $request): RedirectResponse
    {
        $session = $request->getSession();
        $expectedState = $session->get(self::SESSION_STATE_KEY);
        $expectedProvider = $session->get(self::SESSION_PROVIDER_KEY);

        $session->remove(self::SESSION_STATE_KEY);
        $session->remove(self::SESSION_PROVIDER_KEY);

        // The provider may say no before we get anywhere — a declined consent
        // screen lands here with an error and no code.
        $error = $request->query->get('error_description') ?? $request->query->get('error');

        if (null !== $error) {
            return $this->raw((string) $error);
        }

        $state = $request->query->get('state');

        if (null === $expectedState || $state !== $expectedState || $expectedProvider !== $provider->value) {
            return $this->back('settings.integrations.oauth_state_mismatch', true);
        }

        $code = $request->query->get('code');

        if (false === is_string($code) || '' === $code) {
            return $this->back('settings.integrations.oauth_no_code', true);
        }

        try {
            $client = $this->providerFactory->create($provider);
            $token = $client->getAccessToken('authorization_code', ['code' => $code]);
        } catch (\Throwable $e) {
            return $this->raw($e->getMessage());
        }

        $this->store($provider, $token);

        return $this->back('settings.integrations.saved', false);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Find-or-create the connection and write the tokens onto it.
     *
     * Reconnecting updates the existing row rather than adding a second one:
     * a SaaS provider has exactly one account per user from our side, so a
     * repeat authorisation is a token refresh by another route — and creating a
     * duplicate would leave filters pointing at the stale one.
     */
    private function store(Provider $provider, AccessTokenInterface $token): void
    {
        $integration = $this->integrationRepository->findOneByProviderForUser($this->getUser(), $provider);

        if (null === $integration) {
            $integration = new Integration($this->user(), $provider, $provider->label());
            $this->em->persist($integration);
        }

        $integration->oauthAccessToken = $token->getToken();

        $expires = $token->getExpires();

        if (null !== $expires) {
            $integration->oauthTokenExpiry = new DateTimeImmutable()->setTimestamp($expires);
        }

        // Absent on a re-consent that Google decides not to re-issue for. The
        // stored one is still good, so it must not be cleared.
        $refresh = $token->getRefreshToken();

        if (null !== $refresh && '' !== $refresh) {
            $integration->oauthRefreshToken = $refresh;
        }

        $integration->isActive = true;
        $integration->recordSuccess();

        $this->em->flush();
    }

    private function back(string $message, bool $isError): RedirectResponse
    {
        $this->addFlash($isError ? 'error' : 'success', $message);

        return $this->redirectToRoute('app_settings_index', ['section' => 'integrations']);
    }

    /**
     * A message from the provider or the OAuth library, which is already
     * phrased for a person and must not be run through the translator.
     */
    private function raw(string $message): RedirectResponse
    {
        $this->addFlash('error', $message);

        return $this->redirectToRoute('app_settings_index', ['section' => 'integrations']);
    }

    private function user(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
