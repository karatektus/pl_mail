<?php

declare(strict_types=1);

namespace App\Tests\Service\Graph;

use App\Domain\Enum\Account\AuthType;
use App\Entity\Mail\Account;
use App\Service\Mail\GraphApiClient;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What a folder's delta is asked to carry.
 *
 * A message delta reports a change only in the properties it was asked for,
 * and the $select it begins with is the chain's for good. Asked for
 * `id,internetMessageId,parentFolderId`, Exchange did not merely leave isRead
 * out of the entries it sent: it sent no entry at all for a message whose only
 * change was being read or unread. Mail read in Outlook or on a phone never
 * reached plMail, and GraphInboundReadStateTest passed throughout, because it
 * puts `isRead` into the delta it hands the syncer — a delta the real server
 * could not have produced.
 *
 * So this pins the request, which is the one place the fixture cannot lie.
 */
final class GraphDeltaSelectTest extends KernelTestCase
{
    private const string FOLDER = 'AAMkAD-inbox';

    /** What the entries of a chain have to carry for a read or a star to be heard. */
    private const array MUST_CARRY = ['isRead', 'flag'];

    /** And what the syncer has always needed, so a rewrite of the list cannot drop it. */
    private const array ALWAYS_CARRIED = ['id', 'internetMessageId', 'parentFolderId'];

    public function testANewDeltaChainAsksForReadAndFlagState(): void
    {
        $select = $this->selectOf(fn (GraphApiClient $client, Account $account) => $client->deltaMessages($account, self::FOLDER, null));

        foreach (self::MUST_CARRY as $property) {
            self::assertContains($property, $select, sprintf('a delta that does not select %s is never told when it changes', $property));
        }

        foreach (self::ALWAYS_CARRIED as $property) {
            self::assertContains($property, $select);
        }
    }

    public function testAFoldersImportAsksForTheSameThing(): void
    {
        // The import's enumeration ends by handing the folder its delta link,
        // so what it selects is what the folder is followed with afterwards.
        $select = $this->selectOf(fn (GraphApiClient $client, Account $account) => $client->deltaMessagesPage($account, self::FOLDER, null, 50));

        foreach (self::MUST_CARRY as $property) {
            self::assertContains($property, $select, sprintf('a delta that does not select %s is never told when it changes', $property));
        }

        foreach (self::ALWAYS_CARRIED as $property) {
            self::assertContains($property, $select);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * The properties named in the $select of the request the call makes.
     *
     * @param callable(GraphApiClient, Account): mixed $call
     *
     * @return list<string>
     */
    private function selectOf(callable $call): array
    {
        $urls = [];

        $http = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse(
                json_encode(['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta?$deltatoken=t'], JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $client = new GraphApiClient($http, self::getContainer()->get('App\Service\OAuth\OAuthTokenManager'));

        $call($client, $this->account());

        self::assertCount(1, $urls, 'one request, to begin the chain');

        parse_str((string) parse_url($urls[0], PHP_URL_QUERY), $query);

        self::assertArrayHasKey('$select', $query, 'the chain was begun without a $select, so it carries whatever Exchange defaults to');

        return array_map('trim', explode(',', (string) $query['$select']));
    }

    /** Not stored: the token is current, so nothing asks the database. */
    private function account(): Account
    {
        $account                   = new Account();
        $account->name             = 'Graph fixture';
        $account->email            = 'graph@example.test';
        $account->username         = 'graph-select';
        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = 'microsoft';
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $account->isActive         = true;

        return $account;
    }
}
