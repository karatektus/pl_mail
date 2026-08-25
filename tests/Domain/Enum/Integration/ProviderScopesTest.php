<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Integration;

use App\Domain\Enum\Integration\Provider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a connected service actually granted, in its own spelling.
 *
 * The file stores and photo libraries had the same blindness the mail accounts
 * had until v0.1.28: plMail asked for a set of permissions and threw the answer
 * away, so a connection granted read and not write was indistinguishable from
 * one that got everything — until a save failed days later.
 *
 * Google Photos is the case that makes it concrete. An unverified app is
 * routinely refused `photoslibrary.readonly` while being given `appendonly`, so
 * saving works and browsing is empty. Those two differ only in their last path
 * segment, which is why the normalisation strips the host and nothing more.
 */
final class ProviderScopesTest extends TestCase
{
    /** @return iterable<string, array{Provider, string|null, list<string>|null}> */
    public static function grants(): iterable
    {
        yield 'Photos, browse refused and append granted' => [
            Provider::GooglePhotos,
            'https://www.googleapis.com/auth/photoslibrary.appendonly',
            ['https://www.googleapis.com/auth/photoslibrary.readonly'],
        ];

        yield 'Photos, both granted' => [
            Provider::GooglePhotos,
            'https://www.googleapis.com/auth/photoslibrary.readonly https://www.googleapis.com/auth/photoslibrary.appendonly',
            [],
        ];

        // The false positive the mail side had to unpick: offline_access asks
        // for a refresh token, not for access to anything, and Microsoft does
        // not echo it.
        yield 'OneDrive, which never reports offline_access' => [
            Provider::OneDrive,
            'Files.ReadWrite',
            [],
        ];

        yield 'OneDrive, spelled as a full URI' => [
            Provider::OneDrive,
            'https://graph.microsoft.com/Files.ReadWrite offline_access',
            [],
        ];

        yield 'Dropbox, complete' => [
            Provider::Dropbox,
            'files.metadata.read files.content.read files.content.write sharing.write',
            [],
        ];

        yield 'Dropbox, missing the write half' => [
            Provider::Dropbox,
            'files.metadata.read files.content.read',
            ['files.content.write', 'sharing.write'],
        ];

        // Absent means "you got what you asked for" per OAuth 2.0, and is also
        // what a connection made before this was recorded looks like. Neither
        // is evidence of a shortfall.
        yield 'nothing came back' => [Provider::GoogleDrive, null, null];
        yield 'an empty string came back' => [Provider::Dropbox, '   ', null];
    }

    /** @param list<string>|null $expected */
    #[DataProvider('grants')]
    public function testItReadsWhatTheServiceGranted(Provider $provider, ?string $granted, ?array $expected): void
    {
        self::assertSame($expected, $provider->missingScopes($granted));
    }

    /**
     * The set it checks is the set it asks for, expressed as a relationship
     * rather than a literal — the two are the same fact, and a test repeating
     * the URLs would go on passing after somebody changed one of them.
     */
    public function testTheFullRequestedSetIsNeverAShortfall(): void
    {
        foreach (Provider::cases() as $provider) {
            if ([] === $provider->scopes()) {
                continue;
            }

            self::assertSame(
                [],
                $provider->missingScopes(implode(' ', $provider->scopes())),
                sprintf('%s: granting exactly what was asked for must not report a shortfall', $provider->value),
            );
        }
    }
}
