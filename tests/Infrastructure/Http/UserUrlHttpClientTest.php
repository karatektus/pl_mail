<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Infrastructure\Http\ListedHosts;
use App\Infrastructure\Http\UserUrlHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * The client for user-supplied URLs re-checks every hop.
 *
 * The integration drivers used to follow up to twenty redirects unexamined, so
 * a "Nextcloud" answering 302 to an internal address was a request from inside
 * the container network. A trusted LAN host is still reachable directly.
 */
final class UserUrlHttpClientTest extends TestCase
{
    public function testARedirectIntoThePrivateNetworkIsRefused(): void
    {
        $inner = new MockHttpClient([
            // redirect_url by hand: a real transport derives it from Location,
            // MockHttpClient does not, and it is what the redirect logic reads.
            new MockResponse('', [
                'http_code'        => 302,
                'redirect_url'     => 'http://169.254.169.254/latest/meta-data',
                'response_headers' => ['Location: http://169.254.169.254/latest/meta-data'],
            ]),
            new MockResponse('secret'),
        ]);

        $client = new UserUrlHttpClient($inner, new ListedHosts(''));

        $this->expectException(TransportExceptionInterface::class);

        $client->request('GET', 'https://93.184.215.14/remote.php/dav')->getContent();
    }

    public function testATrustedLanHostIsReachable(): void
    {
        $client = new UserUrlHttpClient(new MockHttpClient(new MockResponse('ok')), new ListedHosts('10.0.0.5'));

        self::assertSame('ok', $client->request('GET', 'http://10.0.0.5:8080/status.php')->getContent());
    }

    public function testAnUntrustedPrivateHostIsRefused(): void
    {
        $client = new UserUrlHttpClient(new MockHttpClient(new MockResponse('ok')), new ListedHosts(''));

        $this->expectException(TransportExceptionInterface::class);

        $client->request('GET', 'http://10.0.0.5:8080/status.php')->getContent();
    }
}
