<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Service\Imap\MessageSyncer;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attribute;

/**
 * Recipients survive the trip out of webklex.
 *
 * The bug this pins was silent and total: `foreach ($attribute as $address)`
 * over a webklex Attribute looks like an iteration and is not one. Attribute
 * implements ArrayAccess and nothing else — no Iterator, no IteratorAggregate
 * — so PHP falls back to walking the object's public properties, of which it
 * has none. No error, no warning, zero rounds of the loop, and an empty list
 * returned for every message ever synced over IMAP. The message header had no
 * "to" line, reply-all had nobody to reply to, and no contact was ever
 * harvested from a recipient.
 *
 * The first test is the one that matters: it is written against a REAL
 * Attribute, because a hand-rolled array double iterates perfectly and would
 * have gone on passing against the broken code.
 */
final class ImapRecipientCaptureTest extends TestCase
{
    public function testARealWebklexAttributeYieldsItsAddresses(): void
    {
        $attribute = new Attribute('to', [
            self::address('Alice Example', 'Alice@Example.COM'),
            self::address('', 'bob@example.com'),
        ]);

        self::assertSame(
            [
                ['name' => 'Alice Example', 'address' => 'alice@example.com'],
                ['name' => '', 'address' => 'bob@example.com'],
            ],
            MessageSyncer::addressesOf($attribute),
            'an Attribute is not Traversable — reading it as one captured nothing',
        );
    }

    public function testTheAttributeIsIndeedNotTraversable(): void
    {
        $attribute = new Attribute('to', [self::address('Alice', 'alice@example.com')]);

        $rounds = 0;

        // PHPStan is right, and that is the point of the line: it says this
        // exact thing about this exact expression. The syncer's parameter was
        // typed `mixed`, so the analyser had nothing to say there and the
        // mistake shipped. Reproduced here on purpose, and only here.
        // @phpstan-ignore foreach.nonIterable
        foreach ($attribute as $ignored) {
            ++$rounds;
        }

        self::assertSame(0, $rounds, 'the day this iterates, the guard above stops being the point');
        self::assertCount(1, MessageSyncer::addressesOf($attribute));
    }

    /**
     * `undisclosed-recipients:;` is a group with no members. It parses to
     * something with a name and no addr-spec, and storing that would put a
     * recipient called "undisclosed-recipients" in the header. The honest
     * answer is an empty list, which the template states out loud.
     */
    public function testAnEntryWithNoAddressIsDropped(): void
    {
        $attribute = new Attribute('to', [
            self::address('undisclosed-recipients', ''),
            self::address('Carol', 'carol@example.com'),
        ]);

        self::assertSame(
            [['name' => 'Carol', 'address' => 'carol@example.com']],
            MessageSyncer::addressesOf($attribute),
        );
    }

    public function testAQuotedDisplayNameLosesOnlyItsQuotes(): void
    {
        $attribute = new Attribute('to', [self::address('"Doe, John"', 'john@example.com')]);

        self::assertSame(
            [['name' => 'Doe, John', 'address' => 'john@example.com']],
            MessageSyncer::addressesOf($attribute),
        );
    }

    /**
     * A header webklex could not split into parts arrives as a plain string.
     * Keeping the address out of it is better than dropping the recipient.
     */
    public function testAStringEntryStillYieldsItsAddress(): void
    {
        $attribute = new Attribute('to', ['Dave <dave@example.com>']);

        self::assertSame(
            [['name' => '', 'address' => 'dave@example.com']],
            MessageSyncer::addressesOf($attribute),
        );
    }

    public function testAnEmptyOrAbsentHeaderIsAnEmptyList(): void
    {
        self::assertSame([], MessageSyncer::addressesOf(new Attribute('to', [])));
        self::assertSame([], MessageSyncer::addressesOf(null));
    }

    private static function address(string $personal, string $mail): Address
    {
        return new Address((object) [
            'personal' => $personal,
            'mail'     => $mail,
            'host'     => '',
            'mailbox'  => '',
            'full'     => '' === $personal ? $mail : sprintf('%s <%s>', $personal, $mail),
        ]);
    }
}
