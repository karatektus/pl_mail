<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Query\WhereQuery;

/**
 * The IMAP search command a UID range actually produces.
 *
 * `where('UID', '1:*')` is the obvious spelling and it is wrong.
 * Query::generate_query() only emits a value unquoted when `is_numeric()`
 * agrees, and a range is not numeric — so it went out as `UID "1:*"` and every
 * server refused it. Dovecot says `BAD expected DIGIT instead of '"'`, which
 * names the quote but not the cause, and it surfaced only as "mailbox sync
 * failed" repeating in the log.
 *
 * It applied to every incremental sync, since those all ask for
 * `<lastSeenUid + 1>:*`. A first sync of a single message would have worked,
 * which is roughly the worst way for a bug like this to behave.
 *
 * This asserts the generated command rather than mocking a server: the command
 * string is the thing that was wrong, and it is what a library upgrade would
 * silently change again.
 */
final class UidRangeQueryTest extends TestCase
{
    private static function query(): WhereQuery
    {
        // Never connected — generate_query() only reads config, and only for
        // the SINCE/BEFORE date option this does not use.
        return new WhereQuery(new Client(Config::make()));
    }

    public function testAUidRangeIsSentUnquoted(): void
    {
        $criteria = self::callUidRangeCriteria('42:*');

        self::assertSame('UID 42:*', self::query()->where($criteria)->generate_query());
    }

    /**
     * The spelling that broke it, kept as a test so the reason is visible: if
     * this ever stops quoting, the workaround above can go.
     */
    public function testThePlainSpellingIsStillQuotedByTheLibrary(): void
    {
        self::assertSame(
            'UID "42:*"',
            self::query()->where('UID', '42:*')->generate_query(),
            'library no longer quotes ranges — MessageSyncer::uidRangeCriteria can be dropped',
        );
    }

    public function testASingleUidWasNeverAffected(): void
    {
        // is_numeric() is true here, which is why a first sync of one message
        // worked and hid the problem.
        self::assertSame('UID 42', self::query()->where('UID', '42')->generate_query());
    }

    /** Reaches the private helper the syncer uses, without duplicating it. */
    private static function callUidRangeCriteria(string $range): string
    {
        $method = new \ReflectionMethod(\App\Service\Imap\MessageSyncer::class, 'uidRangeCriteria');

        return $method->invoke(null, $range);
    }
}
