<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\DTO\Mail\ImapFlagNotice;
use App\Infrastructure\ImapClient\ImapResponseReader;
use App\Service\Imap\ImapIdleListener;
use PHPUnit\Framework\TestCase;

/**
 * Which lines off the IDLE socket mean "a flag changed".
 *
 * The IDLE listener answers three untagged responses: EXISTS for an arrival,
 * EXPUNGE for a deletion, and FETCH-with-FLAGS for a flag another client
 * changed. All three are triggers rather than authorities — none of them
 * resolves the sequence number it carries into a UID, and each simply makes the
 * folder's own machinery run now instead of on its cadence.
 *
 * The distinction pinned here is the one that costs a round trip when it is
 * wrong: SELECT answers every folder open with FLAGS and PERMANENTFLAGS lines,
 * which describe what the folder permits and say nothing about any message. A
 * matcher that woke on those would re-list a folder every time one was opened.
 */
final class IdleFlagChangeTest extends TestCase
{
    /** The shape the server actually sends when another client marks mail read. */
    public function testAnUntaggedFetchCarryingFlagsIsAFlagChange(): void
    {
        self::assertNotNull(self::read('* 4 FETCH (FLAGS (\\Seen))'));
    }

    public function testAFetchCarryingFlagsAndAUidIsOneToo(): void
    {
        self::assertNotNull(self::read('* 12 FETCH (UID 5001 FLAGS (\\Seen \\Flagged))'));
    }

    /** A star set elsewhere is the same announcement. */
    public function testAStarSetElsewhereAnnouncesItself(): void
    {
        self::assertNotNull(self::read('* 7 FETCH (FLAGS (\\Flagged))'));
    }

    /**
     * The FLAGS line SELECT answers with. It lists the flags the folder
     * supports, which has nothing to do with any message's state.
     */
    public function testTheFoldersOwnFlagsLineIsNotAFlagChange(): void
    {
        self::assertNull(self::read('* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft)'));
    }

    public function testThePermanentFlagsLineIsNotOneEither(): void
    {
        self::assertNull(self::read('* OK [PERMANENTFLAGS (\\Answered \\Flagged \\Seen \\*)] Limited'));
    }

    /** An arrival is EXISTS, and it has its own branch. */
    public function testAnExistsLineIsNotAFlagChange(): void
    {
        self::assertNull(self::read('* 23 EXISTS'));
    }

    /** So is a deletion. */
    public function testAnExpungeLineIsNotAFlagChange(): void
    {
        self::assertNull(self::read('* 4 EXPUNGE'));
    }

    public function testTheIdleContinuationIsNotAFlagChange(): void
    {
        self::assertNull(self::read('+ idling'));
    }

    /**
     * The line names the message, so nothing has to be asked of the server.
     *
     * This is the whole of the fix. The old code read exactly this line, learnt
     * only that "something about flags happened", and answered it by opening an
     * IMAP session and re-reading every UID in the folder to rediscover what
     * the line had just told it.
     */
    public function testAFlagChangeCarryingAUidIsReadRatherThanRecognised(): void
    {
        $notice = self::read('* 12 FETCH (UID 5001 FLAGS (\\Seen \\Flagged))');

        self::assertNotNull($notice);
        self::assertTrue($notice->isResolvable());
        self::assertSame(5001, $notice->uid);
        self::assertSame(12, $notice->sequence);
        self::assertTrue($notice->hasFlag('\\Seen'));
        self::assertTrue($notice->hasFlag('\\Flagged'));
    }

    /**
     * Without a UID there is only a POSITION, and positions move under every
     * expunge — so this must NOT be resolvable, and the caller falls back to
     * the listing rather than writing somebody else's flags onto the wrong
     * message.
     */
    public function testAFlagChangeWithoutAUidIsNotResolvable(): void
    {
        $notice = self::read('* 4 FETCH (FLAGS (\\Seen))');

        self::assertNotNull($notice);
        self::assertFalse($notice->isResolvable());
        self::assertNull($notice->uid);
    }

    /**
     * `FLAGS ()` is a real notification and the one that most wants to arrive
     * quickly: every flag cleared is "marked unread on my phone". Read as an
     * empty list, never as nothing to do — the server sends the COMPLETE set,
     * so absence is the fact.
     */
    public function testAnEmptyFlagListIsAChangeAndNotAnAbsence(): void
    {
        $notice = self::read('* 9 FETCH (UID 77 FLAGS ())');

        self::assertNotNull($notice);
        self::assertTrue($notice->isResolvable());
        self::assertSame([], $notice->flags);
        self::assertFalse($notice->hasFlag('\\Seen'));
    }

    /**
     * The two lines every SELECT answers with describe what the folder PERMITS,
     * not what any message now has. Reading one as a flag change is a folder
     * listing that learns nothing — and under the old substring test the only
     * thing keeping them out was that neither happens to contain "FETCH".
     */
    public function testTheFolderCapabilityLinesAreStillNotFlagChanges(): void
    {
        self::assertNull(self::read('* FLAGS (\\Answered \\Flagged \\Deleted \\Seen)'));
        self::assertNull(self::read('* OK [PERMANENTFLAGS (\\Answered \\Seen \\*)] Limited'));
        self::assertNull(self::read('* 23 EXISTS'));
    }

    /** A line as the server sends it, read the way the listener reads it. */
    private static function read(string $line): ?ImapFlagNotice
    {
        return ImapIdleListener::flagChange(ImapResponseReader::parse($line . "\r\n"));
    }
}
