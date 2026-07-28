<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\MessageIdHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageIdHelperTest extends TestCase
{
    #[DataProvider('normaliseCases')]
    public function testNormaliseStripsBracketsAndWhitespace(string $raw, string $expected): void
    {
        self::assertSame($expected, MessageIdHelper::normalise($raw));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normaliseCases(): iterable
    {
        yield 'bracketed'   => ['<abc@host>', 'abc@host'];
        yield 'bare'        => ['abc@host', 'abc@host'];
        yield 'padded'      => ["  <abc@host>\r\n", 'abc@host'];
        yield 'empty'       => ['', ''];
        yield 'brackets only' => ['<>', ''];
    }

    /**
     * The bug this helper exists for: IMAP stored ids unbracketed while the API
     * backends stored them bracketed, so the two never compared equal.
     */
    public function testBracketedAndBareFormsNormaliseToTheSameId(): void
    {
        self::assertSame(
            MessageIdHelper::normalise('<CAF=abc@mail.gmail.com>'),
            MessageIdHelper::normalise('CAF=abc@mail.gmail.com'),
        );
    }

    public function testNormaliseListSplitsOnAnyWhitespace(): void
    {
        self::assertSame(
            ['a@host', 'b@host', 'c@host'],
            MessageIdHelper::normaliseList("<a@host> <b@host>\r\n\t<c@host>"),
        );
    }

    public function testNormaliseListAcceptsAnAlreadySplitList(): void
    {
        self::assertSame(
            ['a@host', 'b@host'],
            MessageIdHelper::normaliseList(['<a@host>', 'b@host']),
        );
    }

    public function testNormaliseListDropsEmptiesAndDuplicates(): void
    {
        self::assertSame(
            ['a@host'],
            MessageIdHelper::normaliseList(['<a@host>', '', '  ', 'a@host', '<>']),
        );
    }

    public function testNormaliseListHandlesNullAndBlank(): void
    {
        self::assertSame([], MessageIdHelper::normaliseList(null));
        self::assertSame([], MessageIdHelper::normaliseList('   '));
    }
}
