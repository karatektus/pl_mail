<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\JmapClient;

use App\Infrastructure\JmapClient\JmapEventStream;
use PHPUnit\Framework\TestCase;

/**
 * The EventSource framing, fed the way a network feeds it: in pieces.
 *
 * The case worth a test is the chunk cut mid-line. An event parsed before its
 * blank line arrives is half an event — a StateChange whose JSON ends where
 * the packet did, which decodes to nothing and is a push silently lost.
 */
final class JmapEventStreamTest extends TestCase
{
    public function testAnEventCutAcrossChunksIsReadOnceItIsWhole(): void
    {
        $stream = new JmapEventStream();
        $change = '{"@type":"StateChange","changed":{"u1":{"Email":"s9"}}}';

        self::assertSame([], $stream->feed("event: state\ndata: " . substr($change, 0, 20)));

        $events = $stream->feed(substr($change, 20) . "\n\n");

        self::assertSame([['event' => 'state', 'data' => $change]], $events);
        self::assertSame(['u1' => ['Email' => 's9']], JmapEventStream::stateChanges($events)[0]['changed']);
    }

    public function testPingsAndCommentsAreNotStateChanges(): void
    {
        $events = new JmapEventStream()->feed(": keep-alive\r\n\r\nevent: ping\r\ndata: {\"interval\":60}\r\n\r\n");

        self::assertSame([['event' => 'ping', 'data' => '{"interval":60}']], $events);
        self::assertSame([], JmapEventStream::stateChanges($events));
    }

    public function testMultiLineDataIsJoined(): void
    {
        $events = new JmapEventStream()->feed("event: state\ndata: {\"changed\":\ndata: {}}\n\n");

        self::assertSame("{\"changed\":\n{}}", $events[0]['data']);
    }
}
