<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Integration;

use App\Domain\Enum\Integration\AuthKind;
use App\Domain\Enum\Integration\Capability;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Enum\Integration\ServiceKind;
use App\Domain\Interface\CalendarSyncDriverInterface;
use App\Domain\Interface\IntegrationDriverInterface;
use App\Domain\Interface\SearchableDriverInterface;
use App\Domain\Interface\TimelineDriverInterface;
use App\Domain\Interface\VerifiableDriverInterface;
use App\Service\Calendar\Sync\CalDav\CalDavCalendarDriver;
use App\Service\Calendar\Sync\IcsUrl\IcsUrlCalendarDriver;
use App\Service\Integration\Driver\DropboxDriver;
use App\Service\Integration\Driver\GoogleDriveDriver;
use App\Service\Integration\Driver\GooglePhotosDriver;
use App\Service\Integration\Driver\ImmichDriver;
use App\Service\Integration\Driver\NextcloudDriver;
use App\Service\Integration\Driver\OneDriveDriver;
use PHPUnit\Framework\TestCase;

/**
 * Pins the provider table.
 *
 * Every one of these facts is load-bearing somewhere in the UI, and all of
 * them are the kind of thing a later edit could flip without any test failing
 * — a provider quietly claiming ShareLink would make the compose picker offer
 * "insert link" and then hand the user nothing back.
 *
 * Most of them are asked of the **file** providers rather than of every case,
 * and that scoping is the subject of a claim in its own right: a calendar
 * connection is on this enum and must never be in a file list. Where a check
 * walks Provider::of(ServiceKind::Files), the reason is that the thing it
 * checks — a capability, a picker driver, a tutorial partial — belongs to the
 * file picker, and asking it of CalDAV would be asserting that a calendar
 * server can be browsed for attachments.
 */
final class ProviderTest extends TestCase
{
    public function testEveryProviderIsImplemented(): void
    {
        self::assertSame(Provider::cases(), Provider::implemented());
        self::assertCount(8, Provider::cases());
    }

    /**
     * A calendar connection is on this enum, and everything that offers files
     * has to filter it out.
     *
     * The kind is what does that filtering, so it is what this pins. Inferring
     * it from an empty capability list instead would be true today and quietly
     * wrong the first time a calendar service also served files.
     */
    public function testTheCalendarProvidersAreNotOfferedAsFileServices(): void
    {
        // Both of them, and by kind rather than by case: an ICS address is the
        // second calendar provider and the first that is a *format* rather than
        // a protocol, so "there is exactly one calendar provider" was never the
        // rule and pinning it as one would fail the next time somebody adds a
        // third.
        foreach ([Provider::CalDav, Provider::Ics] as $provider) {
            self::assertSame(ServiceKind::Calendar, $provider->kind());
            self::assertNotContains($provider, Provider::of(ServiceKind::Files));
            self::assertSame([], $provider->capabilities(), $provider->value . ' holds no files');

            foreach (Capability::cases() as $capability) {
                self::assertFalse($provider->supports($capability), $capability->value . ' is a file capability');
            }
        }
    }

    /**
     * The calendar provider's driver implements the two calendar interfaces and
     * emphatically not the file one.
     *
     * IntegrationDriverInterface is file-coupled in every method — list,
     * download, upload, shareLink, thumbnail. A calendar driver implementing it
     * would be five throwing stubs, and worse, it would be tagged into the file
     * driver registry and reachable from the picker.
     */
    public function testTheCalendarProvidersHaveCalendarDriversAndNotFileOnes(): void
    {
        foreach ([CalDavCalendarDriver::class, IcsUrlCalendarDriver::class] as $driver) {
            $implements = class_implements($driver) ?: [];

            self::assertContains(CalendarSyncDriverInterface::class, $implements, $driver);
            self::assertContains(VerifiableDriverInterface::class, $implements, 'the connect and test paths need verify()');
            self::assertNotContains(IntegrationDriverInterface::class, $implements, 'a calendar connection holds no files');
        }
    }

