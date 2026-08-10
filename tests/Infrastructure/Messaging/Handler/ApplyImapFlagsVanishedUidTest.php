<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\ApplyImapFlagsHandler;
use App\Infrastructure\Messaging\Message\ApplyImapFlagsMessage;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message as ImapMessage;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\FlagCollection;
use Webklex\PHPIMAP\Support\MessageCollection;

/**
 * What the outgoing push does when the UID it was given is not there.
 *
 * The report arrived with this line in it, from the worker-export queue:
 *
 *     ApplyImapFlagsHandler: UID not found in source folder
 *     {"uid": 6, "folder": "INBOX.spambucket"}
 *
 * It was a warning, and it was the wrong reading of the situation twice over.
 * A UID that has left its folder is the *ordinary* outcome of a message having
 * already moved — by an earlier attempt of the same job, or by another client —
 * so the log filled up with a condition nobody could act on. And stopping there
 * left the row still claiming the address the server had just disowned, which
 * is precisely the state that makes the destination's next sync insert a second
 * row instead of recognising the first. The warning was not the bug, but it was
 * the bug's fingerprint.
 *
 * So the handler re-resolves instead: the stale claim is dropped, which is what
 * makes the row reconcilable by Message-ID on the next sync, and the event is
 * reported as the routine thing it is.
 */
final class ApplyImapFlagsVanishedUidTest extends TestCase
{
    /** @var list<array{level: string, message: string}> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->logged = [];
    }

    /**
     * The row must not go on naming an address the server does not have. A row
     * with no UID is the one shape SentCopyReconciler::claim() can reconcile
     * onto the real copy; a row with a wrong UID is the one shape that
     * guarantees it cannot.
     */
    public function testAVanishedUidLeavesTheRowReconcilableRatherThanStale(): void
    {
        $row     = $this->row(uid: 6);
        $handler = $this->handler($row, $this->folderHolding([]));

        $handler(new ApplyImapFlagsMessage([1 => 10], 'trash', null, [1 => 6]));

        self::assertNull($row->imapUid, 'the address the server disowned has to go');
    }

    /**
     * The reported log line, demoted. Nothing here is a fault: it is the normal
     * result of the mail having already moved.
     */
    public function testAVanishedUidIsNoLongerReportedAsAWarning(): void
    {
        $handler = $this->handler($this->row(uid: 6), $this->folderHolding([]));

        $handler(new ApplyImapFlagsMessage([1 => 10], 'trash', null, [1 => 6]));

        $warnings = array_values(array_filter(
            $this->logged,
            static fn (array $entry): bool => 'warning' === $entry['level'] || 'error' === $entry['level'],
        ));

        self::assertSame([], $warnings, 'a message that has already moved is not a failure');
        self::assertContains(
            'ApplyImapFlagsHandler: UID has left the source folder, re-resolving',
            array_column($this->logged, 'message'),
        );
    }

    /**
     * The one way this handler could put a real duplicate on the *server*.
     *
     * Servers without RFC 6851 MOVE make webklex fall back to COPY + STORE
     * \Deleted + EXPUNGE. A connection that dies between the COPY and the
     * EXPUNGE leaves the copy in the destination and the original still in the
     * source, flagged — and Messenger, which is told to redeliver exactly on
     * connection failures, then hands the job back. Moving it again would put a
     * second copy in the destination. The flag is the evidence that the copy is
     * already there.
     */
    public function testAHalfFinishedMoveIsNotCopiedIntoTheDestinationTwice(): void
    {
        $alreadyCopied = new FakeImapMessage(uid: 6, flags: ['\\Deleted']);
        $handler       = $this->handler($this->row(uid: 6), $this->folderHolding([$alreadyCopied]));

        $handler(new ApplyImapFlagsMessage([1 => 10], 'trash', null, [1 => 6]));

        self::assertSame(0, $alreadyCopied->moveCalls, 'the copy is already in the destination; making another is the bug');
    }

