<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Domain\Enum\Account\MailProvider;
use App\Entity\Mail\Account;
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

        $token = $account->oauthAccessToken;

        if (null === $token) {
            return $this->refresh($account);
        }

        return $token;
    }

    private function isExpiring(Account $account): bool
    {
        $expiry = $account->oauthTokenExpiry;

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
        $refreshToken = $account->oauthRefreshToken;

        if (null === $refreshToken) {
            throw new \RuntimeException(sprintf(
                'Account %d has no refresh token; the account must be reconnected.',
                $account->id,
            ));
        }

        $provider = $this->providerFactory->create($this->providerFor($account));

        try {
            $newToken = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $refreshToken,
            ]);
        } catch (Throwable $e) {
            $account->oauthLastRefreshError = $e->getMessage();
            $this->em->flush();

            throw $e;
        }

        $account->oauthAccessToken = $newToken->getToken();

        $expires = $newToken->getExpires();
        if (null !== $expires) {
            $account->oauthTokenExpiry = new DateTimeImmutable()->setTimestamp($expires);
        }

        $returnedRefresh = $newToken->getRefreshToken();
        if (null !== $returnedRefresh) {
            $account->oauthRefreshToken = $returnedRefresh;
            $account->oauthLastRefreshAt = new DateTimeImmutable();
            $account->oauthLastRefreshError = null;
        }

        $account->updatedAt = new DateTimeImmutable();

        $this->em->flush();

        return $newToken->getToken();
    }

    private function providerFor(Account $account): MailProvider
    {
        $providerValue = $account->oauthProvider;

        if (null === $providerValue) {
            throw new \RuntimeException(sprintf(
                'Account %d has no OAuth provider set.',
                $account->id,
            ));
        }

        $provider = MailProvider::tryFrom($providerValue);

        if (null === $provider) {
            throw new \RuntimeException(sprintf(
                'Account %d has unknown OAuth provider "%s".',
                $account->id,
                $providerValue,
            ));
        }

        return $provider;
    }
}
