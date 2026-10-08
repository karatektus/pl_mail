<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\ProviderAcceptTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The moment a provider took delivery, read out of the header it wrote. The
 * shapes below are copied from real mail, because the format is "whatever the
 * server felt like" inside a grammar that allows most things.
 */
final class ProviderAcceptTimeTest extends TestCase
{
    /**
     * @param string|list<string> $received
     */
    #[DataProvider('headers')]
    public function testTheNewestReceivedHeaderGivesTheTime(string|array $received, string $expectedUtc): void
    {
        $accepted = new ProviderAcceptTime()->fromHeaders(['received' => $received]);

        self::assertNotNull($accepted);
        self::assertSame($expectedUtc, gmdate('Y-m-d H:i:s', $accepted->getTimestamp()));
    }

    /**
     * @return iterable<string, array{string|list<string>, string}>
     */
    public static function headers(): iterable
    {
        yield 'Gmail, with a zone comment' => [
            'by 2002:a05:7208:a58f:b0:10d:f003:3eb3 with SMTP id iq15csp261064rbb;        Wed, 7 Oct 2026 05:15:46 -0700 (PDT)',
            '2026-10-07 12:15:46',
        ];

        yield 'several hops: the first is the newest' => [
            [
                'from www313.your-server.de by www313.your-server.de with LMTP id zw/zCkxoxmpNywAAUfZx5Q (envelope-from <job@example.test>); Wed, 07 Oct 2026 17:42:04 +0200',
                'from dd26934.kasserver.com ([85.13.145.212]) by www313.your-server.de with esmtps; Wed, 07 Oct 2026 17:31:00 +0200',
            ],
            '2026-10-07 15:42:04',
        ];

        yield 'a semicolon earlier in the route' => [
            'from a.example.test (a.example.test; helo=a) by mx.example.test with ESMTPS; Tue, 06 Oct 2026 19:53:02 -0700 (PDT)',
            '2026-10-07 02:53:02',
        ];
    }

    public function testTheTimeIsInThisProcessesZoneLikeEveryOtherDateOnTheRow(): void
    {
        $accepted = new ProviderAcceptTime()->fromHeaders(['received' => 'by mx.example.test; Wed, 7 Oct 2026 05:15:46 -0700']);

        self::assertNotNull($accepted);
        self::assertSame(date_default_timezone_get(), $accepted->getTimezone()->getName());
    }

    /**
     * @param array<string, string|list<string>>|null $headers
     */
    #[DataProvider('unusable')]
    public function testMailThatDidNotArriveHasNoAcceptTime(?array $headers): void
    {
        self::assertNull(new ProviderAcceptTime()->fromHeaders($headers));
    }

    /**
     * @return iterable<string, array{array<string, string|list<string>>|null}>
     */
    public static function unusable(): iterable
    {
        yield 'no headers at all'      => [null];
        yield 'a draft: no Received'   => [['subject' => 'Hello']];
        yield 'no date part'           => [['received' => 'by mx.example.test with SMTP']];
        yield 'a date that is not one' => [['received' => 'by mx.example.test; some time last week, probably']];
        yield 'an empty list'          => [['received' => []]];
    }
}
