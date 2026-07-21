<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Domain\Enum\MailProvider;
use App\Entity\Account;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\GuzzleException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Throwable;

/**
 * Single source of a valid access token for an OAuth account.
 *
 * Both the IMAP factory (reading) and the Gmail API sender (sending) call
 * getValidAccessToken() and never touch the refresh flow themselves.
 */
class OAuthTokenManager
{
    /**
     * Refresh this many seconds before the token actually expires, so a token
     * that is valid at check-time is still valid when the request lands.
     */
    private const EXPIRY_BUFFER_SECONDS = 120;

    public function __construct(
        private readonly OAuthProviderFactory   $providerFactory,
        private readonly EntityManagerInterface $em,
    )
    {
    }

    public function getValidAccessToken(Account $account): string
    {
        if (true === $this->isExpiring($account)) {
            return $this->refresh($account);
        }

        $token = $account->getOauthAccessToken();

        if (null === $token) {
            return $this->refresh($account);
        }

        return $token;
    }

    private function isExpiring(Account $account): bool
    {
        $expiry = $account->getOauthTokenExpiry();

        if (null === $expiry) {
            return true;
        }

        $threshold = new DateTimeImmutable(sprintf('+%d seconds', self::EXPIRY_BUFFER_SECONDS));

        if ($expiry <= $threshold) {
            return true;
        }

        return false;
    }

    /**
     * @throws Throwable
     * @throws GuzzleException
     * @throws IdentityProviderException
     */
    private function refresh(Account $account): string
    {
        $refreshToken = $account->getOauthRefreshToken();

        if (null === $refreshToken) {
            throw new \RuntimeException(sprintf(
                'Account %d has no refresh token; the account must be reconnected.',
                $account->getId(),
            ));
        }

        $provider = $this->providerFactory->create($this->providerFor($account));

        try {
            $newToken = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $refreshToken,
            ]);
        } catch (Throwable $e) {
            $account->setOauthLastRefreshError($e->getMessage());
            $this->em->flush();

            throw $e;
        }

        $account->setOauthAccessToken($newToken->getToken());

        $expires = $newToken->getExpires();
        if (null !== $expires) {
            $account->setOauthTokenExpiry(
                new DateTimeImmutable()->setTimestamp($expires),
            );
        }

        // Providers usually omit a fresh refresh token on refresh — keep the
        // existing one unless a new one is explicitly returned.
        $returnedRefresh = $newToken->getRefreshToken();
        if (null !== $returnedRefresh) {
            $account
                ->setOauthRefreshToken($returnedRefresh)
                ->setOauthLastRefreshAt(new DateTimeImmutable())
                ->setOauthLastRefreshError(null);;
        }

        $account->setUpdatedAt(new DateTimeImmutable());

        $this->em->flush();

        return $newToken->getToken();
    }

    private function providerFor(Account $account): MailProvider
    {
        $providerValue = $account->getOauthProvider();

        if (null === $providerValue) {
            throw new \RuntimeException(sprintf(
                'Account %d has no OAuth provider set.',
                $account->getId(),
            ));
        }

        $provider = MailProvider::tryFrom($providerValue);

        if (null === $provider) {
            throw new \RuntimeException(sprintf(
                'Account %d has unknown OAuth provider "%s".',
                $account->getId(),
                $providerValue,
            ));
        }

        return $provider;
    }
}
