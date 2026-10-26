<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\RepeatedSingletonHeaders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The first of each once-only header stays, and every other line is left as
 * it came — the repair exists so the parser survives, not to tidy the mail.
 * Every header below is invented.
 */
final class RepeatedSingletonHeadersTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function headers(): array
    {
        return [
            'three Date lines keep the first (#219)' => [
                "From: a@example.test\r\nDate: Mon, 19 Oct 2026 10:00:00 +0000\r\nDate: Mon, 19 Oct 2026 09:00:00 +0000\r\nSubject: hi\r\nDate: Sun, 18 Oct 2026 23:00:00 +0000\r\n",
                "From: a@example.test\r\nDate: Mon, 19 Oct 2026 10:00:00 +0000\r\nSubject: hi\r\n",
            ],
            'a repeated header goes with its folded lines' => [
                "References: <a@example.test>\r\n <b@example.test>\r\nReferences: <c@example.test>\r\n\t<d@example.test>\r\nTo: b@example.test\r\n",
                "References: <a@example.test>\r\n <b@example.test>\r\nTo: b@example.test\r\n",
            ],
            'names compare without case' => [
                "Message-ID: <one@example.test>\r\nmessage-id: <two@example.test>\r\nIN-REPLY-TO: <x@example.test>\r\nIn-Reply-To: <y@example.test>\r\n",
                "Message-ID: <one@example.test>\r\nIN-REPLY-TO: <x@example.test>\r\n",
            ],
            'headers the parser reads as lists are all kept' => [
                "Received: from a\r\nReceived: from b\r\nReceived: from c\r\nTo: a@example.test\r\nTo: b@example.test\r\n",
                "Received: from a\r\nReceived: from b\r\nReceived: from c\r\nTo: a@example.test\r\nTo: b@example.test\r\n",
            ],
            'bare line feeds and no final break come back as they were' => [
                "Date: Mon, 19 Oct 2026 10:00:00 +0000\nX-Note: kept\nDate: Mon, 19 Oct 2026 09:00:00 +0000",
                "Date: Mon, 19 Oct 2026 10:00:00 +0000\nX-Note: kept\n",
            ],
        ];
    }

    #[DataProvider('headers')]
    public function testItKeepsTheFirstOfEachSingleton(string $raw, string $expected): void
    {
        self::assertSame($expected, RepeatedSingletonHeaders::keepFirst($raw));
    }

    public function testAHeaderWithNoRepeatComesBackByteForByte(): void
    {
        $raw = "From: a@example.test\r\nDate: Mon, 19 Oct 2026 10:00:00 +0000\r\nSubject: =?utf-8?q?hi?=\r\n\r\n";

        self::assertSame($raw, RepeatedSingletonHeaders::keepFirst($raw));
    }
}
