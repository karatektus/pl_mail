<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Exception\InvalidFolderNameException;
use App\Domain\Helper\ImapUtf7Helper;
use App\Service\Imap\ImapFolderName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A folder name somebody typed, on its way to becoming an IMAP path.
 *
 * The name is encoded to modified UTF-7 once, here, and the parent's path —
 * already encoded, as LIST gave it — is put in front untouched. The separator
 * is the server's and may not appear in a name: IMAP has no way to escape it,
 * so "v1.2" on a "." server would quietly be two folders.
 */
final class ImapFolderNameTest extends TestCase
{
    public function testAnAsciiNameAtTheTopLevelIsItself(): void
    {
        self::assertSame('Clients', ImapFolderName::childPath(null, '/', 'Clients'));
    }

    public function testAChildHangsFromItsParentsRawPathWithTheServersSeparator(): void
    {
        self::assertSame('INBOX.Clients.Acme', ImapFolderName::childPath('INBOX.Clients', '.', 'Acme'));
        self::assertSame('Clients/Acme', ImapFolderName::childPath('Clients', '/', 'Acme'));
    }

    /**
     * The name is encoded; the parent, which is already, is not touched again.
     */
    public function testANonAsciiNameIsEncodedOnceAndTheParentNotAtAll(): void
    {
        $path = ImapFolderName::childPath('INBOX.B&APw-cher', '.', 'Köln');

        self::assertSame('INBOX.B&APw-cher.K&APY-ln', $path);
        self::assertSame('Köln', ImapUtf7Helper::decode('K&APY-ln'), 'and it reads back as typed');
    }

    /** The encoding's own shift character has to be escaped, or "R&D" is not valid UTF-7. */
    public function testAnAmpersandIsEscaped(): void
    {
        self::assertSame('R&-D', ImapFolderName::childPath(null, '/', 'R&D'));
        self::assertSame('R&D', ImapUtf7Helper::decode('R&-D'));
    }

    public function testSurroundingSpaceIsTrimmed(): void
    {
        self::assertSame('Clients', ImapFolderName::childPath(null, '/', '  Clients '));
    }

    /**
     * The separator is refused, whichever it is: "v1.2" is fine on a "/"
     * server and two folders on a "." one.
     */
    public function testTheServersSeparatorIsRefusedInAName(): void
    {
        self::assertSame('v1.2', ImapFolderName::childPath(null, '/', 'v1.2'));

        $this->expectExceptionObject(new InvalidFolderNameException(InvalidFolderNameException::SEPARATOR, '.'));

        ImapFolderName::childPath('INBOX', '.', 'v1.2');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'empty'             => ['', InvalidFolderNameException::EMPTY];
        yield 'blank'             => ['   ', InvalidFolderNameException::EMPTY];
        yield 'star'              => ['Important*', InvalidFolderNameException::WILDCARD];
        yield 'percent'           => ['100%', InvalidFolderNameException::WILDCARD];
        yield 'control character' => ["Line\nbreak", InvalidFolderNameException::CONTROL];
        yield 'too long'          => [str_repeat('a', 256), InvalidFolderNameException::TOO_LONG];
        yield 'inbox'             => ['inbox', InvalidFolderNameException::RESERVED];
    }

    #[DataProvider('refusals')]
    public function testANameThatCannotBeAFolderIsRefusedWithItsReason(string $name, string $reason): void
    {
        try {
            ImapFolderName::childPath(null, '/', $name);
            self::fail('accepted ' . var_export($name, true));
        } catch (InvalidFolderNameException $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    /** "INBOX" is only reserved at the top; inside another folder it is a name like any. */
    public function testInboxIsOnlyReservedAtTheTop(): void
    {
        self::assertSame('Archive/INBOX', ImapFolderName::childPath('Archive', '/', 'INBOX'));
    }
}
