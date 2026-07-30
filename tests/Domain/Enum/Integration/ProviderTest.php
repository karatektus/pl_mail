<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Integration;

use App\Domain\Enum\Integration\AuthKind;
use App\Domain\Enum\Integration\Capability;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Interface\IntegrationDriverInterface;
use App\Domain\Interface\SearchableDriverInterface;
use App\Domain\Interface\TimelineDriverInterface;
use App\Service\Integration\Driver\ImmichDriver;
use PHPUnit\Framework\TestCase;

/**
 * Pins the provider table.
 *
 * Every one of these facts is load-bearing somewhere in the UI, and all of
 * them are the kind of thing a later edit could flip without any test failing
 * — a provider quietly claiming ShareLink would make the compose picker offer
 * "insert link" and then hand the user nothing back.
 */
final class ProviderTest extends TestCase
{
    public function testEveryProviderIsImplemented(): void
    {
        self::assertSame(Provider::cases(), Provider::implemented());
        self::assertCount(6, Provider::cases());
    }

    /**
     * Every provider needs a driver class implementing the interface. Whether
     * it actually claims its provider is asserted in that driver's own test;
     * this is the cheap check that nobody added a case without a driver, which
     * would only surface at run time — after the user had already authorised.
     */
    public function testEveryProviderHasADriverClass(): void
    {
        // Keyed by backing value: an enum instance cannot be an array key.
        $drivers = [
            'nextcloud'    => 'NextcloudDriver',
            'immich'       => 'ImmichDriver',
            'googleDrive'  => 'GoogleDriveDriver',
            'googlePhotos' => 'GooglePhotosDriver',
            'oneDrive'     => 'OneDriveDriver',
            'dropbox'      => 'DropboxDriver',
        ];

        self::assertSame(
            array_map(static fn (Provider $p): string => $p->value, Provider::cases()),
            array_keys($drivers),
            'a provider has no driver mapped',
        );

        foreach ($drivers as $shortName) {
            $class = 'App\\Service\\Integration\\Driver\\'.$shortName;

            self::assertTrue(class_exists($class), $class.' is missing');
            self::assertContains(IntegrationDriverInterface::class, class_implements($class) ?: []);
        }
    }

    public function testOnlyPhotoServicesLackShareLink(): void
    {
        // Neither can produce a public URL for one asset without creating a
        // shared album, which is a bigger side effect than attaching a file
        // should have.
        self::assertFalse(Provider::Immich->supports(Capability::ShareLink));
        self::assertFalse(Provider::GooglePhotos->supports(Capability::ShareLink));

        self::assertTrue(Provider::Nextcloud->supports(Capability::ShareLink));
        self::assertTrue(Provider::GoogleDrive->supports(Capability::ShareLink));
        self::assertTrue(Provider::OneDrive->supports(Capability::ShareLink));
        self::assertTrue(Provider::Dropbox->supports(Capability::ShareLink));
    }

    /**
     * Search and Timeline are Immich-only for now, and each is paired with an
     * optional interface — a provider claiming one without implementing it would
     * draw a search box or a date bar that then fails at the driver.
     */
    public function testOnlyImmichClaimsSearchAndTimeline(): void
    {
        foreach (Provider::cases() as $provider) {
            $expected = Provider::Immich === $provider;

            self::assertSame($expected, $provider->supports(Capability::Search), $provider->value);
            self::assertSame($expected, $provider->supports(Capability::Timeline), $provider->value);
        }

        self::assertContains(
            SearchableDriverInterface::class,
            class_implements(ImmichDriver::class) ?: [],
        );
        self::assertContains(
            TimelineDriverInterface::class,
            class_implements(ImmichDriver::class) ?: [],
        );
    }

    public function testEveryProviderCanBrowseDownloadUploadAndPreview(): void
    {
        foreach (Provider::cases() as $provider) {
            foreach ([Capability::Browse, Capability::Download, Capability::Upload, Capability::Thumbnail] as $capability) {
                self::assertTrue(
                    $provider->supports($capability),
                    sprintf('%s should support %s', $provider->value, $capability->value),
                );
            }
        }
    }

    public function testSelfHostedProvidersUseAppPasswordsAndNeedAnAddress(): void
    {
        foreach ([Provider::Nextcloud, Provider::Immich] as $provider) {
            self::assertSame(AuthKind::AppPassword, $provider->authKind());
            self::assertTrue($provider->needsBaseUrl(), 'a self-hosted service has no canonical host');
        }
    }

    public function testSaasProvidersUseOauthAndHaveAFixedAddress(): void
    {
        foreach ([Provider::GoogleDrive, Provider::GooglePhotos, Provider::OneDrive, Provider::Dropbox] as $provider) {
            self::assertSame(AuthKind::OAuth2, $provider->authKind());
            self::assertFalse($provider->needsBaseUrl(), 'a SaaS host must not be user-settable');
        }
    }

    /**
     * The tutorial partials are included by slug, so a wrong one is a silently
     * missing tutorial rather than an error.
     */
    public function testSlugsAreSnakeCase(): void
    {
        self::assertSame('nextcloud', Provider::Nextcloud->slug());
        self::assertSame('immich', Provider::Immich->slug());
        self::assertSame('google_drive', Provider::GoogleDrive->slug());
        self::assertSame('google_photos', Provider::GooglePhotos->slug());
        self::assertSame('one_drive', Provider::OneDrive->slug());
        self::assertSame('dropbox', Provider::Dropbox->slug());
    }

    public function testEveryProviderHasATutorialTemplate(): void
    {
        foreach (Provider::cases() as $provider) {
            self::assertFileExists(
                sprintf(
                    '%s/templates/admin/integrations/tutorial/_%s.html.twig',
                    dirname(__DIR__, 4),
                    $provider->slug(),
                ),
            );
        }
    }

    public function testEveryProviderHasALabelAndAnIcon(): void
    {
        foreach (Provider::cases() as $provider) {
            self::assertNotSame('', $provider->label());
            self::assertStringStartsWith('fa-', $provider->icon());
        }
    }
}
