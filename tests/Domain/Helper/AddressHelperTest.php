<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\AddressHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressHelperTest extends TestCase
{
    #[DataProvider('nameCases')]
    public function testNameStripsTheQuotedStringWrapper(?string $raw, string $expected): void
    {
        self::assertSame($expected, AddressHelper::name($raw));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function nameCases(): iterable
    {
        yield 'unquoted'          => ['John Doe', 'John Doe'];
        yield 'quoted'            => ['"John Doe"', 'John Doe'];
        yield 'quoted with comma' => ['"Doe, John"', 'Doe, John'];
        yield 'padded quotes'     => ["  \"John Doe\" \r\n", 'John Doe'];
        yield 'escaped quote'     => ['"John \"Johnny\" Doe"', 'John "Johnny" Doe'];
        yield 'inner quotes kept' => ['John "Johnny" Doe', 'John "Johnny" Doe'];
        yield 'escaped backslash' => ['"C:\\\\ Doe"', 'C:\\ Doe'];
        yield 'folded whitespace' => ["John\r\n  Doe", 'John Doe'];
        yield 'encoded word'      => ['=?UTF-8?Q?J=C3=B6rg_M=C3=BCller?=', 'Jörg Müller'];
        yield 'quoted encoding'   => ['"=?UTF-8?Q?J=C3=B6rg?="', 'Jörg'];
        yield 'raw utf-8 kept'    => ['Jörg Müller', 'Jörg Müller'];
        yield 'empty'             => ['', ''];
        yield 'null'              => [null, ''];
    }

    #[DataProvider('emailCases')]
    public function testEmailIsCanonicalised(?string $raw, string $expected): void
    {
        self::assertSame($expected, AddressHelper::email($raw));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function emailCases(): iterable
    {
        yield 'plain'        => ['john@example.com', 'john@example.com'];
        yield 'mixed case'   => ['John.Doe@Example.COM', 'john.doe@example.com'];
        yield 'bracketed'    => ['<john@example.com>', 'john@example.com'];
        yield 'with name'    => ['"Doe, John" <john@example.com>', 'john@example.com'];
        yield 'quoted'       => ['"john@example.com"', 'john@example.com'];
        yield 'padded'       => ["  john@example.com\r\n", 'john@example.com'];
        yield 'empty'        => ['', ''];
        yield 'null'         => [null, ''];
    }

    #[DataProvider('validityCases')]
    public function testIsValidEmail(?string $raw, bool $expected): void
    {
        self::assertSame($expected, AddressHelper::isValidEmail($raw));
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function validityCases(): iterable
    {
        yield 'plain'            => ['john@example.com', true];
        yield 'bracketed'        => ['<john@example.com>', true];
        yield 'name and address' => ['John Doe <john@example.com>', true];
        yield 'split fragment'   => ['"Doe', false];
        yield 'no domain'        => ['john@', false];
        yield 'no local part'    => ['@example.com', false];
        yield 'bare word'        => ['undisclosed-recipients', false];
        yield 'empty'            => ['', false];
        yield 'null'             => [null, false];
        yield 'over 320 chars'   => [str_repeat('a', 315) . '@example.com', false];
    }

    /**
     * The bug this exists for: splitting an address list on every comma turned
     * one quoted display name into two addresses, the first of which kept the
     * opening quote and had no addr-spec at all.
     */
    public function testSplitListIgnoresCommasInsideQuotesAndAngleBrackets(): void
    {
        self::assertSame(
            ['"Doe, John" <john@example.com>', 'jane@example.com'],
            AddressHelper::splitList('"Doe, John" <john@example.com>, jane@example.com'),
        );
    }

    public function testSplitListKeepsAnEscapedQuoteInsideTheName(): void
    {
        self::assertSame(
            ['"John \"J, J\" Doe" <john@example.com>', 'jane@example.com'],
            AddressHelper::splitList('"John \"J, J\" Doe" <john@example.com>, jane@example.com'),
        );
    }

    #[DataProvider('splitListCases')]
    public function testSplitListDropsEmptyFragments(string $raw, int $expected): void
    {
        self::assertCount($expected, AddressHelper::splitList($raw));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function splitListCases(): iterable
    {
        yield 'empty'            => ['', 0];
        yield 'whitespace only'  => ["  \r\n", 0];
        yield 'trailing comma'   => ['a@example.com,', 1];
        yield 'double comma'     => ['a@example.com,, b@example.com', 2];
        yield 'three addresses'  => ['a@example.com, B <b@example.com>, "C, c" <c@example.com>', 3];
    }
}
