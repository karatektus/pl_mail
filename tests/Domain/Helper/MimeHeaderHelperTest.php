<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\MimeHeaderHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MimeHeaderHelperTest extends TestCase
{
    #[DataProvider('headerCases')]
    public function testDecode(string $raw, string $expected): void
    {
        self::assertSame($expected, MimeHeaderHelper::decode($raw));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function headerCases(): iterable
    {
        yield 'plain ascii'  => ['Project update', 'Project update'];
        yield 'empty'        => ['', ''];

        // The regression: servers send raw UTF-8 headers, and webklex decodes
        // encoded words before we see them. iconv_mime_decode() over a whole
        // header deletes every 8-bit byte, so these arrived as "Gre ber hren"
        // and "Jrg Mller" — every umlaut gone from every synced subject.
        yield 'raw utf-8 subject' => ['Grüße über Ähren', 'Grüße über Ähren'];
        yield 'raw utf-8 name'    => ['Jörg Müller', 'Jörg Müller'];

        yield 'quoted-printable' => ['=?UTF-8?Q?Gr=C3=BC=C3=9Fe?=', 'Grüße'];
        yield 'base64'           => ['=?UTF-8?B?R3LDvMOfZQ==?=', 'Grüße'];
        yield 'iso-8859-1'       => ['=?ISO-8859-1?Q?J=F6rg?=', 'Jörg'];

        // Adjacent encoded words: the whitespace between them is a separator
        // (RFC 2047 §6.2), so decoding them as one run is what joins the word.
        yield 'split word' => ['=?UTF-8?Q?Gr=C3=BC?= =?UTF-8?Q?=C3=9Fe?=', 'Grüße'];

        // Real headers mix the two shapes; the raw half has to survive.
        yield 'mixed' => ['=?UTF-8?Q?Gr=C3=BC=C3=9Fe?= von Jörg', 'Grüße von Jörg'];

        yield 'ascii around encoded word' => ['Re: =?UTF-8?Q?Gr=C3=BC=C3=9Fe?= (2)', 'Re: Grüße (2)'];

        // Not an encoded word — must not be mangled or decoded.
        yield 'question marks' => ['What? =? Really?', 'What? =? Really?'];

        // The second regression: a header that never did RFC 2047 at all.
        // Older German mail systems and mailing list software send raw 8-bit
        // bytes with no charset stated anywhere, webklex passes them through,
        // and this helper used to as well — so invalid UTF-8 reached
        // $subject and Postgres rejected the INSERT. The message did not
        // arrive mangled, it did not arrive.
        yield 'raw latin-1 subject' => ["Gr\xfc\xdfe von J\xf6rg", 'Grüße von Jörg'];
        yield 'raw latin-1 name'    => ["J\xf6rg M\xfcller", 'Jörg Müller'];

        // Read as cp1252 rather than as true latin-1, because the bytes in
        // 0x80–0x9F are Windows punctuation in practice and control characters
        // only on paper.
        yield 'raw cp1252 punctuation' => ["Ihr \x93Angebot\x94 \x96 heute", 'Ihr “Angebot” – heute'];

        // Both shapes in one header, which is what pins the order of the two
        // steps. The guard runs first, over a string whose encoded word is
        // still ASCII and therefore untouchable; run last it would find the
        // decoded "Jörg" already valid UTF-8 beside a raw 0xFC and re-read the
        // lot as cp1252, mangling the half that was right.
        yield 'encoded word beside raw 8-bit' => [
            "=?ISO-8859-1?Q?J=F6rg?= schickt Gr\xfc\xdfe",
            'Jörg schickt Grüße',
        ];
    }

    /**
     * Headers that decode to something unhelpful, but must never decode to
     * something Postgres refuses. The value is not asserted — only that it can
     * be stored, which is the property the whole guard exists for.
     */
    #[DataProvider('hostileHeaders')]
    public function testHostileHeadersAreStillValidUtf8(string $raw): void
    {
        self::assertTrue(mb_check_encoding(MimeHeaderHelper::decode($raw), 'UTF-8'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileHeaders(): iterable
    {
        yield 'unknown charset'    => ['=?X-NOT-A-CHARSET?Q?Gr=FC=DFe?='];
        yield 'mislabelled utf-8'  => ['=?UTF-8?B?R3L832U=?='];
        yield 'truncated utf-8'    => ['=?UTF-8?B?R3LD?='];
        yield 'lone opener'        => ["=? Gr\xfc\xdfe"];
        yield 'koi8-r'             => ['=?KOI8-R?Q?=F0=D2=C9=D7=C5=D4?='];
        yield 'raw bytes only'     => ["\x80\x81\x8d\x90\x9d\xfc"];
    }
}
