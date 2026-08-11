<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\MailboxSpecialUse;
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
use Psr\Log\NullLogger;
use ReflectionProperty;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message as ImapMessage;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\FlagCollection;
use Webklex\PHPIMAP\Support\MessageCollection;

/**
 * What a local action actually asks the IMAP server to do.
 *
 * The outgoing half of the lifecycle had tests for its failure modes — an
 * unreachable server, a bad password, a UID that had already moved — and none
 * at all for the thing it exists to do. Every one of the six actions was
 * asserted only by reading the `match` in applyToMessage() and believing it,
 * which is how `delete` came to be the one action whose meaning nobody had
 * pinned: it is an expunge, not a move to Trash, and those differ in whether
 * the mail still exists afterwards.
 *
 * So this states each action as an operation on a server, and asserts on what
 * that server was told. It also pins the two halves of the write-back, which is
 * the part of a move that keeps the row addressable: webklex learns the
 * destination UID by predicting the folder's UIDNEXT, and a prediction adopted
 * unchecked points the row at whatever else happened to land there in the
 * meantime.
 */
final class ApplyImapFlagsOutboundOperationsTest extends TestCase
{
    // ── flags, in place ──────────────────────────────────────────────────

    public function testMarkingReadSetsSeenOnTheServer(): void
    {
        $present = new RecordingImapMessage(uid: 6);

        $this->issue($present, 'seen');

        self::assertSame(['setFlag:Seen'], $present->calls);
    }

    public function testMarkingUnreadClearsSeenOnTheServer(): void
    {
        $present = new RecordingImapMessage(uid: 6);

        $this->issue($present, 'unseen');

        self::assertSame(['unsetFlag:Seen'], $present->calls);
    }

    public function testStarringSetsFlaggedOnTheServer(): void
    {
        $present = new RecordingImapMessage(uid: 6);

        $this->issue($present, 'flag');

        self::assertSame(['setFlag:Flagged'], $present->calls);
    }

    public function testUnstarringClearsFlaggedOnTheServer(): void
    {
        $present = new RecordingImapMessage(uid: 6);

        $this->issue($present, 'unflag');

        self::assertSame(['unsetFlag:Flagged'], $present->calls);
    }

    /**
     * A flag change is not a move, and must not become one. The distinction
     * matters because a move is the one operation here that can duplicate mail
     * on the server if it is issued twice.
     */
    public function testAFlagChangeNeverMovesTheMessage(): void
    {
        $present = new RecordingImapMessage(uid: 6);

        $this->issue($present, 'seen');

        self::assertSame(0, $present->moveCalls);
    }

    // ── delete, which means delete ───────────────────────────────────────

    /**
     * `delete` expunges. It is not the Trash button — that is `trash`, and it
     * moves. This action is what plMail issues when the mail is to stop
     * existing on the server, and it has to carry the expunge or the message
     * merely wears \Deleted and comes back on the next listing.
     */
    public function testDeleteExpungesRatherThanMovingToTrash(): void
    {
        $present = new RecordingImapMessage(uid: 6);

        $this->issue($present, 'delete');

        self::assertSame(['delete:expunge'], $present->calls);
        self::assertSame(0, $present->moveCalls, 'a delete is not a move');
    }

    // ── moves, and where they go ─────────────────────────────────────────

    /**
     * Trash resolves to the account's Trash folder by special-use, which is the
     * mapping the whole label model rests on.
     */
    public function testTrashingMovesToTheAccountsTrashFolder(): void
    {
        $present = new RecordingImapMessage(uid: 6);

        $this->issue($present, 'trash');

        self::assertSame(1, $present->moveCalls);
        self::assertSame('INBOX.Trash', $present->movedTo);
    }

    public function testArchivingMovesToTheAccountsArchiveFolder(): void
    {
        $present = new RecordingImapMessage(uid: 6);

        $this->issue($present, 'archive', archiveExists: true);

        self::assertSame(1, $present->moveCalls);
        self::assertSame('INBOX.Archive', $present->movedTo);
    }

