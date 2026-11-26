<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\SenderPicture\Lookup;

use App\Domain\Exception\SenderPictureUnavailableException;
use App\Service\Mail\SenderPicture\Lookup\HashedPictureLookup;
use App\Service\Mail\SenderPicture\SenderPictureHttp;
use App\Tests\Support\Mail\FakeDns;
use App\Tests\Support\Mail\SenderPictureFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Libravatar where the domain runs its own server, then Gravatar (#639).
 *
 * Pinned: the hash is SHA-256 (never MD5, which a lookup table reverses), a
 * domain's own Libravatar is asked first, a stranger's SRV record cannot
 * point the server at another port, and the three answers stay apart — a
 * picture, "none" (404), and "nobody answered" (5xx).
 */
final class HashedPictureLookupTest extends TestCase
{
    private const string ADDRESS = 'ada@example.org';

    public function testGravatarIsAskedBySha256WhenTheDomainRunsNoServer(): void
    {
        $requests = [];
        $picture  = $this->lookup([], [200], $requests)->lookup(self::ADDRESS);

        self::assertNotNull($picture);
        self::assertSame('gravatar', $picture->origin);
        self::assertCount(1, $requests);
        self::assertStringContainsString(hash('sha256', self::ADDRESS), $requests[0]);
        self::assertStringNotContainsString(md5(self::ADDRESS), $requests[0]);
        self::assertStringContainsString('d=404', $requests[0]);
    }

    public function testTheDomainsOwnLibravatarIsAskedFirst(): void
    {
        $requests = [];
        $srv      = ['_avatars-sec._tcp.example.org' => [['target' => 'avatars.example.org', 'port' => 443, 'priority' => 0, 'weight' => 0]]];
        $picture  = $this->lookup($srv, [200], $requests)->lookup(self::ADDRESS);

        self::assertNotNull($picture);
        self::assertSame('libravatar:avatars.example.org', $picture->origin);
        self::assertStringStartsWith('https://avatars.example.org/avatar/', $requests[0]);
    }

    public function testAnEmptyLibravatarFallsBackToGravatar(): void
    {
        $requests = [];
        $srv      = ['_avatars-sec._tcp.example.org' => [['target' => 'avatars.example.org', 'port' => 443, 'priority' => 0, 'weight' => 0]]];
        $picture  = $this->lookup($srv, [404, 200], $requests)->lookup(self::ADDRESS);

        self::assertSame('gravatar', $picture?->origin);
        self::assertCount(2, $requests);
    }

    public function testAnSrvRecordCannotChooseThePort(): void
    {
        $requests = [];
        $srv      = ['_avatars-sec._tcp.example.org' => [['target' => 'internal.example.org', 'port' => 6379, 'priority' => 0, 'weight' => 0]]];

        $this->lookup($srv, [200], $requests)->lookup(self::ADDRESS);

        self::assertStringStartsWith('https://gravatar.com/', $requests[0], 'a non-443 SRV target must be ignored, not connected to');
    }

    public function testNoPictureIsAnAnswerAndAnOutageIsNot(): void
    {
        $requests = [];

        self::assertNull($this->lookup([], [404], $requests)->lookup(self::ADDRESS));

        $this->expectException(SenderPictureUnavailableException::class);
        $this->lookup([], [503], $requests)->lookup(self::ADDRESS);
    }

    /**
     * @param array<string, list<array{target: string, port: int, priority: int, weight: int}>> $srv
     * @param list<int>    $statuses  one per request, in order
     * @param list<string> $requests  filled with every URL asked for
     */
    private function lookup(array $srv, array $statuses, array &$requests): HashedPictureLookup
    {
        $client = new MockHttpClient(static function (string $method, string $url) use (&$requests, &$statuses): MockResponse {
            $requests[] = $url;
            $status     = array_shift($statuses) ?? 404;

            return new MockResponse(200 === $status ? SenderPictureFixtures::png(80, 80) : '', [
                'http_code'        => $status,
                'response_headers' => ['content-type' => 'image/png'],
            ]);
        });

        return new HashedPictureLookup(new SenderPictureHttp($client, new NullLogger()), new FakeDns([], $srv));
    }
}