    /**
     * Every file provider needs a driver class implementing the interface.
     * Whether it actually claims its provider is asserted in that driver's own
     * test; this is the cheap check that nobody added a case without a driver,
     * which would only surface at run time — after the user had already
     * authorised.
     */
    public function testEveryFileProviderHasADriverClass(): void
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
            array_map(static fn (Provider $p): string => $p->value, Provider::of(ServiceKind::Files)),
            array_keys($drivers),
            'a file provider has no driver mapped',
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
     * Every file provider searches except Google Photos, whose Library API
     * offers no text search over media. Timeline stays Immich-only.
     *
     * Each capability is paired with an optional interface, so a provider
     * claiming one without implementing it would draw a search box or a date bar
     * that then failed at the driver.
     */
    public function testSearchAndTimelineMatchTheirInterfaces(): void
    {
        $drivers = [
            'nextcloud'    => NextcloudDriver::class,
            'immich'       => ImmichDriver::class,
            'googleDrive'  => GoogleDriveDriver::class,
            'googlePhotos' => GooglePhotosDriver::class,
            'oneDrive'     => OneDriveDriver::class,
            'dropbox'      => DropboxDriver::class,
        ];

        foreach (Provider::of(ServiceKind::Files) as $provider) {
            $implements = class_implements($drivers[$provider->value]) ?: [];

            self::assertSame(
                Provider::GooglePhotos !== $provider,
                $provider->supports(Capability::Search),
                $provider->value.' search capability',
            );
            self::assertSame(
                $provider->supports(Capability::Search),
                in_array(SearchableDriverInterface::class, $implements, true),
                $provider->value.' claims search without implementing it, or the reverse',
            );

            self::assertSame(
                Provider::Immich === $provider,
                $provider->supports(Capability::Timeline),
                $provider->value.' timeline capability',
            );
            self::assertSame(
                $provider->supports(Capability::Timeline),
                in_array(TimelineDriverInterface::class, $implements, true),
                $provider->value.' claims a timeline without implementing it, or the reverse',
            );
        }
    }

    public function testEveryFileProviderCanBrowseDownloadUploadAndPreview(): void
    {
        foreach (Provider::of(ServiceKind::Files) as $provider) {
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
        // CalDAV belongs here for the same reason and one more: it is a
        // protocol rather than a product, so there is no canonical host to
        // point at even in principle. An ICS subscription belongs here because
        // the address is the whole connection — it asks for no password at all,
        // and AuthKind is what makes needsBaseUrl() true so the address is
        // validated. See Provider::authKind(), which says why that is not a
        // third AuthKind case.
        foreach ([Provider::Nextcloud, Provider::Immich, Provider::CalDav, Provider::Ics] as $provider) {
            self::assertSame(AuthKind::AppPassword, $provider->authKind());
            self::assertTrue($provider->needsBaseUrl(), 'a self-hosted service has no canonical host');
            self::assertSame([], $provider->scopes(), 'an app-password provider asks for no OAuth scopes');
            self::assertNull($provider->oauthEndpoints());
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

        // Not "cal_dav": the case value is lower-case for exactly this reason,
        // so the slug, the translation key and anything named after the
        // provider all read the way a person writing about CalDAV would spell
        // it.
        self::assertSame('caldav', Provider::CalDav->slug());
        self::assertSame('caldav', Provider::CalDav->transKey());
    }

    /**
     * The tutorials are the file-picker ones: what an admin has to register at
     * Google, Dropbox or Microsoft before anybody can connect. CalDAV needs no
     * application-side registration at all — the user brings a URL and an app
     * password — so there is nothing to walk an admin through, and the aside
     * that renders these includes them with `ignore missing`.
     */
    public function testEveryFileProviderHasATutorialTemplate(): void
    {
        foreach (Provider::of(ServiceKind::Files) as $provider) {
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
