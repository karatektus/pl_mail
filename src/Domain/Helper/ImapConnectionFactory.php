<?php

namespace App\Domain\Helper;

use App\Entity\Mail\Account;
use App\Domain\Enum\Account\AuthType;
use App\Infrastructure\Imap\Utf8AwareMessageDecoder;
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

    public function connect(Account $account, ?int $timeout = null): Client
    {
        $encryption = match ($account->imapEncryption) {
            'ssl'      => 'ssl',
            'tls'      => 'tls',
            'starttls' => 'starttls',
            default    => false,
        };

        $host = $account->imapHost;

        if (null === $host || '' === $host) {
            throw new \RuntimeException(sprintf(
                'Account %d (%s) has no IMAP host — it is API-synced and must not be opened over IMAP.',
                $account->id,
                $account->email,
            ));
        }
        $accountConfig = [
            'host'          => $host,
            'port'          => $account->imapPort,
            'encryption'    => $encryption,
            'validate_cert' => true,
            'username'      => $account->username,
            'protocol'      => 'imap',
        ];

        if (AuthType::OAuth2->value === $account->authType) {
            $accountConfig['password']       = $this->tokenManager->getValidAccessToken($account);
            $accountConfig['authentication'] = 'oauth';
        } else {
            $accountConfig['password']       = $account->password;
            $accountConfig['authentication'] = null;
        }

        if (null !== $timeout) {
            $accountConfig['timeout'] = $timeout;
        }

        $client = new Client(Config::make([
            'default'  => 'default',
            'accounts' => [
                'default' => $accountConfig,
            ],
            // The library converts every body part from whatever charset it
            // declares, which is wrong for the senders that declare
            // ISO-8859-1 and send UTF-8 — see Utf8AwareMessageDecoder.
            // Config::make merges into the vendor defaults, so the header and
            // attachment decoders stay as they were.
            'decoding' => [
                'decoder' => [
                    'message' => Utf8AwareMessageDecoder::class,
                ],
            ],
        ]));

        $client->connect();

        return $client;
    }
}
