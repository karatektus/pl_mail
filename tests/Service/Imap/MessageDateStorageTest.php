<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Service\Imap\MessageSyncer;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Header;

/**
 * IMAP has to store the same thing Gmail and Graph do.
 *
 * The whole app leans on one unwritten rule — every timestamp column holds UTC
 * — because the columns are TIMESTAMP WITHOUT TIME ZONE and therefore cannot
 * enforce it. IMAP broke that rule silently: webklex parses the `Date:` header
 * with a bare Carbon::parse() and keeps the sender's offset, and Doctrine
 * formats a DateTimeImmutable in whatever zone it carries, so a +0200 sender's
 * mail landed as their wall clock while a UTC sender's landed as UTC. Nothing
 * failed; the same mailbox just held two different conventions.
 *
 * It was also invisible for as long as rendering was wrong in the opposite
 * direction — dates were formatted in UTC, so the mail that was stored wrong
 * displayed right. That is why this test exists rather than a UI check: the
 * half nobody would notice is this one, and it only shows up once the
 * rendering half is fixed.
 *
 * The assertions go through Doctrine's own type rather than reading the
 * object's fields, because the conversion is where the offset was being lost.
 */
final class MessageDateStorageTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dateHeaders(): iterable
    {
        // The one that was actually wrong: high summer in Berlin.
        yield 'CEST +0200'   => ['Fri, 25 Jul 2025 15:58:46 +0200', '2025-07-25 13:58:46'];
        // Same sender in winter — the offset moved, the rule must not.
        yield 'CET +0100'    => ['Sat, 25 Jan 2025 15:58:46 +0100', '2025-01-25 14:58:46'];
        // Behind UTC, so the date rolls forward as well as the clock.
        yield 'EST -0500'    => ['Sat, 25 Jan 2025 21:30:00 -0500', '2025-01-26 02:30:00'];
        // A half-hour zone: an implementation that rounded to whole hours
        // would pass every case above and fail this one.
        yield 'IST +0530'    => ['Fri, 25 Jul 2025 15:58:46 +0530', '2025-07-25 10:28:46'];
        // Already UTC. Must survive untouched — a conversion that "fixes"
        // this one is applying an offset twice.
        yield 'UTC +0000'    => ['Fri, 25 Jul 2025 13:58:46 +0000', '2025-07-25 13:58:46'];
        yield 'named GMT'    => ['Fri, 25 Jul 2025 13:58:46 GMT', '2025-07-25 13:58:46'];
    }

    #[DataProvider('dateHeaders')]
    public function testHeaderIsStoredAsUtc(string $header, string $expected): void
    {
        $parsed = new Header("Date: {$header}\r\nSubject: Test\r\n", Config::make())
            ->get('date')
            ->toDate();

        self::assertSame(
            $expected,
            new DateTimeImmutableType()->convertToDatabaseValue(
                MessageSyncer::toUtc($parsed),
                new PostgreSQLPlatform(),
            ),
        );
    }

    /**
     * Nails down the failure this guards against, so a future reader can see
     * that the offset really was being kept rather than take it on trust.
     */
    public function testTheUnconvertedHeaderWouldHaveStoredTheSendersWallClock(): void
    {
        $parsed = new Header("Date: Fri, 25 Jul 2025 15:58:46 +0200\r\nSubject: Test\r\n", Config::make())
            ->get('date')
            ->toDate();

        self::assertSame('+02:00', $parsed->format('P'));

        self::assertSame(
            '2025-07-25 15:58:46',
            new DateTimeImmutableType()->convertToDatabaseValue(
                \DateTimeImmutable::createFromInterface($parsed),
                new PostgreSQLPlatform(),
            ),
        );
    }

    /**
     * The instant is what has to survive; the zone it is expressed in is
     * presentation. Asserting both keeps a "fix" that shifts the clock instead
     * of relabelling it from passing.
     */
    public function testTheInstantIsUnchanged(): void
    {
        $parsed = new Header("Date: Fri, 25 Jul 2025 15:58:46 +0200\r\nSubject: Test\r\n", Config::make())
            ->get('date')
            ->toDate();

        $stored = MessageSyncer::toUtc($parsed);

        self::assertSame($parsed->getTimestamp(), $stored->getTimestamp());
        self::assertSame('UTC', $stored->getTimezone()->getName());
    }
}
