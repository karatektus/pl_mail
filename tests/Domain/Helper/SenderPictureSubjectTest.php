<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Enum\Mail\SenderPictureKind;
use App\Domain\Helper\SenderPictureSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What each sender picture lookup is keyed by (#639).
 *
 * The ingest step stores a lookup under this key and the page searches for it
 * under this key; if the two ever disagreed nothing would fail, every picture
 * would just silently never be found. So the keys are pinned per kind,
 * including the cases where there must be none.
 */
final class SenderPictureSubjectTest extends TestCase
{
    /** @return iterable<string, array{SenderPictureKind, ?string, ?string}> */
    public static function subjects(): iterable
    {
        yield 'a hashed lookup is the address, lower-cased and unwrapped' => [SenderPictureKind::Hashed, 'Ada <ADA@Example.ORG>', 'ada@example.org'];
        yield 'BIMI is the From domain as written, subdomain kept'        => [SenderPictureKind::Bimi, 'news@mail.shop.example.com', 'mail.shop.example.com'];
        yield 'a website is the registrable domain, not the subdomain'    => [SenderPictureKind::Website, 'news@mail.shop.example.com', 'example.com'];
        yield 'a two-label public suffix keeps its third label'           => [SenderPictureKind::Website, 'info@news.firm.co.uk', 'firm.co.uk'];
        yield 'an IP literal has no domain to look up'                    => [SenderPictureKind::Bimi, 'root@[192.0.2.1]', null];
        yield 'nothing is not an address'                                 => [SenderPictureKind::Hashed, null, null];
        yield 'a fragment from a broken header is not an address'         => [SenderPictureKind::Hashed, '"Doe', null];
        yield 'a contact photo is not a lookup and has no subject'        => [SenderPictureKind::Contact, 'ada@example.org', null];
    }

    #[DataProvider('subjects')]
    public function testEachKindIsKeyedByWhatItsSourcePublishes(SenderPictureKind $kind, ?string $address, ?string $expected): void
    {
        self::assertSame($expected, SenderPictureSubject::of($kind, $address));
    }

    public function testTheAddressKeyIgnoresCaseAndWrappingButNotTheAddress(): void
    {
        $key = SenderPictureSubject::addressKey('ada@example.org');

        self::assertSame($key, SenderPictureSubject::addressKey('Ada <ADA@example.org>'));
        self::assertNotSame($key, SenderPictureSubject::addressKey('bob@example.org'));
        self::assertSame(32, strlen((string) $key));
        self::assertStringNotContainsString('ada', (string) $key, 'the key must not carry the address itself');
    }
}
