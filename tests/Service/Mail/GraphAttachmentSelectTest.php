<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Account\AuthType;
use App\Entity\Mail\Account;
use App\Service\Mail\GraphApiClient;
use App\Service\OAuth\OAuthTokenManager;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The attachment listing that answered "none" for every message that had one.
 *
 * contentId is declared on Graph's fileAttachment type, not on the attachment
 * the collection is typed as. Named unqualified, the $select is rejected as a
 * whole — "Could not find a property named 'contentId' on type
 * 'microsoft.graph.attachment'" — and in a $batch that arrives as a 400 on the
 * sub-request. The caller skips a non-2xx sub-request, so the message was
 * stored with no attachment and nothing in the log. Every Outlook message with
 * a file on it went that way.
 *
 * contentId cannot be dropped to make the 400 go away: it is what separates an
 * image the body refers to with cid: from a file the user attached.
 */
final class GraphAttachmentSelectTest extends TestCase
{
    private const string CAST = 'microsoft.graph.fileAttachment/contentId';

    /** @var list<string> the $select of every attachment request, in order */
    private array $selects = [];

    protected function setUp(): void
    {
        $this->selects = [];
    }

    public function testTheBatchedListingAsksForContentIdThroughItsOwnType(): void
    {
        $client = $this->client([$this->batch([$this->listing('0', [['id' => 'att-1', 'contentId' => 'logo@x']])])]);

        $client->batchListAttachments($this->account(), ['AAA']);

        self::assertCount(1, $this->selects);
        self::assertStringContainsString(self::CAST, $this->selects[0]);
        self::assertStringNotContainsString(
            'contentId',
            str_replace(self::CAST, '', $this->selects[0]),
            'and never as a bare property, which Graph rejects',
        );
    }

    public function testTheSingleMessageListingDoesTheSame(): void
    {
        $client = $this->client([$this->json(['value' => [['id' => 'att-1']]])]);

        $client->listAttachments($this->account(), 'AAA');

        self::assertCount(1, $this->selects);
        self::assertStringContainsString(self::CAST, $this->selects[0]);
        self::assertStringNotContainsString(
            'contentId',
            str_replace(self::CAST, '', $this->selects[0]),
        );
    }

    /**
     * The other half: what comes back is handed on whole, contentId included,
     * because GraphMessageBuilder reads it to decide what is inline.
     */
    public function testWhatGraphListsIsReturnedKeyedByMessage(): void
    {
        $client = $this->client([$this->batch([
            $this->listing('0', [['id' => 'att-1', 'name' => 'a.zip', 'contentId' => 'cid-1@x']]),
            $this->listing('1', [['id' => 'att-2', 'name' => 'b.pdf', 'contentId' => 'cid-2@x']]),
        ])]);

        $listed = $client->batchListAttachments($this->account(), ['AAA', 'BBB']);

        self::assertSame('cid-1@x', $listed['AAA'][0]['contentId']);
        self::assertSame('b.pdf', $listed['BBB'][0]['name']);
    }

    /**
     * A sub-request Graph refuses leaves its message out of the result. The
     * sync handler warns about exactly that gap, so this is the contract it
     * leans on: absent, not an empty list that reads as "no attachments".
     */
    public function testAMessageWhoseListingFailedIsAbsentRatherThanEmpty(): void
    {
        $client = $this->client([$this->batch([
            [
                'id'     => '0',
                'status' => 400,
                'body'   => ['error' => ['code' => 'BadRequest', 'message' => 'Parsing OData Select and Expand failed.']],
            ],
            $this->listing('1', [['id' => 'att-2', 'name' => 'b.pdf']]),
        ])]);

        $listed = $client->batchListAttachments($this->account(), ['AAA', 'BBB']);

        self::assertArrayNotHasKey('AAA', $listed);
        self::assertArrayHasKey('BBB', $listed, 'while a neighbour that listed fine is kept');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** @param list<MockResponse> $responses */
    private function client(array $responses): GraphApiClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): ResponseInterface {
            $body = json_decode((string) ($options['body'] ?? '{}'), true);

            // $batch: the select is inside each sub-request's own url. Plain
            // GET: it is on the request's.
            $urls = [] === ($body['requests'] ?? [])
                ? [$url]
                : array_map(static fn (array $request): string => (string) $request['url'], $body['requests']);

            foreach ($urls as $each) {
                parse_str((string) (parse_url($each, PHP_URL_QUERY) ?? ''), $parsed);

                $this->selects[] = (string) ($parsed['$select'] ?? '');
            }

            return array_shift($responses) ?? new MockResponse('{}');
        });

        return new GraphApiClient($http, $this->createStub(OAuthTokenManager::class));
    }

    /** @param list<array<string,mixed>> $subResponses */
    private function batch(array $subResponses): MockResponse
    {
        return $this->json(['responses' => $subResponses]);
    }

    /**
     * @param list<array<string,mixed>> $attachments
     *
     * @return array<string,mixed>
     */
    private function listing(string $index, array $attachments): array
    {
        return ['id' => $index, 'status' => 200, 'body' => ['value' => $attachments]];
    }

    /** @param array<string,mixed> $body */
    private function json(array $body): MockResponse
    {
        return new MockResponse(
            json_encode($body, JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    private function account(): Account
    {
        $account                   = new Account();
        $account->email            = 'graph@example.test';
        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = 'microsoft';
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new DateTimeImmutable('+1 day');

        return $account;
    }
}
