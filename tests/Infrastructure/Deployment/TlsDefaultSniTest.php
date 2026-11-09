<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Deployment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Which certificate Caddy hands out when a TLS handshake names no host.
 *
 * Browsers send no server name to an IP address. With `SERVER_NAME=192.168.2.45`
 * Caddy issued a certificate for that address and then, told to default to
 * `localhost`, looked for one it never had and ended every handshake with an
 * internal-error alert, which no browser lets anyone click through (#485).
 * frankenphp/tls-default-sni.sh picks the default from SERVER_NAME instead;
 * these are the cases it must get right. It runs under `sh` as the image does,
 * so a bashism fails here rather than in a container.
 */
final class TlsDefaultSniTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../frankenphp/tls-default-sni.sh';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function serverNames(): iterable
    {
        // Everything that worked before must keep localhost.
        yield 'compose.yaml default'        => ['localhost, :443, php:80', 'localhost'];
        yield 'localhost alone'             => ['localhost, php:80', 'localhost'];
        yield 'localhost beside an IP'      => ['192.168.2.45, localhost', 'localhost'];
        yield 'localhost in capitals'       => ['10.0.0.5, LOCALHOST', 'localhost'];
        yield 'a real domain'               => ['mail.example.org, php:80', 'localhost'];
        yield 'plain HTTP behind a proxy'   => [':80', 'localhost'];
        yield 'unset'                       => ['', 'localhost'];

        // The #485 cases: the address's own certificate.
        yield 'the machine address'         => ['192.168.2.45, php:80', '192.168.2.45'];
        yield 'with scheme and port'        => ['https://192.168.2.45:443', '192.168.2.45'];
        yield 'space separated, IP second'  => ['nas.lan 10.0.0.5, php:80', '10.0.0.5'];
        yield 'first of two addresses'      => ['10.0.0.5, 10.0.0.6', '10.0.0.5'];
        yield 'IPv6 in brackets'            => ['[fd00::1]:443, php:80', 'fd00::1'];

        // Addresses that serve no TLS, and things that only look like one.
        yield 'an http:// address'          => ['http://10.0.0.5', 'localhost'];
        yield 'an address on port 80'       => ['10.0.0.5:80', 'localhost'];
        yield 'three dotted numbers'        => ['1.2.3', 'localhost'];
    }

    #[DataProvider('serverNames')]
    public function testItPicksTheCertificateABrowserCanBeGiven(string $serverName, string $expected): void
    {
        $process = new Process(['sh', self::SCRIPT, $serverName]);
        $process->mustRun();

        self::assertSame($expected, trim($process->getOutput()));
    }

    public function testTheCaddyfileDefaultsToWhatTheEntrypointExports(): void
    {
        $caddyfile  = (string) file_get_contents(__DIR__.'/../../../frankenphp/Caddyfile');
        $entrypoint = (string) file_get_contents(__DIR__.'/../../../frankenphp/docker-entrypoint.sh');

        self::assertStringContainsString('default_sni {$TLS_DEFAULT_SNI:localhost}', $caddyfile);
        self::assertStringContainsString('fallback_sni {$TLS_DEFAULT_SNI:localhost}', $caddyfile);
        self::assertStringContainsString('export TLS_DEFAULT_SNI="$(tls-default-sni ', $entrypoint);
    }
}
