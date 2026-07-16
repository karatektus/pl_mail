<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Enum\MailProvider;
use App\Entity\Account;
use App\Entity\User;
use App\Enum\AuthType;
use App\Repository\AccountRepository;
use App\Service\OAuth\OAuthProviderFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/oauth', name: 'app_oauth_')]
class OAuthController extends AbstractController
{
    private const SESSION_STATE_KEY = 'oauth2_state';

    public function __construct(
        private readonly OAuthProviderFactory   $providerFactory,
        private readonly AccountRepository      $accountRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

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

    #[Route('/{provider}/callback', name: 'callback', methods: ['GET'])]
    public function callback(string $provider, Request $request): Response
    {
        $mailProvider = $this->resolveProvider($provider);

        $state         = $request->query->get('state');
        $expectedState = $request->getSession()->get(self::SESSION_STATE_KEY);
        $request->getSession()->remove(self::SESSION_STATE_KEY);

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
            throw $this->createAccessDeniedException('OAuth token exchange failed: ' . $e->getMessage());
        }

        $ownerData = $client->getResourceOwner($token)->toArray();
        $email     = $this->extractEmail($ownerData);

        if (null === $email) {
            throw $this->createAccessDeniedException('Could not determine the account email from the provider.');
        }

        $this->upsertAccount($mailProvider, $email, $token);

        return $this->redirectToRoute('app_default_index');
    }

    private function upsertAccount(MailProvider $provider, string $email, AccessTokenInterface $token): void
    {
        /** @var User $user */
        $user = $this->getUser();

        $account = $this->accountRepository->findOneBy([
            'usr'   => $user,
            'email' => $email,
        ]);

        if (null === $account) {
            $account = new Account();
            $account->setUsr($user);
            $account->setEmail($email);
            $account->setName($email);
            $account->setIsActive(true);
        }

        $account->setUsername($email);
        $account->setAuthType(AuthType::OAuth2->value);
        $account->setOauthProvider($provider->value);
        $account->setImapHost($provider->imapHost());
        $account->setImapPort($provider->imapPort());
        $account->setImapEncryption($provider->imapEncryption());

        // OAuth accounts never carry a password; sending goes over the provider API.
        $account->setPassword(null);

        $account->setOauthAccessToken($token->getToken());

        $refreshToken = $token->getRefreshToken();
        if (null !== $refreshToken) {
            $account->setOauthRefreshToken($refreshToken);
        }

        $expires = $token->getExpires();
        if (null !== $expires) {
            $account->setOauthTokenExpiry(new DateTimeImmutable()->setTimestamp($expires));
        }

        $account->setUpdatedAt(new DateTimeImmutable());

        $this->em->persist($account);
        $this->em->flush();
    }

    /**
     * Provider-agnostic email extraction. Google exposes "email"; Microsoft
     * exposes "mail" or falls back to "userPrincipalName".
     *
     * @param array<string, mixed> $ownerData
     */
    private function extractEmail(array $ownerData): ?string
    {
        $candidateKeys = ['email', 'mail', 'userPrincipalName'];

        foreach ($candidateKeys as $key) {
            if (array_key_exists($key, $ownerData) && is_string($ownerData[$key]) && '' !== $ownerData[$key]) {
                return $ownerData[$key];
            }
        }

        return null;
    }

    private function resolveProvider(string $provider): MailProvider
    {
        $mailProvider = MailProvider::tryFrom($provider);

        if (null === $mailProvider) {
            throw $this->createNotFoundException(sprintf('Unknown OAuth provider "%s".', $provider));
        }

        return $mailProvider;
    }
}
