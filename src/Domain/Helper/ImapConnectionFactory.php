<?php

namespace App\Domain\Helper;

use App\Entity\Account;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Support\Masks\AttachmentMask;
use Webklex\PHPIMAP\Support\Masks\MessageMask;

class ImapConnectionFactory
{
    public static function connect(Account $account): Client
    {
        $encryption = match ($account->getImapEncryption()) {
            'ssl'      => 'ssl',
            'tls'      => 'tls',
            'starttls' => 'starttls',
            default    => false,
        };

        $client = new Client(Config::make([
            'default' => 'default',
            'accounts' => [
                'default' => [
                    'host'          => $account->getImapHost(),
                    'port'          => $account->getImapPort(),
                    'encryption'    => $encryption,
                    'validate_cert' => true,
                    'username'      => $account->getUsername(),
                    'password'      => $account->getPassword(),
                    'protocol'      => 'imap',
                ],
            ],
        ]));


        $client->connect();

        return $client;
    }
}