    /**
     * An explicit `move` — what the label propagator queues when a custom
     * location label is replaced — goes exactly where the envelope says, and
     * does not re-resolve anything.
     */
    public function testAnExplicitMoveGoesToThePathTheEnvelopeNames(): void
    {
        $present = new RecordingImapMessage(uid: 6);

        $this->issue($present, 'move', destinationPath: 'INBOX.Projekte');

        self::assertSame('INBOX.Projekte', $present->movedTo);
    }

    /**
     * With no destination resolvable, nothing is issued at all. Moving mail to
     * a folder that could not be identified is worse than not moving it.
     */
    public function testAMoveWithNoResolvableDestinationIssuesNothing(): void
    {
        $present = new RecordingImapMessage(uid: 6);

        $this->issue($present, 'archive', archiveExists: false);

        self::assertSame(0, $present->moveCalls);
        self::assertSame([], $present->calls);
    }

    // ── the write-back ───────────────────────────────────────────────────

    /**
     * A move that lands somewhere the server confirms is the same message ends
     * with the row holding the destination's UID, so it is addressable
     * immediately rather than at the next sync of the destination.
     */
    public function testAConfirmedMoveWritesTheDestinationUidBackOntoTheRow(): void
    {
        $landed  = new RecordingImapMessage(uid: 91, rfcMessageId: 'abc@example.test');
        $present = new RecordingImapMessage(uid: 6, rfcMessageId: 'abc@example.test', landsAs: $landed);

        $row = $this->issue($present, 'trash', rowMessageId: 'abc@example.test', rowLandsIn: 'INBOX.Trash');

        self::assertSame(91, $row->imapUid, 'the row can be addressed in Trash straight away');
    }

    /**
     * And the case that makes checking worth the trouble. webklex finds the
     * moved message by predicting the destination's UIDNEXT; if something else
     * landed there first, the prediction names somebody else's mail. An
     * unverified answer leaves the row unlocated, which costs one sync — a
     * wrong answer would point the row at another message permanently.
     */
    public function testAMoveWhoseDestinationCopyIsSomebodyElsesMailIsNotAdopted(): void
    {
        $someoneElse = new RecordingImapMessage(uid: 91, rfcMessageId: 'other@example.test');
        $present     = new RecordingImapMessage(uid: 6, rfcMessageId: 'abc@example.test', landsAs: $someoneElse);

        $row = $this->issue($present, 'trash', rowMessageId: 'abc@example.test', rowLandsIn: 'INBOX.Trash');

        self::assertNull($row->imapUid, 'unlocated is reconcilable; wrong is not');
    }

    // ── fixture ──────────────────────────────────────────────────────────

    /**
     * Run one envelope against a server holding one message, and hand back the
     * row so the write-back can be asserted on.
     */
    private function issue(
        RecordingImapMessage $present,
        string               $action,
        ?string              $destinationPath = null,
        bool                 $archiveExists = true,
        ?string              $rowMessageId = null,
        ?string              $rowLandsIn = null,
    ): Message {
        $account      = new Account();
        $account->usr = new User();

        $source = $this->mailbox($account, 'INBOX', 'INBOX', MailboxSpecialUse::INBOX);
        $trash  = $this->mailbox($account, 'Trash', 'INBOX.Trash', MailboxSpecialUse::TRASH);

        $row            = new Message();
        $row->account   = $account;
        $row->messageId = $rowMessageId;

        // A row mid-move carries no UID at all — relocateTo() cleared it the
        // moment the user acted, and the envelope is where the source address
        // survives. Reproducing that is the point: adopt() writing a UID onto
        // such a row is the whole behaviour under test, and a row that still
        // held its old UID would hide a wrong adoption behind it.
        if (null !== $rowLandsIn) {
            $row->relocateTo($trash);
        } else {
            $row->relocateTo($source, 6);
        }

        new ReflectionProperty(Message::class, 'id')->setValue($row, 1);

        $archive = $archiveExists
            ? $this->mailbox($account, 'Archive', 'INBOX.Archive', MailboxSpecialUse::ARCHIVE)
            : null;

        $messageRepository = $this->createStub(MessageRepository::class);
        $messageRepository->method('findBy')->willReturn([$row]);

        $mailboxRepository = $this->createStub(MailboxRepository::class);
        $mailboxRepository->method('find')->willReturn($source);
        $mailboxRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?Mailbox => match ($criteria['specialUse'] ?? null) {
                '\\Trash'   => $trash,
                '\\Archive' => $archive,
                default     => null,
            },
        );

