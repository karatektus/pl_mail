<?php

declare(strict_types=1);

namespace App\Tests\Service\Demo;

use App\Service\Demo\DemoMode;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The switch, and the predicate the reaper deletes on.
 *
 * ownsAddress() gets the most attention here because it is the guard on the
 * only destructive operation in the feature, and that operation runs
 * unattended on a timer. Everything it wrongly returns true for is somebody's
 * mailbox.
 */
final class DemoModeTest extends TestCase
{
    private function mode(bool $enabled = true, string $ttl = 'PT2H'): DemoMode
    {
        return new DemoMode($enabled, $ttl);
    }

    public function testTheSwitchIsReportedAsGiven(): void
    {
        self::assertTrue($this->mode(true)->isEnabled());
        self::assertFalse($this->mode(false)->isEnabled());
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function addresses(): iterable
    {
        yield 'a provisioned visitor'   => ['demo-a1b2c3d4e5f6@plmail.invalid', true];
        yield 'nobody at all'           => [null, false];
        yield 'the admin of the demo'   => ['paul@example.com', false];
        yield 'the E2E fixture user'    => ['e2e@plmail.test', false];
        // The prefix alone is not enough, and neither is the domain alone.
        // Either half on its own is a name a real user could have chosen.
        yield 'right prefix, real host' => ['demo-1234@example.com', false];
        yield 'right host, real name'   => ['paul@plmail.invalid', false];
        yield 'merely contains it'      => ['not-demo-1@plmail.invalid', false];
    }

    #[DataProvider('addresses')]
    public function testOnlyProvisionedAddressesAreClaimed(?string $email, bool $expected): void
    {
        self::assertSame($expected, $this->mode()->ownsAddress($email));
    }

    public function testExpiryIsTheConfiguredIntervalFromNow(): void
    {
        $now = new DateTimeImmutable('2026-08-24 12:00:00');

        self::assertSame(
            '2026-08-24 14:00:00',
            $this->mode(ttl: 'PT2H')->expiryFrom($now)->format('Y-m-d H:i:s'),
        );
    }

    /**
     * A typo in the environment must not take the demo off the internet. The
     * failure it would otherwise cause is on the request that provisions a
     * visitor, which is every visitor.
     */
    public function testAMalformedIntervalFallsBackRatherThanThrowing(): void
    {
        $now = new DateTimeImmutable('2026-08-24 12:00:00');

        self::assertSame(
            '2026-08-24 14:00:00',
            $this->mode(ttl: 'two hours please')->expiryFrom($now)->format('Y-m-d H:i:s'),
        );
    }
}
