<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Deployment;

use PHPUnit\Framework\TestCase;

/**
 * How the browser gets plMail's JavaScript: once, and only what a page uses.
 *
 * Measured on a NAS install, the inbox HTML was there at 0.6s and the page was
 * ready at 4.7s. In between the browser asked again for 195 of 199 modules,
 * because nothing told it it could keep them, and it loaded every Stimulus
 * controller of every screen — admin, calendar, settings — before the inbox
 * could start. These pin the two halves of the fix; the Caddy half was checked
 * against a running container (200 and 304 carry the year, a 404 does not).
 */
final class AssetDeliveryTest extends TestCase
{
    private const string CADDYFILE   = __DIR__.'/../../../frankenphp/Caddyfile';
    private const string CONTROLLERS = __DIR__.'/../../../assets/controllers';

    /**
     * Screens of their own, with nothing the mail list needs. Their
     * controllers load when an element of theirs appears.
     */
    private const array LAZY_SCREENS = [
        'admin', 'auth', 'calendar', 'contacts', 'onboarding', 'rules', 'settings', 'setup', 'whats_new',
    ];

    public function testCompiledAssetsAreKeptForAYear(): void
    {
        $caddyfile = (string) file_get_contents(self::CADDYFILE);

        self::assertMatchesRegularExpression(
            '/@fingerprinted \{\s*path \/assets\/\*\s*not path \*\.json\s*\}/',
            $caddyfile,
            'Only digested files may be immutable; AssetMapper writes its JSON manifests without a digest.',
        );
        self::assertMatchesRegularExpression(
            '/header @fingerprinted \{\s*Cache-Control "public, max-age=31536000, immutable"\s*match status 200 304\s*\}/',
            $caddyfile,
            'Without `match status`, a 404 from Symfony goes out with this header beside its own.',
        );
    }

    public function testControllersOfOtherScreensAreLazy(): void
    {
        $eager = [];

        foreach (self::LAZY_SCREENS as $screen) {
            $files = glob(self::CONTROLLERS.'/'.$screen.'/*_controller.js') ?: [];
            self::assertNotSame([], $files, sprintf('assets/controllers/%s/ has no controllers; update LAZY_SCREENS.', $screen));

            foreach ($files as $file) {
                $head = (string) file_get_contents($file, length: 200);

                if (1 !== preg_match("~^/\\* stimulusFetch: 'lazy' \\*/~", $head)) {
                    $eager[] = substr($file, strlen(self::CONTROLLERS) + 1);
                }
            }
        }

        self::assertSame([], $eager, "These load on every page; start each with /* stimulusFetch: 'lazy' */.");
    }
}
