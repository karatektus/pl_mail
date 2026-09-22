<?php

declare(strict_types=1);

namespace App\Tests\Support\Mercure;

use Symfony\Component\Mercure\Exception\RuntimeException as MercureException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * A hub that keeps what was published to it, and can refuse instead.
 *
 * There were three of these, in three test files, doing these same two things:
 * `RecordingHub` and `ThrowingHub` at the foot of the calendar notification
 * test, an anonymous `deadHub()` in the outage test, and `TestHub` — a recorder
 * with a `refuse` flag, which is what this is — beside the label one. Every one
 * of them had to grow when {@see HubInterface} did, and all three carried the
 * same note saying `getProtocolVersion()` and `getCookieName()` arrived in
 * symfony/mercure 0.8. The next method on that interface would have been four
 * edits.
 *
 * ## Recording and refusing are one class rather than two
 *
 * A separate ThrowingHub reads as the tidier design and is not: every test that
 * wants a failure ALSO wants to know nothing was published, and with two classes
 * that second half has nowhere to be asserted from. One object answers both —
 * `updates` stays empty when it refused — which is the shape the label test had
 * already arrived at independently.
 *
 * ## What it refuses with
 *
 * Mercure's own {@see MercureException}, because that is what a real hub raises
 * when it cannot be reached, and a double that fails in a way the real thing
 * never does is testing the wrong contract. It makes no behavioural difference
 * today — every notifier that publishes catches `\Throwable`, which is the
 * point of them and is separately asserted — but the day one narrows its catch,
 * this should be the exception that decides whether it was narrowed correctly.
 *
 * ## The five methods, and no more
 *
 * `HubInterface` declares exactly these. One of the doubles this replaces also
 * carried `getUrl()` and `getProvider()`, which are on neither the interface nor
 * any path under test; they are gone rather than carried forward, because an
 * unused method on a double is a claim about the code that nothing checks.
 * Neither of the two the interface added is consulted by anything that
 * publishes, so they answer with a real hub's defaults rather than pretending to
 * a configuration these tests do not have.
 */
final class RecordingHub implements HubInterface
{
    /** @var list<Update> */
    public array $updates = [];

    /** An installation whose hub is unreachable, or that runs without one. */
    public bool $refuse = false;

    /** Reads better at the call site than setting the flag after construction. */
    public static function refusing(): self
    {
        $hub = new self();
        $hub->refuse = true;

        return $hub;
    }

    public function getPublicUrl(): string
    {
        return 'https://hub.test/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        if (true === $this->refuse) {
            throw new MercureException('the hub is down');
        }

        $this->updates[] = $update;

        return 'id';
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return ProtocolVersion::V1;
    }

    public function getCookieName(): string
    {
        return 'mercureAuthorization';
    }
}
