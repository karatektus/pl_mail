<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Helper\MessageParser;
use App\Service\Imap\MessageSyncer;
use PHPUnit\Framework\TestCase;

/**
 * Recipients survive the trip from the header to the row.
 *
 * The bug this was written for was silent and total: webklex handed the
 * recipients over as an Attribute, which is not Traversable, so the loop over
 * them ran zero times and every message ever synced over IMAP was stored with
 * no recipients. The message header had no "to" line, reply-all had nobody to
 * reply to, and no contact was ever harvested from a recipient.
 *
 * Written end to end, from the header text through MessageParser to the list
 * a row stores, because a hand-built list of addresses would iterate
 * perfectly and would have gone on passing against the broken code.
 */
final class ImapRecipientCaptureTest extends TestCase
{
    public function testEveryRecipientOfARealHeaderIsCaptured(): void
    {
        self::assertSame(
            [
                ['name' => 'Alice Example', 'address' => 'alice@example.com'],
                ['name' => '', 'address' => 'bob@example.com'],
            ],
            $this->recipients('To: Alice Example <Alice@Example.COM>, bob@example.com'),
        );
    }

    /**
     * `undisclosed-recipients:;` is a group with no members. Storing it would
     * put a recipient called "undisclosed-recipients" in the header. The
     * honest answer is an empty list, which the template states out loud.
     */
    public function testAGroupWithNobodyInItNamesNobody(): void
    {
        self::assertSame([], $this->recipients('To: undisclosed-recipients:;'));
    }

    /** A group's members are recipients like any other. */
    public function testTheMembersOfAGroupAreCaptured(): void
    {
        self::assertSame(
            [['name' => 'Carol', 'address' => 'carol@example.com']],
            $this->recipients('To: Team: Carol <carol@example.com>;'),
        );
    }

    public function testAQuotedDisplayNameLosesOnlyItsQuotes(): void
    {
        self::assertSame(
            [['name' => 'Doe, John', 'address' => 'john@example.com']],
            $this->recipients('To: "Doe, John" <john@example.com>'),
        );
    }

    /** An encoded name, and one written in raw UTF-8 without any encoding. */
    public function testANameIsDecodedHoweverItWasWritten(): void
    {
        self::assertSame(
            [
                ['name' => 'Jörg Müller', 'address' => 'joerg@example.com'],
                ['name' => 'Jörg Müller', 'address' => 'j2@example.com'],
            ],
            $this->recipients("To: =?UTF-8?Q?J=C3=B6rg_M=C3=BCller?= <joerg@example.com>, J\xC3\xB6rg M\xC3\xBCller <j2@example.com>"),
        );
    }

    public function testAnAbsentHeaderIsAnEmptyList(): void
    {
        self::assertSame([], $this->recipients('Subject: Nobody'));
    }

    /** @return list<array{name: string, address: string}> */
    private function recipients(string $header): array
    {
        return MessageSyncer::addressesOf(MessageParser::parse("From: a@example.test\r\n{$header}\r\n\r\nBody.\r\n")->to);
    }
}
