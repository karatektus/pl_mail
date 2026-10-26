<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\Mail\Message;
use App\Twig\MessageRecipientsExtension;
use PHPUnit\Framework\TestCase;

/**
 * The addresses the message header shows, for the one field with no column.
 *
 * Reply-To only ever lives in the stored header bag. Everywhere else the
 * column is read first and the header is the fallback, so a Reply-To that fell
 * through to that default would quietly show the To list as the address
 * answers go to.
 */
final class MessageRecipientsExtensionTest extends TestCase
{
    public function testReplyToComesFromTheHeaderRatherThanTheToColumn(): void
    {
        $message              = new Message();
        $message->toAddresses = [['name' => 'Team', 'address' => 'team@example.com']];
        $message->headers     = ['reply-to' => 'Tickets <tickets@example.com>'];

        self::assertSame(
            [['name' => 'Tickets', 'address' => 'tickets@example.com']],
            new MessageRecipientsExtension()->addresses($message, 'reply-to'),
        );
    }

    // ── a Reply-To that sends the answer somewhere else ───────────────────

    /**
     * The classic: the From line is the brand, the answer goes to the thief.
     * Shown to the reader as a fact, not scored — see replyToElsewhere().
     */
    public function testAReplyToOnAnotherDomainIsReported(): void
    {
        $message = $this->message('service@paypal.com', 'Service <help@evil.example>');

        self::assertSame(
            [['name' => 'Service', 'address' => 'help@evil.example']],
            new MessageRecipientsExtension()->replyToElsewhere($message),
        );
    }

    /**
     * The presence companion, and the reason this is not a warning: a
     * different address on the SAME organisation is how most mail is set up.
     */
    public function testAReplyToOnTheSameOrganisationIsNotReported(): void
    {
        self::assertSame([], new MessageRecipientsExtension()->replyToElsewhere(
            $this->message('noreply@paypal.com', 'help@support.paypal.com'),
        ));
        self::assertSame([], new MessageRecipientsExtension()->replyToElsewhere(
            $this->message('noreply@paypal.com', 'noreply@paypal.com'),
        ));
        self::assertSame([], new MessageRecipientsExtension()->replyToElsewhere(
            $this->message('NoReply@PayPal.com', 'help@PAYPAL.COM'),
        ));
    }

    public function testOnlyTheReplyToEntriesOnAnotherDomainAreReported(): void
    {
        $message = $this->message('service@paypal.com', 'help@paypal.com, help@evil.example');

        self::assertSame(
            [['name' => '', 'address' => 'help@evil.example']],
            new MessageRecipientsExtension()->replyToElsewhere($message),
        );
    }

    public function testNoReplyToReportsNothing(): void
    {
        $message              = new Message();
        $message->fromAddress = 'service@paypal.com';
        $message->headers     = [];

        self::assertSame([], new MessageRecipientsExtension()->replyToElsewhere($message));
    }

    /**
     * A From host that cannot be reduced to a registrable domain — mail from a
     * bare `localhost`, say — is compared whole rather than skipped, so a
     * Reply-To elsewhere is still reported.
     */
    public function testAFromHostWithNoRegistrableDomainIsComparedWhole(): void
    {
        self::assertSame(
            [['name' => '', 'address' => 'help@evil.example']],
            new MessageRecipientsExtension()->replyToElsewhere($this->message('root@localhost', 'help@evil.example')),
        );
    }

    /** An address literal is an address nobody can read the organisation off. */
    public function testAReplyToOnAnAddressLiteralIsReported(): void
    {
        self::assertSame(
            [['name' => '', 'address' => 'help@[192.0.2.1]']],
            new MessageRecipientsExtension()->replyToElsewhere($this->message('service@paypal.com', 'help@[192.0.2.1]')),
        );
    }

    /** Nothing to compare against is nothing to say. */
    public function testWithNoUsableFromAddressNothingIsReported(): void
    {
        self::assertSame([], new MessageRecipientsExtension()->replyToElsewhere(
            $this->message(null, 'help@evil.example'),
        ));
        self::assertSame([], new MessageRecipientsExtension()->replyToElsewhere(
            $this->message('not-an-address', 'help@evil.example'),
        ));
    }

    private function message(?string $from, string $replyTo): Message
    {
        $message              = new Message();
        $message->fromAddress = $from;
        $message->headers     = ['reply-to' => $replyTo];

        return $message;
    }
}