        $client = $this->createStub(Client::class);
        // Every folder lookup answers with the same holding folder except the
        // ones resolveDestinationPath() probes by name when there is no
        // special-use row, which must not invent an Archive that is not there.
        $client->method('getFolder')->willReturnCallback(
            static fn (string $path): ?Folder => match (true) {
                false === $archiveExists && in_array($path, ['Archive', 'Archives'], true) => null,
                default => new RecordingFolder($path, new MessageCollection([$present])),
            },
        );
        $client->method('disconnect')->willReturnSelf();

        $connectionFactory = $this->createStub(ImapConnectionFactory::class);
        $connectionFactory->method('connect')->willReturn($client);

        $handler = new ApplyImapFlagsHandler(
            $messageRepository,
            $mailboxRepository,
            new NullLogger(),
            $connectionFactory,
            $this->createStub(EntityManagerInterface::class),
        );

        $handler(new ApplyImapFlagsMessage([1 => 10], $action, $destinationPath, [1 => 6]));

        return $row;
    }

    private function mailbox(
        Account           $account,
        string            $name,
        string            $fullPath,
        MailboxSpecialUse $specialUse,
    ): Mailbox {
        $mailbox = new Mailbox();
        $mailbox->account    = $account;
        $mailbox->name       = $name;
        $mailbox->fullPath   = $fullPath;
        $mailbox->specialUse = $specialUse;

        return $mailbox;
    }
}

/**
 * A folder that holds what the test says it holds. Named apart from the
 * FakeFolder in ApplyImapFlagsVanishedUidTest because PHP class names are
 * global and both files load in the same suite.
 */
final class RecordingFolder extends Folder
{
    public function __construct(string $path, private readonly MessageCollection $holding)
    {
        // Deliberately not parent::__construct(): it wants a live connection.
        $this->path      = $path;
        $this->name      = $path;
        $this->full_name = $path;
    }

    public function messages(array $extensions = []): WhereQuery
    {
        return new RecordingQuery($this->holding);
    }
}

final class RecordingQuery extends WhereQuery
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
 * An IMAP message that writes down every operation performed on it.
 *
 * getUid()/getMessageId() are declared outright because webklex serves those
 * through __call, which a double cannot intercept.
 */
final class RecordingImapMessage extends ImapMessage
{
    /** @var list<string> */
    public array $calls = [];

    public int $moveCalls = 0;

    public ?string $movedTo = null;

    private int $fakeUid;

    private ?string $fakeMessageId;

    private ?RecordingImapMessage $landsAs;

    public function __construct(
        int                   $uid,
        ?string               $rfcMessageId = null,
        ?RecordingImapMessage $landsAs = null,
    ) {
        $this->fakeUid       = $uid;
        $this->fakeMessageId = $rfcMessageId;
        $this->landsAs       = $landsAs;
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
        return new FlagCollection([]);
    }

    public function setFlag($flag): bool
    {
        $this->calls[] = 'setFlag:' . (is_array($flag) ? implode(',', $flag) : (string) $flag);

        return true;
    }

    public function unsetFlag($flag): bool
    {
        $this->calls[] = 'unsetFlag:' . (is_array($flag) ? implode(',', $flag) : (string) $flag);

        return true;
    }

    public function delete(bool $expunge = true, ?string $trash_path = null, bool $force_move = false): bool
    {
        $this->calls[] = 'delete:' . (true === $expunge ? 'expunge' : 'flag-only');

        return true;
    }

    public function move(string $folder_path, bool $expunge = false, bool $utf7 = false): ?ImapMessage
    {
        ++$this->moveCalls;
        $this->movedTo = $folder_path;

        return $this->landsAs;
    }
}
