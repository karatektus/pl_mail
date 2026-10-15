<?php

declare(strict_types=1);

namespace App\Tests\Command\Imap;

use App\Command\Imap\ImapIdleCommand;
use PHPUnit\Framework\TestCase;

/**
 * A closed IDLE connection is not a quiet one.
 *
 * The library answers both with the same "empty response", and the loop took
 * every one for a mailbox with nothing to say. On a connection the server had
 * closed, each read then came back empty at once and the loop spun a core
 * until the 29-minute re-issue (#94 — Lark and QQ close idle sessions within
 * minutes, so for them this was the normal case).
 *
 * Real sockets, because the claim is about what a stream reports after its
 * peer has gone, and a stub would only report what it was told to.
 */
final class IdleClosedConnectionTest extends TestCase
{
    public function testAStreamWhosePeerHasClosedIsEnded(): void
    {
        [$ours, $theirs] = $this->pair();

        fclose($theirs);
        fread($ours, 1);

        // However long the read is said to have taken: end-of-file settles it.
        self::assertTrue(ImapIdleCommand::connectionEnded($ours, 30.0));
    }

    /** The quiet-mailbox case, which must go on being a heartbeat and nothing more. */
    public function testAnOpenStreamThatTimedOutIsNotEnded(): void
    {
        [$ours, $theirs] = $this->pair();

        stream_set_timeout($ours, 0, 50_000);
        $read = fread($ours, 1);

        self::assertContains($read, ['', false], 'the read gave up with nothing');
        self::assertTrue(stream_get_meta_data($ours)['timed_out'], 'and it gave up because of the clock');

        self::assertFalse(ImapIdleCommand::connectionEnded($ours, 30.0));

        fclose($theirs);
    }

    /**
     * The backstop. A TLS stream does not always say end-of-file for a peer
     * that went away, but a read meant to block for thirty seconds cannot
     * come back empty in a millisecond on a connection that is alive.
     */
    public function testAnEmptyReadThatCameBackAtOnceIsEndedWhateverTheStreamSays(): void
    {
        [$ours, $theirs] = $this->pair();

        self::assertTrue(ImapIdleCommand::connectionEnded($ours, 0.002));
        self::assertFalse(ImapIdleCommand::connectionEnded($ours, 29.9));

        fclose($theirs);
    }

    public function testSomethingThatIsNoLongerAStreamIsEnded(): void
    {
        [$ours, $theirs] = $this->pair();

        fclose($ours);
        fclose($theirs);

        self::assertTrue(ImapIdleCommand::connectionEnded($ours, 30.0));
        self::assertTrue(ImapIdleCommand::connectionEnded(null, 30.0));
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        self::assertIsArray($pair);

        return $pair;
    }
}
