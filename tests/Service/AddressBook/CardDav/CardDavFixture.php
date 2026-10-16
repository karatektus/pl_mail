<?php

declare(strict_types=1);

namespace App\Tests\Service\AddressBook\CardDav;

use App\Domain\Enum\Integration\Provider;
use App\Entity\AddressBook\AddressBook;
use App\Entity\Integration\Integration;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Service\AddressBook\CardDav\CardDavDriver;
use App\Service\Calendar\Sync\CalDav\CalDavClient;
use App\Service\Calendar\Sync\CalDav\CalDavDiscovery;
use App\Service\Calendar\Sync\CalDav\MultiStatusParser;
use App\Service\Integration\IntegrationUrlValidator;
use App\Tests\Service\Calendar\Sync\CalDav\CalDavFixture;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The CardDAV driver over a scripted transport — CalDavFixture's counterpart,
 * reusing its response builders and request readers, because the transport
 * under both drivers is the same one.
 *
 * Every collaborator is real, the URL validator included.
 */
final class CardDavFixture
{
    public readonly CardDavDriver $driver;
    public readonly MockHttpClient $http;

    /** @var list<MockResponse> */
    public readonly array $responses;

    private readonly CalDavFixture $requests;

    public function __construct(MockResponse ...$responses)
    {
        $this->responses = array_values($responses);
        $this->http      = new MockHttpClient($this->responses);
        $this->requests  = new CalDavFixture(...$this->responses);

        $parser = new MultiStatusParser();
        $client = new CalDavClient($this->http, new IntegrationUrlValidator(), self::configs(), $parser);

        $this->driver = new CardDavDriver(
            $client,
            new CalDavDiscovery($client, $parser, new NullLogger()),
            $parser,
            new NullLogger(),
        );
    }

    public function method(int $index): string
    {
        return $this->requests->method($index);
    }

    public function url(int $index): string
    {
        return $this->requests->url($index);
    }

    public function body(int $index): string
    {
        return $this->requests->body($index);
    }

    public function header(int $index, string $name): ?string
    {
        return $this->requests->header($index, $name);
    }

    public function requestCount(): int
    {
        return $this->http->getRequestsCount();
    }

    // ── Entities ──────────────────────────────────────────────────────────────

    public static function integration(string $baseUrl = 'https://dav.example.com'): Integration
    {
        $integration           = new Integration(new User(), Provider::CardDav, 'Home server');
        $integration->baseUrl  = $baseUrl;
        $integration->username = 'alice';
        $integration->secret   = 'app-password';

        return $integration;
    }

    public static function book(string $remoteId = 'https://dav.example.com/dav/addressbooks/alice/contacts/'): AddressBook
    {
        $integration = self::integration();

        return new AddressBook($integration->usr, $integration, $remoteId, 'Contacts');
    }

    // ── XML ───────────────────────────────────────────────────────────────────

    public static function vcard(string $uid, string $name, string $email): string
    {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\n"
            . sprintf("UID:%s\r\nFN:%s\r\nEMAIL:%s\r\n", $uid, $name, $email)
            . "END:VCARD\r\n";
    }

    /**
     * One collection's PROPFIND answer, with or without sync-collection.
     */
    public static function collection(string $href, bool $syncCollection, ?string $ctag = null): MockResponse
    {
        $reports = true === $syncCollection
            ? '<d:supported-report-set><d:supported-report><d:report><d:sync-collection/></d:report></d:supported-report></d:supported-report-set>'
            : '<d:supported-report-set/>';
        $ctagXml = null === $ctag ? '' : sprintf('<cs:getctag>%s</cs:getctag>', $ctag);

        return CalDavFixture::multistatus(sprintf(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav" xmlns:cs="http://calendarserver.org/ns/">
              <d:response>
                <d:href>%s</d:href>
                <d:propstat>
                  <d:prop>
                    <d:resourcetype><d:collection/><card:addressbook/></d:resourcetype>
                    %s
                    %s
                  </d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
            </d:multistatus>
            XML, $href, $reports, $ctagXml));
    }

    /**
     * @param array<string, array{etag: string, vcard: string|null}|null> $members href => card, null for deleted
     */
    public static function report(array $members, ?string $syncToken = null): MockResponse
    {
        $responses = '';

        foreach ($members as $href => $member) {
            if (null === $member) {
                $responses .= sprintf('<d:response><d:href>%s</d:href><d:status>HTTP/1.1 404 Not Found</d:status></d:response>', $href);

                continue;
            }

            $data = null === $member['vcard']
                ? ''
                : sprintf('<card:address-data>%s</card:address-data>', htmlspecialchars($member['vcard'], ENT_XML1));

            $responses .= sprintf(
                '<d:response><d:href>%s</d:href><d:propstat><d:prop><d:getetag>%s</d:getetag>%s</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>',
                $href,
                htmlspecialchars($member['etag'], ENT_XML1),
                $data,
            );
        }

        $token = null === $syncToken ? '' : sprintf('<d:sync-token>%s</d:sync-token>', $syncToken);

        return CalDavFixture::multistatus(
            '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:multistatus xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            . $responses . $token
            . '</d:multistatus>',
        );
    }

    private static function configs(): IntegrationProviderConfigRepository
    {
        return new class extends IntegrationProviderConfigRepository {
            public function __construct()
            {
            }

            public function findOneByProvider(Provider $provider): ?IntegrationProviderConfig
            {
                return null;
            }
        };
    }
}
