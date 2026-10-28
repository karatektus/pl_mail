<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Helper\MailServerHost;
use App\Entity\Mail\Account;
use App\Infrastructure\ImapClient\ImapAuthMechanism;
use App\Infrastructure\ImapClient\ImapConnection;
use App\Infrastructure\ImapClient\ImapCredentials;
use App\Infrastructure\ImapClient\ImapEndpoint;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Infrastructure\ImapClient\ImapSecurity;
use App\Service\OAuth\OAuthTokenManager;

/**
 * Opens a logged-in connection on plMail's own IMAP client for an account.
 *
 * The counterpart of ImapConnectionFactory, which does the same for webklex,
 * and it turns away the same accounts for the same reasons. The two live side
 * by side while the client replaces the library one caller at a time; IDLE is
 * the first caller.
 *
 * The one step here that can block the loop is an OAuth token refresh, an
 * HTTP request to Google or Microsoft with its own timeout. That is not the
 * IMAP server, which is the one thing that must never hold up other accounts.
 */
final readonly class ImapConnector
{
    public function __construct(
        private OAuthTokenManager $tokenManager,
    ) {
    }

    public function endpoint(Account $account): ImapEndpoint
    {
        if (true === $account->isPop3() || true === $account->isJmap()) {
            throw new \LogicException(sprintf(
                'Account %d (%s) does not read its mail over IMAP.',
                $account->id,
                $account->email,
            ));
        }

        $host = $account->imapHost;

        if (null === $host || '' === $host) {
            throw new \RuntimeException(sprintf(
                'Account %d (%s) has no IMAP host — it is API-synced and must not be opened over IMAP.',
                $account->id,
                $account->email,
            ));
        }

        if (false === MailServerHost::isValid($host)) {
            throw new \InvalidArgumentException('The IMAP host is not a valid hostname or IP address.');
        }

        $security = ImapSecurity::fromAccountSetting($account->imapEncryption);

        return new ImapEndpoint($host, $account->imapPort ?? $security->defaultPort(), $security);
    }

    public function credentials(Account $account): ImapCredentials
    {
        $username = (string) $account->username;

        if (AuthType::OAuth2->value === $account->authType) {
            return new ImapCredentials($username, $this->tokenManager->getValidAccessToken($account), ImapAuthMechanism::XOAuth2);
        }

        return new ImapCredentials($username, (string) $account->password);
    }

    /**
     * Connect and log in. The connection is closed again if the login fails.
     */
    public function open(ImapLoop $loop, Account $account, float $timeoutSeconds = ImapConnection::DEFAULT_TIMEOUT_SECONDS): ImapConnection
    {
        $endpoint    = $this->endpoint($account);
        $credentials = $this->credentials($account);
        $connection  = ImapConnection::open($loop, $endpoint, $timeoutSeconds);

        try {
            $connection->authenticate($credentials);
        } catch (\Throwable $e) {
            $connection->close();

            throw $e;
        }

        return $connection;
    }
}