    /**
     * The ordinary path still has to work: a message that is where it is
     * supposed to be gets moved, once.
     */
    public function testAMessageThatIsStillThereIsMovedNormally(): void
    {
        $present = new FakeImapMessage(uid: 6, flags: ['\\Seen']);
        $handler = $this->handler($this->row(uid: 6), $this->folderHolding([$present]));

        $handler(new ApplyImapFlagsMessage([1 => 10], 'trash', null, [1 => 6]));

        self::assertSame(1, $present->moveCalls);
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    private function row(int $uid): Message
    {
        $account = new Account();
        $account->usr = new User();

        $message = new Message();
        $message->account = $account;
        $message->imapUid = $uid;
        new ReflectionProperty(Message::class, 'id')->setValue($message, 1);

        return $message;
    }

    /**
     * @param list<ImapMessage> $messages
     */
    private function folderHolding(array $messages): Folder
    {
        return new FakeFolder('INBOX.spambucket', new MessageCollection($messages));
    }

    private function handler(Message $row, Folder $folder): ApplyImapFlagsHandler
    {
        $account = $row->account;

        $mailbox = new Mailbox();
        $mailbox->account  = $account;
        $mailbox->name     = 'INBOX.spambucket';
        $mailbox->fullPath = 'INBOX.spambucket';

        $trash = new Mailbox();
        $trash->account  = $account;
        $trash->name     = 'Trash';
        $trash->fullPath = 'INBOX.Trash';

        $messageRepository = $this->createStub(MessageRepository::class);
        $messageRepository->method('findBy')->willReturn([$row]);

        $mailboxRepository = $this->createStub(MailboxRepository::class);
        $mailboxRepository->method('find')->willReturn($mailbox);
        $mailboxRepository->method('findOneBy')->willReturn($trash);

        $client = $this->createStub(Client::class);
        $client->method('getFolder')->willReturn($folder);
        // disconnect() returns Client, and Client::__destruct() calls it. Left
        // to invent its own return value PHPUnit builds another Client double,
        // whose destructor does the same, until the stack ends — so it is told
        // what to return instead.
        $client->method('disconnect')->willReturnSelf();

        $connectionFactory = $this->createStub(ImapConnectionFactory::class);
        $connectionFactory->method('connect')->willReturn($client);

        return new ApplyImapFlagsHandler(
            $messageRepository,
            $mailboxRepository,
            $this->logger(),
            $connectionFactory,
            $this->createStub(EntityManagerInterface::class),
        );
    }

    private function logger(): LoggerInterface
    {
        return new class($this->logged) extends AbstractLogger {
            /** @param list<array{level: string, message: string}> $logged */
            public function __construct(public array &$logged) {}

            /** @param array<mixed> $context */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };
    }
}

/**
 * A folder that holds exactly what the test says it holds.
 *
 * Subclassed rather than mocked because the handler walks a fluent query —
 * messages()->whereUid()->setFetchBody()->get() — and a chain of stubs
 * configured to return themselves says far less about what is being tested than
 * a folder that plainly contains some messages.
 */
final class FakeFolder extends Folder
{
    public function __construct(string $path, private readonly MessageCollection $holding)
    {
        // Deliberately not parent::__construct(): that one wants a live client
        // and an IMAP connection, and nothing below needs either.
        $this->path      = $path;
        $this->name      = $path;
        $this->full_name = $path;
    }

    public function messages(array $extensions = []): WhereQuery
    {
        return new FakeQuery($this->holding);
    }
}

final class FakeQuery extends WhereQuery
{
    public function __construct(private readonly MessageCollection $holding)
    {
    }

    public function whereUid(int|string $uid): static
    {
        return $this;
    }

    public function where(mixed $criteria, mixed $value = null): static
    {
        return $this;
    }

    public function setFetchBody(bool $fetch_body): static
    {
        return $this;
    }

    public function get(): MessageCollection
    {
        return $this->holding;
    }
}

/**
 * An IMAP message that records whether anything moved it.
 *
 * getUid() and getMessageId() are declared outright because webklex serves
 * those through __call, which a test double cannot meaningfully intercept.
 */
final class FakeImapMessage extends ImapMessage
{
    public int $moveCalls = 0;

    /** @var list<string> */
    private array $fakeFlags;

    private int $fakeUid;

    private ?string $fakeMessageId;

    /**
     * Prefixed names throughout: Webklex\PHPIMAP\Message already declares
     * $flags and $uid, and a subclass may not redeclare them.
     *
     * @param list<string> $flags
     */
    public function __construct(int $uid, array $flags, ?string $rfcMessageId = null)
    {
        $this->fakeUid       = $uid;
        $this->fakeFlags     = $flags;
        $this->fakeMessageId = $rfcMessageId;
    }

    public function getUid(): int
    {
        return $this->fakeUid;
    }

    public function getMessageId(): ?string
    {
        return $this->fakeMessageId;
    }

    public function getFlags(): FlagCollection
    {
        return new FlagCollection($this->fakeFlags);
    }

    public function move(string $folder_path, bool $expunge = false, bool $utf7 = false): ?ImapMessage
    {
        ++$this->moveCalls;

        return null;
    }
}
