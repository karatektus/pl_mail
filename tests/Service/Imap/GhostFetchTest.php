<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Helper\MessageIdHelper;
use App\Domain\Helper\MessageParser;
use App\Service\Imap\MessageSyncer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where the seven blank rows in Spam came from.
 *
 * A FETCH does not have to fail loudly. When the header block comes back empty
 * — a connection that hiccuped mid-response, a server answering NIL, MIME the
 * parser could make nothing of — the parser raises nothing at all. It hands
 * back a message with nothing in it: no sender, no subject, no date. webklex
 * went further and dated it 1 January 1970.
 *
 * MessageSyncer::buildMessage() therefore assembled a complete Message out of
 * nothing and persisted it, because the only thing that made it skip a message
 * was a Throwable and nothing threw. The result was a row that is a message as
 * far as the schema is concerned: unread, because nothing set seenAt, and so
 * counted in the Spam badge — which is how seven of them became the entire
 * badge with no mail behind it.
 *
 * The first test below pins the parser behaviour the bug rests on, so that the
 * guard's reason is on record rather than mysterious.
 */
final class GhostFetchTest extends TestCase
{
    // ── the parser behaviour the ghosts rested on ─────────────────────────

    /**
     * Nothing complains: an empty fetch parses, into blanks throughout.
     */
    public function testAnEmptyFetchParsesToAMessageWithNothingInIt(): void
    {
        $parsed = MessageParser::parse('');

        self::assertSame('', $parsed->subject);
        self::assertSame('', $parsed->messageId);
        self::assertNull($parsed->from);
        self::assertNull($parsed->date);
        self::assertFalse($parsed->hasDateHeader);
    }

    // ── the guard ─────────────────────────────────────────────────────────

    /**
     * The fetch that produced the ghosts is now refused. This is the assertion
     * that the bug cannot come back.
     */
    public function testAFetchThatCarriedNothingIsRefused(): void
    {
        self::assertFalse(
            MessageSyncer::describesAMessage('', '', false),
            'a fetch with no Message-ID, no sender and no Date is not a message',
        );
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function realMessages(): iterable
    {
        // The case the report called out by name. No subject is not no message.
        yield 'subjectless mail'      => ['<c@d.test>', 'kunde@example.test', true];
        // Any one of the three is enough, because real mail always has at
        // least one and a failed fetch has none.
        yield 'only a Message-ID'     => ['<a@b.test>', '', false];
        yield 'only a sender'         => ['', 'kunde@example.test', false];
        yield 'only a Date'           => ['', '', true];
        yield 'no Date header'        => ['<a@b.test>', 'kunde@example.test', false];
        yield 'sender unparseable'    => ['<a@b.test>', '', true];
    }

    #[DataProvider('realMessages')]
    public function testRealMailIsAccepted(string $messageId, string $from, bool $hasDate): void
    {
        self::assertTrue(
            MessageSyncer::describesAMessage($messageId, $from, $hasDate),
            'this is mail and must be ingested',
        );
    }

    /**
     * A real header, end to end, so the provider above cannot drift away from
     * what the parser actually produces for ordinary mail.
     */
    public function testAnOrdinarySubjectlessHeaderIsAccepted(): void
    {
        $parsed = MessageParser::parse(
            "From: Kunde <kunde@example.test>\r\n"
            . "Message-ID: <c@d.test>\r\n"
            . "Date: Fri, 25 Jul 2025 13:58:46 +0000\r\n",
        );

        self::assertTrue(MessageSyncer::describesAMessage(
            MessageIdHelper::normalise($parsed->messageId),
            (string) $parsed->from?->address,
            $parsed->hasDateHeader,
        ));
    }
}
