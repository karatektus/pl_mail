<?php

declare(strict_types=1);

namespace App\Tests\Service\Demo;

use App\Service\Demo\DemoVisitorFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * What is stored in place of a demo visitor's address: enough to count them
 * once, and not enough to say who they were.
 */
final class DemoVisitorFingerprintTest extends TestCase
{
    public function testTheSameAddressCountsAsTheSameVisitor(): void
    {
        $fingerprint = new DemoVisitorFingerprint('secret');

        self::assertSame($fingerprint->of('203.0.113.57'), $fingerprint->of('203.0.113.57'));
        self::assertNotSame($fingerprint->of('203.0.113.57'), $fingerprint->of('198.51.100.57'));
    }

    /**
     * The property the whole class is for. If two addresses that differ only in
     * the part that was cut off hashed differently, the full address would have
     * gone into the hash, and a keyed hash of a full IPv4 address can be read
     * back by anybody holding the key and an afternoon.
     */
    public function testTheAddressIsCutDownBeforeItIsHashed(): void
    {
        $fingerprint = new DemoVisitorFingerprint('secret');

        self::assertSame($fingerprint->of('203.0.113.57'), $fingerprint->of('203.0.113.200'));
        self::assertSame(
            $fingerprint->of('2001:db8:1234:5678:9abc:def0:1234:5678'),
            $fingerprint->of('2001:db8:1234:ffff::1'),
        );
    }

    public function testTheHashDoesNotContainTheAddress(): void
    {
        $hash = (string) new DemoVisitorFingerprint('secret')->of('203.0.113.57');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        self::assertStringNotContainsString('203.0.113', $hash);
    }

    /** Another instance's table says nothing about this one's visitors. */
    public function testTheKeyIsPartOfIt(): void
    {
        self::assertNotSame(
            new DemoVisitorFingerprint('one')->of('203.0.113.57'),
            new DemoVisitorFingerprint('two')->of('203.0.113.57'),
        );
    }

    public function testNoAddressIsNoFingerprint(): void
    {
        $fingerprint = new DemoVisitorFingerprint('secret');

        self::assertNull($fingerprint->of(null));
        self::assertNull($fingerprint->of('unknown'));
    }
}
