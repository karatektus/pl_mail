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
use App\Infrastructure\ImapClient\ImapSession;
use App\Service\OAuth\OAuthTokenManager;

/**
 * Opens a logged-in connection on plMail's own IMAP client for an account.
 *
 * Every IMAP conversation starts here, so this is where the accounts that do
 * not speak IMAP are turned away: a POP3 account's server sits in the same
 * columns, and a JMAP account's host is a web server that would hang on an
 * IMAP greeting for as long as the timeout allowed.
 *
 * The one step here that can block the loop is an OAuth token refresh, an
 * HTTP request to Google or Microsoft with its own timeout. That is not the
 * IMAP server, which is the one thing that must never hold up other accounts.
 */
final readonly class ImapConnector
{
    public function __construct(
        private OAuthTokenManager $tokenManager,
        private ImapLoop          $loop,
        private ?ImapDeadline     $deadline = null,
    ) {
    }

    /**
     * A logged-in session for the ordinary, blocking callers: sync, import,
     * write-back. On the process's one loop, and watched by the handler
     * deadline (ImapDeadline), so a server that trickles a fetch for twenty
     * minutes is cut off at five, as it was before this client.
     */
    public function session(Account $account, float $timeoutSeconds = ImapConnection::DEFAULT_TIMEOUT_SECONDS): ImapSession
    {
        $connection = $this->open($this->loop, $account, $timeoutSeconds);

        $this->deadline?->watchSession($connection);

        return new ImapSession($connection);
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
