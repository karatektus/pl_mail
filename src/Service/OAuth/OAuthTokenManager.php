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
            // A revoked grant answers identically every time, so it is raised
            // as something Messenger will not retry. Everything else — a
            // timeout, a 500, a DNS failure — stays exactly as it was and is
            // tried again; see OAuthGrantRevokedException on why that line is
            // drawn at the provider's error code and nowhere else.
            if (false === OAuthGrantRevokedException::isTerminal($e)) {
                throw $e;
            }

            // RECORDED ONLY WHEN THE GRANT IS ACTUALLY DEAD.
            //
            // This used to be written on every failed refresh, before anything
            // was decided about retrying — and the card it raises says "needs
            // you to sign in again", in red, at the top of the health page. One
            // timeout against the token endpoint was enough to say that about a
            // perfectly good account.
            //
            // Which would have been survivable if a later success undid it, and
            // it did not: the clear below sat inside `if (null !== $returnedRefresh)`,
            // and Google returns a refresh token on the initial authorization
            // only. So a single network blip marked an account as broken
            // permanently, and the one thing that cleared it was doing the
            // reconnect the card had wrongly demanded.
            $account->oauthLastRefreshError = mb_substr($e->getMessage(), 0, 500);
            $this->em->flush();

            throw new OAuthGrantRevokedException(
                sprintf('The sign-in for account %d has been revoked or has expired.', $account->id),
                0,
                $e,
            );
        }

        $account->oauthAccessToken = $newToken->getToken();

        // THE BACKFILL, and the only one there can be.
        //
        // What a provider granted cannot be recovered from a stored token — it
        // is in the response that delivered it, and that response is long gone
        // for every account connected before this was recorded. A refresh gets
        // a fresh one, and both providers return `scope` on it, so every
        // working account fills itself in on its next refresh: within the hour
        // for an active one, without a migration that would have to invent the
        // answer.
        //
        // An account whose refresh FAILS never gets here, and does not need to:
        // a dead grant already has its own card, and it outranks this one for
        // the good reason that a consent screen is unreachable until you can
        // sign in again.
        //
        // Absent means "unchanged from what was asked for" per OAuth 2.0, so a
        // response without it must leave the column alone rather than blank it
        // — writing null here would un-backfill an account on every refresh.
        $granted = $newToken->getValues()['scope'] ?? null;

        if (true === is_string($granted) && '' !== trim($granted)) {
            $account->oauthGrantedScopes = trim($granted);
        }

        $expires = $newToken->getExpires();
        if (null !== $expires) {
            $account->oauthTokenExpiry = new DateTimeImmutable()->setTimestamp($expires);
        }

        // Cleared on ANY successful refresh, and that is the fix rather than a
        // tidy-up: this sat inside the branch below, which only runs when the
        // provider hands back a NEW refresh token — something Google does on
        // the initial authorization and essentially never afterwards. An
        // account that recovered went on reporting itself broken for ever.
        $account->oauthLastRefreshAt    = new DateTimeImmutable();
        $account->oauthLastRefreshError = null;

        $returnedRefresh = $newToken->getRefreshToken();

        if (null !== $returnedRefresh) {
            $account->oauthRefreshToken = $returnedRefresh;
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
