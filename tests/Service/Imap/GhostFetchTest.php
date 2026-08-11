<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Service\Imap\MessageSyncer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Header;

/**
 * Where the seven blank rows in Spam came from.
 *
 * A FETCH does not have to fail loudly. When the header block comes back empty
 * — a connection that hiccuped mid-response, a server answering NIL, MIME the
 * parser could make nothing of — webklex raises nothing at all. It hands back
 * an empty Attribute for every field, and an empty Attribute is quietly
 * agreeable: '' for a string, `false` for an address, and, through
 * Carbon::parse(false), 1 January 1970 for a date.
 *
 * MessageSyncer::buildMessage() therefore assembled a complete Message out of
 * nothing and persisted it, because the only thing that made it skip a message
 * was a Throwable and nothing threw. The result was a row that is a message as
 * far as the schema is concerned: unread, because nothing set seenAt, and so
 * counted in the Spam badge — which is how seven of them became the entire
 * badge with no mail behind it.
 *
 * The first two tests below pin the vendor behaviour the bug rested on, so that
 * if a webklex upgrade ever makes an empty header throw instead, the guard's
 * reason is on record rather than mysterious.
 */
final class GhostFetchTest extends TestCase
{
    private static function header(string $raw): Header
    {
        return new Header($raw, Config::make());
    }

    // ── the vendor behaviour the ghosts rested on ─────────────────────────

    /**
     * The epoch, out of nowhere. This single line is the 1970-01-01 the report
     * described, and it is why the reaper looks for an epoch date rather than a
     * null one: the row really does hold a timestamp.
     */
    public function testAnEmptyHeaderParsesToTheEpochRatherThanFailing(): void
    {
        self::assertSame(
            '1970-01-01T00:00:00+00:00',
            self::header('')->get('date')->toDate()->toIso8601String(),
            'an empty Date attribute goes through Carbon::parse(false) and lands on the epoch',
        );
    }

    /**
     * The secondary defect: buildMessage() guarded the sender with
     * `null !== $from`, and an empty address Attribute answers `false`. So the
     * guard passed, and the code went on to read `->mail` off a boolean.
     */
    public function testAnEmptyFromIsFalseRatherThanNull(): void
    {
        $from = self::header('')->get('from')->first();

        self::assertFalse($from, 'the empty-address sentinel is false');
        self::assertNotNull($from, 'which is exactly why a null check did not catch it');
    }

    /**
     * Nothing else complains either — the whole profile arrives without a
     * single error, which is what made this silent.
     */
    public function testAnEmptyHeaderYieldsBlanksThroughout(): void
    {
        $header = self::header('');

        self::assertSame('', (string) $header->get('subject'));
        self::assertSame('', (string) $header->get('message_id'));
        self::assertSame([], $header->get('date')->toArray());
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
        $header = self::header(
            "From: Kunde <kunde@example.test>\r\n"
            . "Message-ID: <c@d.test>\r\n"
            . "Date: Fri, 25 Jul 2025 13:58:46 +0000\r\n",
        );

        $from = $header->get('from')->first();

        self::assertTrue(MessageSyncer::describesAMessage(
            (string) $header->get('message_id'),
            (string) ($from->mail ?? ''),
            [] !== $header->get('date')->toArray(),
        ));
    }
}
