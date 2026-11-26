<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\LocalRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #669: after signing in, a path on this site, never the host the proxy
 * forwarded — behind Authentik that host was the container's internal IP.
 */
final class LocalRedirectTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function targets(): iterable
    {
        yield 'the internal host is dropped'   => ['http://10.0.0.5:8080/mail/inbox?page=2#top', '/mail/inbox?page=2#top'];
        yield 'a path stays a path'            => ['/settings?section=accounts', '/settings?section=accounts'];
        yield 'nothing saved'                  => [null, '/'];
        yield 'empty'                          => ['', '/'];
        yield 'a host with no path is the root' => ['https://mail.example.test', '/'];
        yield 'protocol-relative keeps only its path' => ['//evil.example/x', '/x'];
        yield 'a doubled slash in a path falls back'  => ['https://mail.example.test//evil.example/x', '/'];
        yield 'backslash trick falls back'     => ['/\\evil.example', '/'];
        yield 'not a path at all'              => ['javascript:alert(1)', '/'];
    }

    #[DataProvider('targets')]
    public function testOnlyAPathOnThisSiteComesOut(?string $target, string $expected): void
    {
        self::assertSame($expected, LocalRedirect::path($target, '/'));
    }
}
