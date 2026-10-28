<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Service\Imap\ImapKeyword;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A label's name as an IMAP keyword and back (#221 step 2): an atom on the
 * wire, the exact name in plMail, and the system keywords never a label.
 */
final class ImapKeywordTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function names(): iterable
    {
        yield 'plain'          => ['Work'];
        yield 'nested'         => ['Invoices/2025'];
        yield 'space'          => ['Follow up'];
        yield 'non-ascii'      => ['Café'];
        yield 'cjk'            => ['工作'];
        yield 'ampersand'      => ['R&D'];
        yield 'atom-specials'  => ['a(b)c{d}e%f*g"h\\i]j'];
        yield 'leading dollar' => ['$money'];
        yield 'like junk'      => ['Junk'];
        yield 'like seen'      => ['seen'];
        yield 'emoji'          => ['Done ✅'];
    }

    #[DataProvider('names')]
    public function testEveryNameRoundTripsThroughAnAtom(string $name): void
    {
        $keyword = ImapKeyword::encode($name);

        self::assertMatchesRegularExpression('/^[\x21-\x7E]+$/', $keyword, 'printable ASCII only');
        self::assertDoesNotMatchRegularExpression('/[(){%*"\\\\\]]/', $keyword, 'no atom-specials');
        self::assertNotSame('$', $keyword[0], 'never looks like a system keyword');
        self::assertSame($name, ImapKeyword::decode($keyword));
        self::assertTrue(ImapKeyword::isKeyword($keyword));
    }

    /** A plain label is the same word other clients write, both ways. */
    public function testAPlainNameIsItself(): void
    {
        self::assertSame('Work', ImapKeyword::encode('Work'));
        self::assertSame('Invoices/2025', ImapKeyword::encode('Invoices/2025'));
        self::assertSame('Follow&ACA-up', ImapKeyword::encode('Follow up'));
        self::assertSame('R&-D', ImapKeyword::encode('R&D'));
        self::assertSame('Work', ImapKeyword::decode('Work'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function systemKeywords(): iterable
    {
        foreach (['$Junk', '$NotJunk', '$Forwarded', '$MDNSent', '$Phishing', '$Important', '$label1', '$label5', '$Submitted',
                  'Junk', 'NonJunk', 'NotJunk', 'Forwarded', 'MDNSent', '\\Seen', ''] as $keyword) {
            yield ('' === $keyword ? '(empty)' : $keyword) => [$keyword];
        }
    }

    #[DataProvider('systemKeywords')]
    public function testSystemKeywordsAreNeverLabels(string $keyword): void
    {
        self::assertNull(ImapKeyword::decode($keyword));
    }

    public function testSystemFlagsAreNotKeywords(): void
    {
        foreach (['\\Seen', 'Seen', 'flagged', '\\Draft', 'Recent'] as $flag) {
            self::assertFalse(ImapKeyword::isKeyword($flag), $flag);
        }

        self::assertTrue(ImapKeyword::isKeyword('$Junk'));
        self::assertTrue(ImapKeyword::isKeyword('Work'));
    }

    public function testAMalformedRunIsNotALabel(): void
    {
        self::assertNull(ImapKeyword::decode('Broken&AGE'));
        self::assertNull(ImapKeyword::decode('Odd&AA-'));
    }
}
