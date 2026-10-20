<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Exceptions\GetMessagesFailedException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;

/**
 * What ImapQuery corrects in the library's answers (#219): a message whose
 * header the parser chokes on costs that message and no more, and a server's
 * "no such message" for a range is read as the nothing it means.
 *
 * Through ScriptedBuildQuery, which parses with webklex's own Header but needs
 * no server. Every header here is invented.
 */
final class ImapQueryTest extends TestCase
{
    private const string FIRST_DATE = 'Mon, 19 Oct 2026 10:00:00 +0000';

    /**
     * @return array<string, array{int}>
     */
    public static function repeatedDates(): array
    {
        // Two Date lines make Carbon refuse "trailing data", three make the
        // parser hand preg_match() an array: the two failures seen.
        return ['two Date lines' => [2], 'three Date lines' => [3]];
    }

    #[DataProvider('repeatedDates')]
    public function testAMessageWithARepeatedDateArrivesDatedByTheFirst(int $dates): void
    {
        $query = ScriptedBuildQuery::scripted()->setSoftFail(true);
        $header = $this->header($dates);

        $message = $query->makeMessage(7, $header);

        self::assertNotNull($message, 'the message is built');
        self::assertSame('2026-10-19 10:00:00', $message->getDate()->first()->format('Y-m-d H:i:s'), 'dated by the first Date line');
        self::assertSame([], $query->errors(), 'and nothing is recorded against it');
    }

    public function testTheRawHeaderIsTheOneTheServerSent(): void
    {
        $query = ScriptedBuildQuery::scripted()->setSoftFail(true);
        $header = $this->header(3);

        $message = $query->makeMessage(7, $header);

        self::assertSame($header, $message?->getHeader()?->raw, 'the .eml keeps every Date line');
        self::assertCount(2, $query->builtFrom, 'read once as sent, once repaired');
        self::assertSame(1, substr_count($query->builtFrom[1], 'Date:'), 'the repaired copy has one Date');
    }

    public function testAnOrdinaryMessageIsBuiltOnceAndUntouched(): void
    {
        $query = ScriptedBuildQuery::scripted()->setSoftFail(true);
        $header = $this->header(1);

        $message = $query->makeMessage(7, $header);

        // Message::fromString(), which the stand-in builds with, keeps the
        // header without its final line break; nothing here touched it.
        self::assertSame(rtrim($header, "\r\n"), $message?->getHeader()?->raw);
        self::assertCount(1, $query->builtFrom, 'no second parse when the first one worked');
    }

    /**
     * @return array<string, array{\Throwable}>
     */
    public static function parserFailures(): array
    {
        return [
            'a TypeError'                => [new \TypeError('explode(): Argument #2 ($string) must be of type string, array given')],
            'a ValueError'               => [new \ValueError('mb_convert_encoding(): Argument #3 ($from_encoding) contains invalid encoding "x-unknown"')],
            'an InvalidArgumentException' => [new \InvalidArgumentException('Trailing data')],
        ];
    }

    /**
     * A header no repair mends is recorded against its UID, the way the
     * library records its own failures, which is what ImapPagedFetch hands on
     * for MessageSyncer to retry and then pass over.
     */
    #[DataProvider('parserFailures')]
    public function testAMessageTheParserStillCannotReadIsRecordedAgainstItsUid(\Throwable $failure): void
    {
        $query = ScriptedBuildQuery::scripted()->setSoftFail(true);
        $query->buildFailure = $failure;

        self::assertNull($query->makeMessage(7, $this->header(3)));

        $errors = $query->errors();
        self::assertSame([7], array_keys($errors));
        self::assertSame($failure, $errors[7]->getPrevious(), 'with what the parser threw as the cause');
    }

    public function testWithoutSoftFailItEndsTheFetchAsTheLibrarysOwnFailuresDo(): void
    {
        $query = ScriptedBuildQuery::scripted()->setSoftFail(false);
        $query->buildFailure = new \TypeError('array given');

        $this->expectException(GetMessagesFailedException::class);

        $query->makeMessage(7, $this->header(1));
    }

    /**
     * The library's own exceptions are about the connection, not the message,
     * and are left to mean what they mean.
     */
    public function testALibraryExceptionPassesThroughUntouched(): void
    {
        $query = ScriptedBuildQuery::scripted()->setSoftFail(true);
        $query->buildFailure = new ImapServerErrorException('BYE server shutting down');

        $this->expectException(ImapServerErrorException::class);

        $query->makeMessage(7, $this->header(1));
    }

    public function testNoSuchMessageIsAnEmptyResultWhenAskedFor(): void
    {
        $query = ScriptedBuildQuery::scripted()->emptyWhenNoSuchMessage();
        $query->searchFailure = new ImapServerErrorException('NO no such message');

        self::assertSame([], $query->search()->all());
    }

    public function testNoSuchMessageStillFailsASearchThatDidNotAskForThat(): void
    {
        $query = ScriptedBuildQuery::scripted();
        $query->searchFailure = new ImapServerErrorException('NO no such message');

        $this->expectException(ImapServerErrorException::class);

        $query->search();
    }

    public function testAnyOtherRefusalStillFailsTheSearch(): void
    {
        $query = ScriptedBuildQuery::scripted()->emptyWhenNoSuchMessage();
        $query->searchFailure = new ImapServerErrorException('NO mailbox is locked');

        $this->expectException(ImapServerErrorException::class);

        $query->search();
    }

    public function testASearchThatFindsSomethingIsUnchanged(): void
    {
        $query = ScriptedBuildQuery::scripted()->emptyWhenNoSuchMessage();
        $query->found = [3, 4];

        self::assertSame([3, 4], $query->search()->all());
    }

    private function header(int $dates): string
    {
        $header = "From: sender@example.test\r\nSubject: Hello\r\nMessage-ID: <one@example.test>\r\n"
            . sprintf("Date: %s\r\n", self::FIRST_DATE);

        for ($i = 1; $i < $dates; ++$i) {
            $header .= sprintf("Date: Sun, 18 Oct 2026 %02d:00:00 +0000\r\n", 20 + $i);
        }

        return $header . "Content-Type: text/plain; charset=utf-8\r\n";
    }
}
