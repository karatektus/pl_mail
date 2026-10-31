<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ImapClient;

use App\Infrastructure\ImapClient\ImapArgument;
use App\Infrastructure\ImapClient\ImapCommandBuffer;
use PHPUnit\Framework\TestCase;

/**
 * Values from outside go on the wire as values, never as protocol.
 *
 * A folder name or a password is spliced into a command line. Written as it
 * comes, a quote ends the argument early and a line break ends the command —
 * after which whatever follows is a command of its own, sent with the user's
 * session. These pin the encoding that makes that impossible.
 */
final class ImapArgumentTest extends TestCase
{
    public function testPlainTextIsQuoted(): void
    {
        self::assertSame(["A1 SELECT \"Sent Items\"\r\n"], self::encode('SELECT', ImapArgument::string('Sent Items')));
    }

    public function testQuotesAndBackslashesAreEscapedInsideTheQuotes(): void
    {
        self::assertSame(["A1 LOGIN \"user\" \"pa\\\"ss\\\\word\"\r\n"], self::encode(
            'LOGIN',
            ImapArgument::string('user'),
            ImapArgument::string('pa"ss\\word'),
        ));
    }

    /**
     * The injection case. Quoted, the line break would end the command and
     * start another; as a literal it is twelve bytes of a password.
     */
    public function testALineBreakCannotEndTheCommand(): void
    {
        $pieces = self::encode('LOGIN', ImapArgument::string('user'), ImapArgument::string("x\r\nA2 DELETE"));

        self::assertSame(["A1 LOGIN \"user\" {12}\r\n", "x\r\nA2 DELETE\r\n"], $pieces);
    }

    /**
     * A synchronising literal splits the command where the server must say
     * `+` before the bytes go; with LITERAL+ it does not have to be asked.
     */
    public function testEightBitTextIsALiteralAndLiteralPlusKeepsItInOnePiece(): void
    {
        self::assertSame(["A1 SELECT {9}\r\n", "Entwürfe\r\n"], self::encode('SELECT', ImapArgument::string('Entwürfe')));
        self::assertSame(["A1 SELECT {9+}\r\nEntwürfe\r\n"], self::encodeWithLiteralPlus('SELECT', ImapArgument::string('Entwürfe')));
    }

    public function testListsAndAtomsAreWrittenAsTheyAre(): void
    {
        self::assertSame(["A1 STORE 4 +FLAGS (\\Seen \\Flagged)\r\n"], self::encode(
            'STORE',
            ImapArgument::atom('4'),
            ImapArgument::atom('+FLAGS'),
            ImapArgument::list([ImapArgument::atom('\\Seen'), ImapArgument::atom('\\Flagged')]),
        ));
    }

    public function testAnAtomRefusesALineBreak(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ImapArgument::atom("INBOX\r\nA2 LOGOUT");
    }

    /** @return list<string> */
    private static function encode(string $name, ImapArgument ...$arguments): array
    {
        return self::build($name, $arguments, false);
    }

    /** @return list<string> */
    private static function encodeWithLiteralPlus(string $name, ImapArgument ...$arguments): array
    {
        return self::build($name, $arguments, true);
    }

    /**
     * The command as ImapConnection builds it.
     *
     * @param list<ImapArgument> $arguments
     *
     * @return list<string>
     */
    private static function build(string $name, array $arguments, bool $literalPlus): array
    {
        $command = new ImapCommandBuffer('A1 ' . $name);

        foreach ($arguments as $argument) {
            $command->append(' ');
            $argument->appendTo($command, $literalPlus);
        }

        return $command->pieces();
    }
}
