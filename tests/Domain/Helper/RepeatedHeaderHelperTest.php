<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\RepeatedHeaderHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A header block repeated at the top of the body comes off, and nothing else
 * does.
 *
 * Both halves of the rule are pinned. Missing a repeat is #173 — a message
 * shown with its own `Delivered-To: …` block above the content. Taking off
 * something that is not a repeat would cut lines out of a real body, which is
 * worse, so every near miss below must come back as null.
 */
final class RepeatedHeaderHelperTest extends TestCase
{
    private const string HEADER = "Delivered-To: me@example.test\r\n"
        . "Subject: Your parcel\r\n"
        . "Message-ID: <parcel@example.test>\r\n"
        . "Content-Type: text/html; charset=utf-8";

    #[DataProvider('repeats')]
    public function testARepeatedHeaderBlockIsTakenOffTheBody(string $rawHeader, string $rawBody, string $expected): void
    {
        self::assertSame($expected, RepeatedHeaderHelper::strip($rawHeader, $rawBody));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function repeats(): iterable
    {
        $html = "<!DOCTYPE html>\r\n<p>Hello</p>\r\n";

        yield 'with the blank line, header as IMAP sends it' => [self::HEADER . "\r\n\r\n", self::HEADER . "\r\n\r\n" . $html, $html];
        yield 'with the blank line, header already trimmed'  => [self::HEADER, self::HEADER . "\r\n\r\n" . $html, $html];
        yield 'without a blank line before the DOCTYPE'      => [self::HEADER . "\r\n\r\n", self::HEADER . "\r\n" . $html, $html];
        yield 'a body that opens with an empty line keeps it' => [self::HEADER, self::HEADER . "\r\n\r\n\r\n" . $html, "\r\n" . $html];
        yield 'bare line feeds'                               => [self::HEADER, self::HEADER . "\n\n" . $html, $html];
        yield 'nothing after the headers'                     => [self::HEADER, self::HEADER . "\r\n\r\n", ''];
    }

    #[DataProvider('nearMisses')]
    public function testABodyThatDoesNotRepeatTheHeadersRatherThanLooksLikeItIsLeftAlone(string $rawHeader, string $rawBody): void
    {
        self::assertNull(RepeatedHeaderHelper::strip($rawHeader, $rawBody));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nearMisses(): iterable
    {
        yield 'an ordinary body' => [self::HEADER, "<!DOCTYPE html>\r\n<p>Hello</p>\r\n"];

        // Header-shaped, but not this message's headers.
        yield 'a text body that opens like a header' => [self::HEADER, "Note: the parcel is late.\r\n"];

        // The same first lines, then the block goes on: the server's header
        // literal is the whole block, so a longer one is not a repeat of it.
        yield 'the block continues past the last header line' => [self::HEADER, self::HEADER . "; format=flowed\r\n\r\nHello\r\n"];

        yield 'an empty header' => ['', "Hello\r\n"];
    }
}
