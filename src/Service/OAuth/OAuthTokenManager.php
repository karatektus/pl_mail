<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Domain\Enum\Account\MailProvider;
use App\Domain\Exception\OAuthGrantRevokedException;
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
            // Nothing to refresh WITH, which is as permanent as a refusal —
            // and arrives for an account half-way through being connected, or
            // one whose provider never returned a refresh token because the
            // consent screen was skipped.
            throw new OAuthGrantRevokedException(sprintf(
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
            // Recorded before anything is decided about retrying: this is what
            // AccountHealthInspector reads to offer the Reconnect button, and
            // it has to be there whether the message is tried again or not.
            $account->oauthLastRefreshError = $e->getMessage();
            $this->em->flush();

            // A revoked grant answers identically every time, so it is raised
            // as something Messenger will not retry. Everything else — a
            // timeout, a 500, a DNS failure — stays exactly as it was and is
            // tried again; see OAuthGrantRevokedException on why that line is
            // drawn at the provider's error code and nowhere else.
            if (true === OAuthGrantRevokedException::isTerminal($e)) {
                throw new OAuthGrantRevokedException(
                    sprintf('The sign-in for account %d has been revoked or has expired.', $account->id),
                    0,
                    $e,
                );
            }

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
