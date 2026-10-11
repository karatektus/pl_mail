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
    /**
     * What webklex puts in place of a Date header it cannot parse.
     *
     * Without a fallback it throws InvalidMessageDateException while building
     * the message, the fetch fails, and every sync of that folder fails at the
     * same message for ever. The epoch is chosen because nothing real is dated
     * 1970: MessageSyncer::dateFromHeader() reads it as "no usable date" and
     * dates the message by when it arrived here instead.
     */
    public const string UNPARSEABLE_DATE = '1970-01-01 00:00:00 UTC';

    /**
     * How the boundary of a multipart message is read out of its Content-Type.
     *
     * The library's own pattern is `/boundary=(.*?(?=;)|(.*))/i`, which wants
     * the equals sign hard against the word. RFC 2045 does not: a parameter is
     * tokens under RFC 822's lexical rules, and whitespace may stand between
     * them, so
     *
     *     Content-Type: multipart/mixed; boundary = "--=_Part_1"
     *
     * is a legal header that some mailers write. The library found no boundary
     * in it, declared the message to have "no content", and — because that is
     * thrown while a page of mail is being fetched — took the whole folder's
     * sync down with it, on every poll, until the message was deleted (#45).
     *
     * Whitespace is allowed on both sides of the sign. A quoted value is taken
     * whole, spaces and all, since quoting is how a boundary is allowed to
     * contain them; an unquoted one ends at the first semicolon or whitespace.
     * The branch reset `(?|…)` puts either form in group 1, which is the one
     * group Header::find() returns. Nothing trails the value: the obvious
     * smaller change — the library's pattern with `\s*` added — captures the
     * space before a following semicolon, and the library's clean-up does not
     * strip it, so the boundary would be found and then match no line.
     */
    public const string BOUNDARY_PATTERN = '/boundary\s*=\s*(?|"([^"]+)"|([^;\s]+))/i';

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

        // The library splices the host into a socket address; see MailServerHost.
        if (false === MailServerHost::isValid($host)) {
            throw new \InvalidArgumentException('The IMAP host is not a valid hostname or IP address.');
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

        $client = new Client(self::config($accountConfig));

        $client->connect();

        return $client;
    }

    /**
     * The library's configuration as every connection of this application
     * uses it: how mail is decoded and parsed, plus the one account.
     *
     * Public and static, and separate from connect(), so the parsing half can
     * be had without a server — a test that parses a message has to parse it
     * the way a sync would, and one that builds its own config is testing
     * the library's defaults instead.
     *
     * @param array<string, mixed> $account the account's connection settings;
     *                                      empty where nothing is being opened
     */
    public static function config(array $account = []): Config
    {
        return Config::make([
            'default'  => 'default',
            'accounts' => [
                'default' => $account,
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
            'options' => [
                'fallback_date' => self::UNPARSEABLE_DATE,
                'boundary'      => self::BOUNDARY_PATTERN,
            ],
        ]);
    }
}
