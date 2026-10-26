<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\ImapConnectionFactory;
use App\Domain\Helper\MessageParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Message;

/**
 * A message string whose Date line repeats is parsed, dated by the first, and
 * keeps the header it arrived with (#357). Every header below is invented.
 */
final class MessageParserTest extends TestCase
{
    /**
     * Two lines are joined into a date Carbon refuses; three or more become an
     * array that preg_match() refuses. Both fail Message::fromString().
     *
     * @return array<string, array{int}>
     */
    public static function repeats(): array
    {
        return ['twice' => [2], 'three times' => [3]];
    }

    #[DataProvider('repeats')]
    public function testARepeatedDateIsReadAsTheFirst(int $times): void
    {
        $header = "From: a@example.test\r\nSubject: Dated " . $times . " times\r\n";

        for ($hour = 10; $hour < 10 + $times; ++$hour) {
            $header .= 'Date: Mon, 19 Oct 2026 ' . $hour . ":00:00 +0000\r\n";
        }

        $blob = $header . "\r\nBody.\r\n";

        $failed = false;

        try {
            Message::fromString($blob, ImapConnectionFactory::config());
        } catch (\Throwable) {
            $failed = true;
        }

        self::assertTrue($failed, 'the library alone cannot read it, or this test proves nothing');

        $message = MessageParser::fromString($blob, ImapConnectionFactory::config());

        self::assertSame('Dated ' . $times . ' times', (string) $message->getSubject());
        self::assertSame('2026-10-19 10:00:00', $message->getDate()->toDate()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame(rtrim($header, "\r\n"), $message->getHeader()?->raw, 'every line the message arrived with');
        self::assertStringContainsString('Body.', $message->getTextBody());
    }

    public function testAMessageTheLibraryReadsIsParsedAsItCame(): void
    {
        $blob = "From: a@example.test\nSubject: Plain\nDate: Mon, 19 Oct 2026 10:00:00 +0000\n\nBody.\n";

        $message = MessageParser::fromString($blob, ImapConnectionFactory::config());

        self::assertSame('Plain', (string) $message->getSubject());
        self::assertSame(
            Message::fromString($blob, ImapConnectionFactory::config())->getHeader()?->raw,
            $message->getHeader()?->raw,
        );
    }
}
