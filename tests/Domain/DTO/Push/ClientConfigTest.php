<?php

declare(strict_types=1);

namespace App\Tests\Domain\DTO\Push;

use App\Domain\DTO\Push\ClientConfig;
use PHPUnit\Framework\TestCase;

/**
 * Which Android client of a google-services.json the Session publishes (#365).
 *
 * The Play Store app first, the GitHub APKs' package second, and any other
 * package — a fork, a debug variant — only when neither is registered. The
 * order in the file must not decide it: Firebase lists clients in the order
 * they were added, and the Play app was added after the GitHub one.
 */
final class ClientConfigTest extends TestCase
{
    public function testThePlayStorePackageIsPreferredWhereverItIsListed(): void
    {
        $config = ClientConfig::fromGoogleServicesJson(self::file('de.plmail.google', 'de.example.fork', 'de.pluetzner.plmail'));

        self::assertSame('de.pluetzner.plmail', $config->packageName);
        self::assertSame('1:1234567890:android:de.pluetzner.plmail', $config->applicationId);
    }

    /** The APKs already installed from GitHub keep working on a project that registered only them. */
    public function testTheGitHubPackageIsTakenWhenThePlayOneIsNotRegistered(): void
    {
        $config = ClientConfig::fromGoogleServicesJson(self::file('de.example.fork', 'de.plmail.google'));

        self::assertSame('de.plmail.google', $config->packageName);
    }

    public function testAnyOtherPackageFallsBackToTheFirstClient(): void
    {
        $config = ClientConfig::fromGoogleServicesJson(self::file('de.example.fork', 'de.example.debug'));

        self::assertSame('de.example.fork', $config->packageName);
    }

    private static function file(string ...$packages): string
    {
        return (string) json_encode([
            'project_info' => ['project_number' => '1234567890', 'project_id' => 'plmail-test'],
            'client'       => array_map(static fn (string $package): array => [
                'client_info' => [
                    'mobilesdk_app_id'    => '1:1234567890:android:' . $package,
                    'android_client_info' => ['package_name' => $package],
                ],
                'api_key' => [['current_key' => 'AIzaSyTestKeyForPlMail']],
            ], $packages),
        ], JSON_THROW_ON_ERROR);
    }
}
