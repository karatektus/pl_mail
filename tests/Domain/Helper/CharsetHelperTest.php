<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\CharsetHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The guard between a mail server's bytes and a UTF-8 column.
 *
 * The failure this prevents is not mojibake. Postgres rejects an invalid UTF-8
 * byte rather than storing it, so one 0xFC in a body or a subject fails the
 * INSERT and takes its whole batch with it — mail that never appears, and a log
 * line that mentions no charset. Every assertion below is really the same
 * assertion: whatever went in, what comes out is valid UTF-8.
 */
final class CharsetHelperTest extends TestCase
{
    #[DataProvider('declaredCharsets')]
    public function testToUtf8(string $bytes, ?string $charset, string $expected): void
    {
        $decoded = CharsetHelper::toUtf8($bytes, $charset);

        self::assertSame($expected, $decoded);
        self::assertTrue(mb_check_encoding($decoded, 'UTF-8'));
    }

    /**
     * @return iterable<string, array{string, string|null, string}>
     */
    public static function declaredCharsets(): iterable
    {
        yield 'ascii, no charset'  => ['Hello', null, 'Hello'];
        yield 'utf-8 declared'     => ['Grüße', 'utf-8', 'Grüße'];
        yield 'utf-8 quoted'       => ['Grüße', '"UTF-8"', 'Grüße'];

        // The case that was losing mail: a German sender's latin-1 body part.
        yield 'latin-1 declared'   => ["Gr\xfc\xdfe von J\xf6rg", 'iso-8859-1', 'Grüße von Jörg'];
        yield 'latin1 alias'       => ["J\xf6rg", 'latin1', 'Jörg'];
        yield 'windows-1252'       => ["J\xf6rg", 'windows-1252', 'Jörg'];

        // Why the fallback is cp1252 and not true latin-1. 0x93/0x94 are the
        // curly quotes every Windows mail client emits under a latin-1 label;
        // decoded as the C1 control characters latin-1 actually defines there,
        // they become invisible and the sentence loses its punctuation.
        yield 'cp1252 smart quotes under a latin-1 label' => [
            "Das \x93Sonderangebot\x94 \x96 heute",
            'ISO-8859-1',
            'Das “Sonderangebot” – heute',
        ];

        // Declared ASCII and 8-bit anyway. Taken at its word every byte above
        // 0x7F becomes '?', so the declaration would cost data it never had to.
        yield 'us-ascii that is not' => ["caf\xe9", 'us-ascii', 'café'];

        // A charset mbstring cannot use must not throw and must not lose the
        // message; it degrades to the same treatment as no charset at all.
        yield 'bogus charset, 8-bit'  => ["J\xf6rg", 'x-not-a-charset', 'Jörg'];
        yield 'bogus charset, ascii'  => ['Jorg', 'definitely-not-real', 'Jorg'];
        yield 'empty charset'         => ["J\xf6rg", '', 'Jörg'];

        // A charset that is neither latin-1 nor a fallback, to prove the
        // conversion is real and not a cp1252 rubber stamp.
        yield 'koi8-r' => ["\xf0\xd2\xc9\xd7\xc5\xd4", 'KOI8-R', 'Привет'];

        // Declared UTF-8 and not UTF-8. Repaired rather than rejected: the
        // alternative is the failed INSERT this class exists to stop.
        yield 'mislabelled utf-8' => ["Gr\xfc\xdfe", 'utf-8', 'Grüße'];
    }

    #[DataProvider('contentTypes')]
    public function testCharsetFromContentType(?string $header, ?string $expected): void
    {
        self::assertSame($expected, CharsetHelper::charsetFromContentType($header));
    }

    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function contentTypes(): iterable
    {
        yield 'unquoted'      => ['text/html; charset=ISO-8859-1', 'ISO-8859-1'];
        yield 'quoted'        => ['text/html; charset="ISO-8859-1"', 'ISO-8859-1'];
        yield 'spaced'        => ['text/plain ; charset = utf-8', 'utf-8'];
        yield 'other params'  => ['text/plain; charset=utf-8; format=flowed', 'utf-8'];
        yield 'params before' => ['text/calendar; method=REQUEST; charset=UTF-8', 'UTF-8'];
        yield 'mixed case key' => ['text/html; CharSet=Windows-1252', 'Windows-1252'];

        // Absent is not the same as a default — toUtf8() treats the two
        // differently, so this must not invent one.
        yield 'no charset' => ['text/html', null];
        yield 'null'       => [null, null];
        yield 'empty'      => ['text/html; charset=', null];
    }

    #[DataProvider('unlabelledBytes')]
    public function testEnsureUtf8(string $bytes, string $expected): void
    {
        self::assertSame($expected, CharsetHelper::ensureUtf8($bytes));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unlabelledBytes(): iterable
    {
        // Valid UTF-8 is returned byte for byte, which is the case that matters
        // most: nearly every value goes through here and must be untouched.
        yield 'ascii'       => ['Project update', 'Project update'];
        yield 'utf-8'       => ['Grüße über Ähren', 'Grüße über Ähren'];
        yield 'emoji'       => ['Re: 🎉 launch', 'Re: 🎉 launch'];
        yield 'empty'       => ['', ''];

        yield 'latin-1'     => ["Gr\xfc\xdfe von J\xf6rg", 'Grüße von Jörg'];
        yield 'cp1252'      => ["\x93quoted\x94", '“quoted”'];

        // cp1252 leaves five byte values undefined; mbstring maps them to the
        // matching C1 control characters rather than failing. The result is
        // not useful, but it is storable, which is the only requirement here.
        yield 'undefined cp1252 bytes' => ["ok\x81\x8d", "ok\u{0081}\u{008D}"];
    }
}
