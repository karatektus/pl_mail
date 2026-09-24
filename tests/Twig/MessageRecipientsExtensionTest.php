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
}
