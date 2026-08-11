<?php

declare(strict_types=1);

namespace App\Tests\Command\Imap;

use App\Command\Imap\ImapIdleCommand;
use PHPUnit\Framework\TestCase;

/**
 * Which lines off the IDLE socket mean "a flag changed".
 *
 * The supervisor answers three untagged responses now: EXISTS for an arrival,
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
final class IdleFlagTriggerTest extends TestCase
{
    /** The shape the server actually sends when another client marks mail read. */
    public function testAnUntaggedFetchCarryingFlagsIsAFlagChange(): void
    {
        self::assertTrue(ImapIdleCommand::announcesFlagChange('* 4 FETCH (FLAGS (\\Seen))'));
    }

    public function testAFetchCarryingFlagsAndAUidIsOneToo(): void
    {
        self::assertTrue(ImapIdleCommand::announcesFlagChange('* 12 FETCH (UID 5001 FLAGS (\\Seen \\Flagged))'));
    }

    /** A star set elsewhere is the same announcement. */
    public function testAStarSetElsewhereAnnouncesItself(): void
    {
        self::assertTrue(ImapIdleCommand::announcesFlagChange('* 7 FETCH (FLAGS (\\Flagged))'));
    }

    /**
     * The FLAGS line SELECT answers with. It lists the flags the folder
     * supports, which has nothing to do with any message's state.
     */
    public function testTheFoldersOwnFlagsLineIsNotAFlagChange(): void
    {
        self::assertFalse(
            ImapIdleCommand::announcesFlagChange('* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft)'),
        );
    }

    public function testThePermanentFlagsLineIsNotOneEither(): void
    {
        self::assertFalse(
            ImapIdleCommand::announcesFlagChange('* OK [PERMANENTFLAGS (\\Answered \\Flagged \\Seen \\*)] Limited'),
        );
    }

    /** An arrival is EXISTS, and it has its own branch. */
    public function testAnExistsLineIsNotAFlagChange(): void
    {
        self::assertFalse(ImapIdleCommand::announcesFlagChange('* 23 EXISTS'));
    }

    /** So is a deletion. */
    public function testAnExpungeLineIsNotAFlagChange(): void
    {
        self::assertFalse(ImapIdleCommand::announcesFlagChange('* 4 EXPUNGE'));
    }

    public function testTheIdleContinuationIsNotAFlagChange(): void
    {
        self::assertFalse(ImapIdleCommand::announcesFlagChange('+ idling'));
    }
}
