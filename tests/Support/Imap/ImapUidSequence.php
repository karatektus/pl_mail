<?php

declare(strict_types=1);

namespace App\Tests\Support\Imap;

/**
 * A UID no other fixture in this process will pick.
 *
 * `message` is unique on (mailbox_id, imap_uid), and fixtures used to satisfy
 * that with `random_int()` over a few hundred or a few thousand values. That is
 * a birthday problem wearing a disguise: the odds are not "one in nine
 * thousand" but the odds that any TWO of the messages a test seeds collide, and
 * one of these ranges was 999 wide.
 *
 * It duly failed in CI — GhostMessageReaperTest, on
 * "duplicate key value violates unique constraint
 * uniq_message_mailbox_imap_uid" — and would never have reproduced locally on
 * demand, because there is nothing to reproduce: it is a dice roll, and the
 * dice are rolled afresh on every run.
 *
 * A counter cannot collide. Per process is enough: every one of these tests
 * runs inside a transaction it rolls back, so nothing survives to collide with
 * the next process's numbers.
 */
final class ImapUidSequence
{
    /** High enough not to look like a fixture that means something. */
    private static int $next = 100_000;

    public static function next(): int
    {
        return self::$next++;
    }
}
