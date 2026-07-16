<?php

namespace App\Domain\Helper;

use App\Entity\Account;
use App\Enum\AuthType;
use App\Service\OAuth\OAuthTokenManager;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;

/**
 * Builds and opens a Webklex IMAP client for an account.
 *
 * Now a service (was static) because OAuth accounts need a freshly refreshed
 * access token, which requires the token manager. Password accounts behave
 * exactly as before.
 */
class ImapConnectionFactory
{
    public function __construct(
        private readonly OAuthTokenManager $tokenManager,
    ) {
    }

    public function connect(Account $account): Client
    {
        $encryption = match ($account->getImapEncryption()) {
            'ssl'      => 'ssl',
            'tls'      => 'tls',
            'starttls' => 'starttls',
            default    => false,
        };

        $accountConfig = [
            'host'          => $account->getImapHost(),
            'port'          => $account->getImapPort(),
            'encryption'    => $encryption,
            'validate_cert' => true,
            'username'      => $account->getUsername(),
            'protocol'      => 'imap',
        ];

        if (AuthType::OAuth2->value === $account->getAuthType()) {
            $accountConfig['password']       = $this->tokenManager->getValidAccessToken($account);
            $accountConfig['authentication'] = 'oauth';
        } else {
            $accountConfig['password']       = $account->getPassword();
            $accountConfig['authentication'] = null;
        }

        $client = new Client(Config::make([
            'default'  => 'default',
            'accounts' => [
                'default' => $accountConfig,
            ],
        ]));

        $client->connect();

        return $client;
    }
}
