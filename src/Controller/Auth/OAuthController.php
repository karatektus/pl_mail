<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Domain\Enum\Account\MailProvider;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Domain\Enum\Account\AuthType;
use App\Repository\Mail\AccountRepository;
use App\Service\Onboarding\OnboardingFlow;
use App\Service\Gmail\GmailWatchService;
use App\Service\Push\PushSubscriptionRegistry;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Service\OAuth\MicrosoftOAuthErrorTranslator;
use App\Service\OAuth\OAuthProviderFactory;
use App\Service\Graph\GraphSubscriptionManager;

#[IsGranted('ROLE_USER')]
#[Route('/oauth', name: 'app_oauth_')]
class OAuthController extends AbstractController
{
    private const string SESSION_STATE_KEY = 'oauth2_state';

    public function __construct(
        private readonly OAuthProviderFactory   $providerFactory,
        private readonly AccountRepository      $accountRepository,
        private readonly EntityManagerInterface $em,
        private readonly GraphSubscriptionManager $graphSubscriptionManager,
        private readonly PushSubscriptionRegistry $pushRegistry,
        private readonly \App\Service\Mail\AliasSeeder $aliasSeeder,
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

        $request->getSession()->set(self::SESSION_STATE_KEY, $client->getState());

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
        $expectedState = $request->getSession()->get(self::SESSION_STATE_KEY);
        $request->getSession()->remove(self::SESSION_STATE_KEY);
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
        $email     = $this->extractEmail($ownerData);

        if (null === $email) {
            throw $this->createAccessDeniedException(
                'Could not determine the account email from the provider.'
            );
        }

        $account = $this->upsertAccount($mailProvider, $email, $token);

        $this->registerPush($account);
        $this->aliasSeeder->seed($account);

        return $this->redirectToRoute($this->landingRoute());
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function upsertAccount(
        MailProvider       $provider,
        string             $email,
        AccessTokenInterface $token,
    ): Account {
        /** @var User $user */
        $user = $this->getUser();

        // Email alone does NOT identify an account: an OAuth provider's login
        // address is independent of where the mail is hosted, so the same
        // address can legitimately exist as both an IMAP account and an OAuth
        // one. Adopting by email would silently convert the IMAP account and
        // null its password. Match on the full identity instead.
        $account = $this->accountRepository->findOneBy([
            'usr'           => $user,
            'email'         => $email,
            'authType'      => AuthType::OAuth2->value,
            'oauthProvider' => $provider->value,
        ]);

        if (null === $account) {
            $duplicate = $this->accountRepository->count(['usr' => $user, 'email' => $email]) > 0;

            $account = new Account()
                ->setUsr($user)
                ->setEmail($email)
                ->setName($duplicate ? sprintf('%s (%s)', $email, ucfirst($provider->value)) : $email)
                ->setIsActive(true);
        }

        $account->setUsername($email);
        $account->setAuthType(AuthType::OAuth2->value);
        $account->setOauthProvider($provider->value);
        $account->setPassword(null);
        $account->setOauthAccessToken($token->getToken());

        $imapHost = $provider->imapHost();

        if (null !== $imapHost) {
            $account
                ->setImapHost($imapHost)
                ->setImapPort($provider->imapPort())
                ->setImapEncryption($provider->imapEncryption());
        }

        $refreshToken = $token->getRefreshToken();
        if (null !== $refreshToken) {
            $account->setOauthRefreshToken($refreshToken);
        }

        $expires = $token->getExpires();
        if (null !== $expires) {
            $account->setOauthTokenExpiry(
                new DateTimeImmutable()->setTimestamp($expires)
            );
        }

        $account->setUpdatedAt(new DateTimeImmutable());

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    /**
     * Establish push for a freshly connected account.
     *
     * On by default at connect time, because that is the one moment we know the
     * token is fresh and the user is present. Failure is non-fatal: the account
     * falls back to scheduled polling and the settings pane shows it as such.
     */
    private function registerPush(Account $account): void
    {
        $manager = $this->pushRegistry->resolve($account);

        if (null === $manager) {
            return;
        }

        $account->setPushEnabled(true);
        $this->em->flush();

        if (false === $manager->subscribe($account)) {
            $account->setPushEnabled(false);
            $this->em->flush();
        }
    }
    /**
     * Resolve the mailbox address from the provider's resource-owner payload.
     *
     * Order matters. For Microsoft the Azure resource owner merges the id_token
     * claims with the Graph /me response: the OIDC `email` claim is the *sign-in*
     * identity , while Graph `mail` is the actual mailbox
     * SMTP address — the one that matches synced
     * messages' to_address. We want the mailbox, so `mail` is tried first.
     * `userPrincipalName` stays last for org accounts exposing no distinct `mail`.
     * Google has no `mail` key, so it falls through to `email` unchanged.
     *
     * @param array<string,mixed> $ownerData
     */
    private function extractEmail(array $ownerData): ?string
    {
        foreach (['mail', 'email', 'userPrincipalName'] as $key) {
            if (
                true === array_key_exists($key, $ownerData)
                && true === is_string($ownerData[$key])
                && '' !== $ownerData[$key]
            ) {
                return $ownerData[$key];
            }
        }

        return null;
    }

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
