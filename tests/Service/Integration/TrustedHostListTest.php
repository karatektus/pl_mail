<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Service\Integration\TrustedHostList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one spelling of an allowed host, shared by the admin form, the
 * validator and the backup.
 *
 * The validator looks a URL's host up by exactly this form, so a host stored
 * any other way is a row on the admin page that never matches: the server
 * stays refused and the administrator is told it is allowed.
 */
final class TrustedHostListTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spellings(): iterable
    {
        yield 'a bare name'                 => ['dav.home.example.com', 'dav.home.example.com'];
        yield 'upper case'                  => ['DAV.Home.Example.com', 'dav.home.example.com'];
        yield 'a single label'              => ['nextcloud', 'nextcloud'];
        yield 'surrounding spaces'          => ['  nas.lan ', 'nas.lan'];
        yield 'a trailing dot'              => ['nas.lan.', 'nas.lan'];
        yield 'an IPv4 address'             => ['192.168.1.20', '192.168.1.20'];
        yield 'an IPv6 address in brackets' => ['[FD00::5]', 'fd00::5'];
        // The address from the user's error, pasted whole.
        yield 'a whole https address'       => ['https://Dav.Home.Example.com:8443/dav.php/calendars/', 'dav.home.example.com'];
        yield 'a whole IPv6 address'        => ['http://[fd00::5]:8080/', 'fd00::5'];
    }

    #[DataProvider('spellings')]
    public function testEverySpellingOfAHostComesOutTheWayAUrlCarriesIt(string $typed, string $stored): void
    {
        self::assertSame($stored, TrustedHostList::normalise($typed));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notHosts(): iterable
    {
        yield 'empty'               => [''];
        yield 'blank'               => ['   '];
        yield 'a wildcard'          => ['*.lan'];
        yield 'a space inside'      => ['my nas'];
        yield 'a path without host' => ['/dav.php'];
        yield 'a scheme alone'      => ['https://'];
        yield 'an impossible quad'  => ['300.1.1.1'];
        yield 'an underscore'       => ['my_nas.lan'];
    }

    #[DataProvider('notHosts')]
    public function testTextThatNamesNoHostGivesNull(string $typed): void
    {
        self::assertNull(TrustedHostList::normalise($typed));
    }
}
