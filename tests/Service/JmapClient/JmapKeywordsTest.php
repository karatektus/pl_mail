<?php

declare(strict_types=1);

namespace App\Tests\Service\JmapClient;

use App\Domain\Enum\Mail\MessageFlag;
use App\Service\JmapClient\JmapKeywords;
use PHPUnit\Framework\TestCase;

/**
 * Keywords become the flags mirror in IMAP's spelling, and back.
 *
 * The mirror is compared, canonicalised, against the next server answer by
 * ThreadStatusUpdater. A mirror kept in JMAP's spelling would differ from
 * itself on every pass and record a JMAP change for every row of every sync.
 */
final class JmapKeywordsTest extends TestCase
{
    public function testSystemKeywordsReadAsTheirImapFlags(): void
    {
        self::assertSame(
            MessageFlag::canonicalList(['\\Seen', '\\Flagged', '\\Answered', '\\Draft']),
            JmapKeywords::toFlags(['$seen' => true, '$flagged' => true, '$answered' => true, '$draft' => true]),
        );
    }

    /** RFC 8621 makes keywords case-insensitive; a server that writes $Seen still means read. */
    public function testKeywordsAreMatchedWithoutRegardToCase(): void
    {
        self::assertTrue(JmapKeywords::has(['$Seen' => true], MessageFlag::SEEN));
        self::assertSame([MessageFlag::SEEN->value], JmapKeywords::toFlags(['$SEEN' => true]));
    }

    public function testAKeywordWithNoImapTwinIsKeptVerbatim(): void
    {
        self::assertSame(['$forwarded'], JmapKeywords::toFlags(['$forwarded' => true]));
    }

    public function testRecentHasNoKeyword(): void
    {
        self::assertNull(JmapKeywords::fromFlag(MessageFlag::RECENT));
        self::assertSame('$seen', JmapKeywords::fromFlag(MessageFlag::SEEN));
    }
}
