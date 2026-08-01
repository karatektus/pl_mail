<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\ImapUtf7Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Modified UTF-7 in, a name a person can read out.
 *
 * The sidebar on a German account said "Entw&APw-rfe" and "Gel&APY-schte
 * Objekte" — the wire form of the folder names, straight out of the LIST
 * response and into Label::$name, because the label chain is built from the
 * raw folder path.
 */
final class ImapUtf7HelperTest extends TestCase
{
    #[DataProvider('segments')]
    public function testDecode(string $raw, string $expected): void
    {
        self::assertSame($expected, ImapUtf7Helper::decode($raw));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function segments(): iterable
    {
        yield 'drafts, german'  => ['Entw&APw-rfe', 'Entwürfe'];
        yield 'trash, german'   => ['Gel&APY-schte Objekte', 'Gelöschte Objekte'];
        yield 'cyrillic'        => ['&BB4EOwQ1-', 'Оле'];
        yield 'non-bmp'         => ['&Jjo-', '☺'];

        // An escaped ampersand is the one shift sequence that decodes to ASCII.
        yield 'escaped ampersand' => ['R&-D', 'R&D'];

        // Nothing to do, and provably nothing done: every one of these has to
        // come back identical, because this runs over every folder on every
        // server and the ASCII ones are almost all of them.
        yield 'plain'          => ['Work', 'Work'];
        yield 'inbox'          => ['INBOX', 'INBOX'];
        yield 'spaced'         => ['Sent Messages', 'Sent Messages'];
        yield 'empty'          => ['', ''];
        yield 'punctuated'     => ['Re: [list] 2024/25', 'Re: [list] 2024/25'];

        // Malformed input is kept, not corrupted. mbstring would substitute a
        // '?' for the unescaped '&' and never say so, which would rename a
        // folder silently — the round-trip check in the helper is what catches
        // this, and it is the reason the helper is not a one-line call.
        yield 'bare ampersand'      => ['R&D', 'R&D'];
        yield 'lone shift'          => ['&', '&'];
        yield 'truncated sequence'  => ['Gr&APzn', 'Gr&APzn'];

        // A server that sends UTF-8 where the RFC says modified UTF-7 (they
        // exist) is left alone rather than mangled into "already ??tf8".
        yield 'raw utf-8 with an ampersand' => ['Büro & Post', 'Büro & Post'];
    }
}
