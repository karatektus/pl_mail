<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Execution;

use App\Infrastructure\Messaging\Execution\IsolationKeys;
use App\Infrastructure\Messaging\Message\ApplyRemoteFlagsMessage;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Infrastructure\Messaging\Message\SyncAddressBookMessage;
use App\Infrastructure\Messaging\Message\SyncCalendarMessage;
use App\Infrastructure\Messaging\Message\SyncGraphMessageBatchMessage;
use App\Infrastructure\Messaging\Message\SyncImapMailboxMessage;
use App\Repository\Mail\Mailbox\MailboxRepository;
use PHPUnit\Framework\TestCase;

/**
 * Which ingest jobs wait for each other: the ones for the same account (#610).
 */
final class IsolationKeysTest extends TestCase
{
    public function testAnAccountsSyncAndItsBatchesShareAKey(): void
    {
        $keys = $this->keys([]);

        self::assertSame('account:7', $keys->of(new SyncAccountMessage(7)));
        self::assertSame('account:7', $keys->of(new SyncGraphMessageBatchMessage(7, ['a'])));
    }

    /**
     * A folder's jobs wait for its ACCOUNT's, not just for the folder's: the
     * connection limit an IMAP server enforces is per user.
     */
    public function testAFolderJobIsKeyedByItsAccount(): void
    {
        $keys = $this->keys([40 => 7]);

        self::assertSame('account:7', $keys->of(new SyncImapMailboxMessage(40)));
        self::assertSame('account:7', $keys->of(new ApplyRemoteFlagsMessage(40, 12, ['\\Seen'])));
    }

    public function testAFolderThatIsGoneIsKeyedByItself(): void
    {
        self::assertSame('mailbox:41', $this->keys([])->of(new SyncImapMailboxMessage(41)));
    }

    public function testCalendarsAndAddressBooksAreKeyedOnTheirOwn(): void
    {
        $keys = $this->keys([]);

        self::assertSame('calendar:3', $keys->of(new SyncCalendarMessage(3)));
        self::assertSame('address-book:3', $keys->of(new SyncAddressBookMessage(3)));
    }

    public function testAMessageWithNoneOfTheFieldsHasNoKey(): void
    {
        self::assertNull($this->keys([])->of(new \stdClass()));
    }

    /** @param array<int, int> $accountOfMailbox */
    private function keys(array $accountOfMailbox): IsolationKeys
    {
        $mailboxes = $this->createStub(MailboxRepository::class);
        $mailboxes->method('accountIdOf')->willReturnCallback(
            static fn (int $mailboxId): ?int => $accountOfMailbox[$mailboxId] ?? null,
        );

        return new IsolationKeys($mailboxes);
    }
}
