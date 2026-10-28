<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ImapClient;

use App\Infrastructure\ImapClient\Exception\ImapProtocolException;
use App\Infrastructure\ImapClient\ImapResponseKind;
use App\Infrastructure\ImapClient\ImapResponseReader;
use PHPUnit\Framework\TestCase;

/**
 * Bytes off the wire become whole responses, and whole responses are read.
 *
 * Two halves with different failure modes. Framing fails quietly — a
 * response cut at a packet boundary, or a literal taken for the end of a
 * line, reads as a shorter response and nothing complains. Reading fails on
 * the shapes servers actually send: a fetch item with a space inside its
 * brackets, NIL against "NIL", a response code with a list in it. The cases
 * below are the ones a client built on a regular expression gets wrong.
 */
final class ImapResponseReaderTest extends TestCase
{
    public function testAResponseSplitAcrossReadsComesOutWholeOnlyOnceItIsComplete(): void
    {
        $reader = new ImapResponseReader();

        $reader->feed('* 23 EXI');
        self::assertNull($reader->next(), 'half a line is not a response');

        $reader->feed("STS\r\n* 1 REC");
        self::assertSame(23, $reader->next()?->number);
        self::assertNull($reader->next(), 'and the start of the next one waits');
        self::assertTrue($reader->hasPartial());
    }

    /**
     * The bytes after `{n}` belong to the response however they look — here
     * a line break and something shaped like a tagged response, which a
     * reader that looked for line ends alone would cut the response at.
     */
    public function testALiteralIsTakenWholeWhateverItContains(): void
    {
        $reader  = new ImapResponseReader();
        $literal = "Subject: hi\r\nA1 OK not really\r\n";

        $reader->feed(sprintf("* 1 FETCH (UID 7 BODY[HEADER] {%d}\r\n", strlen($literal)));
        $reader->feed(substr($literal, 0, 10));
        self::assertNull($reader->next(), 'the literal is not all here yet');

        $reader->feed(substr($literal, 10) . ")\r\nA2 OK done\r\n");

        $fetch = $reader->next();

        self::assertSame(['UID' => '7', 'BODY[HEADER]' => $literal], $fetch?->fetchItems());
        self::assertSame('A2', $reader->next()?->tag, 'and the response after it is its own');
    }

    public function testATaggedStatusWithACode(): void
    {
        $response = ImapResponseReader::parse("A3 OK [READ-WRITE] SELECT completed\r\n");

        self::assertSame(ImapResponseKind::Tagged, $response->kind);
        self::assertSame('A3', $response->tag);
        self::assertSame('OK', $response->status);
        self::assertSame('READ-WRITE', $response->code);
        self::assertSame('SELECT completed', $response->text);
    }

    public function testACodeCarryingAList(): void
    {
        $response = ImapResponseReader::parse("* OK [PERMANENTFLAGS (\\Seen \\Flagged \\*)] Limited\r\n");

        self::assertSame(ImapResponseKind::Untagged, $response->kind);
        self::assertSame('PERMANENTFLAGS', $response->code);
        self::assertSame([['\\Seen', '\\Flagged', '\\*']], $response->codeData);
        self::assertSame('Limited', $response->text);
    }

    /** The one RFC 9051 says a client must show the user. */
    public function testAnAlertKeepsItsText(): void
    {
        $response = ImapResponseReader::parse("* OK [ALERT] Mailbox is over quota\r\n");

        self::assertSame('ALERT', $response->code);
        self::assertSame([], $response->codeData);
        self::assertSame('Mailbox is over quota', $response->text);
    }

    public function testCapabilitiesAreData(): void
    {
        $response = ImapResponseReader::parse("* CAPABILITY IMAP4rev1 IDLE LITERAL+ AUTH=PLAIN\r\n");

        self::assertSame('CAPABILITY', $response->keyword);
        self::assertSame(['IMAP4rev1', 'IDLE', 'LITERAL+', 'AUTH=PLAIN'], $response->data);
    }

    /**
     * NIL is nothing; "NIL" is three letters. A folder can be called NIL, and
     * an address list without a name says NIL for it.
     */
    public function testNilIsNullAndAQuotedNilIsAString(): void
    {
        $response = ImapResponseReader::parse("* LIST (\\HasNoChildren) NIL \"NIL\"\r\n");

        self::assertSame([['\\HasNoChildren'], null, 'NIL'], $response->data);
    }

    public function testAQuotedStringKeepsItsEscapedCharacters(): void
    {
        $response = ImapResponseReader::parse("* LIST () \"/\" \"Say \\\"hi\\\" \\\\ now\"\r\n");

        self::assertSame([[], '/', 'Say "hi" \\ now'], $response->data);
    }

    /**
     * A fetch item's name runs to its closing bracket, spaces and parentheses
     * included, and a partial's `<0>` belongs to it too.
     */
    public function testAFetchItemWithASpaceInsideItsBracketIsOneName(): void
    {
        $response = ImapResponseReader::parse("* 2 FETCH (BODY[HEADER.FIELDS (FROM DATE)]<0> \"x\" UID 9)\r\n");

        self::assertSame(['BODY[HEADER.FIELDS (FROM DATE)]<0>' => 'x', 'UID' => '9'], $response->fetchItems());
    }

    public function testAContinuationKeepsItsText(): void
    {
        $response = ImapResponseReader::parse("+ idling\r\n");

        self::assertSame(ImapResponseKind::Continuation, $response->kind);
        self::assertSame('idling', $response->text);
    }

    public function testABareLineFeedEndsAResponseToo(): void
    {
        $reader = new ImapResponseReader();
        $reader->feed("* 4 EXPUNGE\n");

        self::assertSame('EXPUNGE', $reader->next()?->keyword);
    }

    /** Waited for, a nonsense size is memory running out rather than an error. */
    public function testAnAbsurdLiteralIsRefusedRatherThanWaitedFor(): void
    {
        $reader = new ImapResponseReader();
        $reader->feed(sprintf("* 1 FETCH (BODY[] {%d}\r\n", ImapResponseReader::MAX_LITERAL_BYTES + 1));

        $this->expectException(ImapProtocolException::class);

        $reader->next();
    }

    public function testATaggedLineWithoutAStatusIsNotIMAP(): void
    {
        $this->expectException(ImapProtocolException::class);

        ImapResponseReader::parse("A1 GREETINGS\r\n");
    }
}
