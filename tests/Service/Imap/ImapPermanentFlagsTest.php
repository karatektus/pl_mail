<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Service\Imap\ImapPermanentFlags;
use PHPUnit\Framework\TestCase;

/**
 * Reading `\*` out of PERMANENTFLAGS, on the shapes webklex hands back from
 * the servers #221 was checked against (#221 step 2).
 */
final class ImapPermanentFlagsTest extends TestCase
{
    /** Stalwart 0.11: `* OK [PERMANENTFLAGS (\Deleted … \*)] All allowed` */
    public function testAServerThatTakesNewKeywordsSaysSo(): void
    {
        self::assertTrue(ImapPermanentFlags::fromResponse([
            ['1', 'EXISTS'],
            ['FLAGS', ['\\Answered', '\\Flagged', '\\Deleted', '\\Seen', '\\Draft']],
            ['OK', '[PERMANENTFLAGS', ['\\Deleted', '\\Seen', '\\Answered', '\\Flagged', '\\Draft', '\\*)]', 'All', 'allowed']],
            ['OK', '[UIDVALIDITY', '4079336918]', 'UIDs', 'valid'],
        ]));
    }

    /** A folder that keeps only the system flags. */
    public function testOnlySystemFlagsIsNo(): void
    {
        self::assertFalse(ImapPermanentFlags::fromResponse([
            ['OK', '[PERMANENTFLAGS', ['\\Deleted', '\\Seen', '\\Answered', '\\Flagged', '\\Draft)]', 'Limited']],
        ]));
    }

    /** Dovecot on a read-only selection: an empty list, which is a "no". */
    public function testAnEmptyListIsNo(): void
    {
        self::assertFalse(ImapPermanentFlags::fromResponse([
            ['OK', '[PERMANENTFLAGS', [')]', 'Read-only', 'mailbox.']],
        ]));
    }

    /** No PERMANENTFLAGS code at all is not an answer. */
    public function testSilenceIsUnknown(): void
    {
        self::assertNull(ImapPermanentFlags::fromResponse([
            ['1', 'EXISTS'],
            ['OK', '[UIDVALIDITY', '1]', 'UIDs', 'valid'],
        ]));
    }
}
