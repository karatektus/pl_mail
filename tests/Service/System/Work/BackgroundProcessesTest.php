<?php

declare(strict_types=1);

namespace App\Tests\Service\System\Work;

use App\Service\System\Work\BackgroundProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The worker container's processes keep the names the containers had, because
 * the heartbeats, /healthz and the admin log filters all go by them; and the
 * hub is configured the way the hub container was.
 */
final class BackgroundProcessesTest extends TestCase
{
    private const string IDENTIFIER = 'https://plmail.invalid/.well-known/mercure';

    public function testEveryProcessKeepsTheNameItsContainerHad(): void
    {
        self::assertSame(
            ['mercure', 'imap-supervisor', 'worker-export', 'worker-ingest', 'worker-backlog', 'worker-live', 'worker-release', 'worker-enrich', 'worker-maintenance', 'worker-bulk', 'scheduler'],
            new BackgroundProcesses(self::IDENTIFIER)->names(),
        );
    }

    public function testTheHubIsConfiguredAsItsContainerWas(): void
    {
        $hub = new BackgroundProcesses(self::IDENTIFIER, 'mercure_access_token', 'the-generated-secret')->all()[0];

        self::assertSame(':80', $hub->env['SERVER_NAME'], 'never the web server\'s hostname: that would send the hub after a certificate');
        self::assertSame('the-generated-secret', $hub->env['MERCURE_PUBLISHER_JWT_KEY']);
        self::assertSame('the-generated-secret', $hub->env['MERCURE_SUBSCRIBER_JWT_KEY']);
        self::assertSame(self::IDENTIFIER, $hub->env['MERCURE_TRUSTED_ISSUERS']);
        self::assertSame(
            "resource_identifier " . self::IDENTIFIER . "\ncookie_name mercure_access_token",
            $hub->env['MERCURE_EXTRA_DIRECTIVES'],
            'the audience pinned, and the cookie the application sets: one MERCURE_COOKIE_NAME for both halves',
        );

        $overridden = new BackgroundProcesses(self::IDENTIFIER, 'c', 's', 'https://issuer.example', 'cookie_name plmail')->all()[0];

        self::assertSame('https://issuer.example', $overridden->env['MERCURE_TRUSTED_ISSUERS'], 'what an operator sets wins');
        self::assertSame('cookie_name plmail', $overridden->env['MERCURE_EXTRA_DIRECTIVES']);
    }

    /**
     * The hub never hears about the `__Secure-` prefix.
     *
     * Whether the browser's cookie carries it follows the public address, and
     * that can change while the hub is running. A hub told the prefixed name at
     * its own start refused every subscription after the address moved to
     * http, until somebody restarted the worker too. The web server strips the
     * prefix in the proxy instead, so this side holds still.
     */
    public function testTheHubReadsTheBareCookieWhateverTheBrowserIsHanded(): void
    {
        $hub = new BackgroundProcesses(self::IDENTIFIER, '__Secure-mercure_access_token', 's')->all()[0];

        self::assertStringEndsWith("\ncookie_name mercure_access_token", $hub->env['MERCURE_EXTRA_DIRECTIVES']);

        $own = new BackgroundProcesses(self::IDENTIFIER, 'plmail', 's')->all()[0];

        self::assertStringEndsWith("\ncookie_name plmail", $own->env['MERCURE_EXTRA_DIRECTIVES'], 'an operator\'s own name is used as it is');
    }
}
