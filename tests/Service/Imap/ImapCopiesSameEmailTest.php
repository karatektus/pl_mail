<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\DTO\Mail\ImapCopyIdentity;
use App\Entity\Mail\Message;
use App\Service\Imap\ImapCopies;
use PHPUnit\Framework\TestCase;

/**
 * What counts as the same email when a new copy turns up (#415). See the
 * class comment on ImapCopies for why each rule is the rule.
 */
final class ImapCopiesSameEmailTest extends TestCase
{
    public function testAServerIdDecidesAlone(): void
    {
        $stored = $this->stored('a@example.test', 100, 'M1');

        self::assertSame($stored, ImapCopies::sameEmail(new ImapCopyIdentity(7, 999, 'other@example.test', 'M1'), [$stored]));
    }

    public function testTwoServerIdsThatDifferAreTwoEmails(): void
    {
        $stored = $this->stored('a@example.test', 100, 'M1');

        self::assertNull(ImapCopies::sameEmail(new ImapCopyIdentity(7, 100, 'a@example.test', 'M2'), [$stored]));
    }

    public function testAMessageIdMatchesWithTheSameSize(): void
    {
        $stored = $this->stored('a@example.test', 100, null);

        self::assertSame($stored, ImapCopies::sameEmail(new ImapCopyIdentity(7, 100, 'a@example.test', null), [$stored]));
    }

    public function testAMessageIdWithAnotherSizeIsAnotherEmail(): void
    {
        $stored = $this->stored('a@example.test', 100, null);

        self::assertNull(ImapCopies::sameEmail(new ImapCopyIdentity(7, 140, 'a@example.test', null), [$stored]));
    }

    public function testMailWithoutAMessageIdNeverMatches(): void
    {
        $stored = $this->stored('', 100, null);

        self::assertNull(ImapCopies::sameEmail(new ImapCopyIdentity(7, 100, '', null), [$stored]));
    }

    /**
     * Stored before #415: no size recorded, so nothing to guard the
     * Message-ID with. Left to the merge job.
     */
    public function testARowWithNoSizeRecordedNeverMatches(): void
    {
        $stored = $this->stored('a@example.test', null, null);

        self::assertNull(ImapCopies::sameEmail(new ImapCopyIdentity(7, 100, 'a@example.test', null), [$stored]));
    }

    private function stored(string $messageId, ?int $size, ?string $serverId): Message
    {
        $message                = new Message();
        $message->messageId     = $messageId;
        $message->rfc822Size    = $size;
        $message->serverEmailId = $serverId;

        return $message;
    }
}
