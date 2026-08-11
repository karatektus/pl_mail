<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\DomainHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The approximation, pinned.
 *
 * This is not a Public Suffix List and does not claim to be. What the tests
 * below fix is the DIRECTION it is allowed to be wrong in: an unknown
 * second-level suffix must produce a domain that compares equal to itself, so
 * the phishing heuristic falls silent rather than inventing a mismatch.
 */
final class DomainHelperTest extends TestCase
{
    #[DataProvider('registrableDomains')]
    public function testRegistrable(?string $host, ?string $expected): void
    {
        self::assertSame($expected, DomainHelper::registrable($host));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function registrableDomains(): iterable
    {
        yield 'a plain gTLD' => ['hetzner.com', 'hetzner.com'];
        yield 'a subdomain drops away' => ['mail.eu.hetzner.com', 'hetzner.com'];
        yield 'a ccTLD that sells at the second level' => ['bahn.de', 'bahn.de'];
        yield 'a subdomain under such a ccTLD' => ['www.bahn.de', 'bahn.de'];

        // The multi-label suffixes: two labels would name the registry itself.
        yield 'co.uk needs three labels' => ['news.bbc.co.uk', 'bbc.co.uk'];
        yield 'com.au needs three labels' => ['shop.example.com.au', 'example.com.au'];

        yield 'case is normalised' => ['HETZNER.COM', 'hetzner.com'];
        yield 'a trailing root dot is not a label' => ['hetzner.com.', 'hetzner.com'];

        // Null means "cannot compare", which every caller treats as "say
        // nothing" rather than as "no match".
        yield 'a bare label names no registrable domain' => ['localhost', null];
        yield 'an IPv4 literal names no organisation' => ['192.0.2.1', null];
        yield 'an IPv6 literal names no organisation' => ['::1', null];
        yield 'nothing' => ['', null];
        yield 'null' => [null, null];
    }

    /**
     * The direction the approximation is allowed to fail in: a suffix the list
     * does not know still yields a value that equals itself, so a comparison
     * between two addresses on the same host stays quiet.
     */
    public function testAnUnknownSecondLevelSuffixStillComparesEqualToItself(): void
    {
        $left  = DomainHelper::registrable('mail.example.co.zz');
        $right = DomainHelper::registrable('www.example.co.zz');

        self::assertSame($left, $right);
    }

    public function testRegistrableOfAddress(): void
    {
        self::assertSame('hetzner.com', DomainHelper::registrableOfAddress('support@hetzner.com'));
        self::assertSame('bbc.co.uk', DomainHelper::registrableOfAddress('news@mail.bbc.co.uk'));
        self::assertNull(DomainHelper::registrableOfAddress('not-an-address'));
        self::assertNull(DomainHelper::registrableOfAddress(null));
    }

    /**
     * An address whose local part contains an @ — legal when quoted — must be
     * split on the LAST one, or the domain is read out of the wrong half.
     */
    public function testTheDomainIsTakenFromTheLastAtSign(): void
    {
        self::assertSame('example.com', DomainHelper::registrableOfAddress('"odd@name"@example.com'));
    }
}
