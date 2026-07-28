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
    }
}
