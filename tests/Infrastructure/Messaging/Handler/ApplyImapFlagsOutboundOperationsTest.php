<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\ImapClient\ImapSession;
use App\Infrastructure\Messaging\Handler\ApplyImapFlagsHandler;
use App\Infrastructure\Messaging\Message\ApplyImapFlagsMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\ImapConnector;
use App\Service\Imap\ImapFolderProvisioner;
use App\Tests\Infrastructure\ImapClient\UsesDovecot;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a change made in plMail does to the mailbox on the server.
 *
 * Against the test stack's Dovecot, so every claim is about what the server
 * holds afterwards — the flag set, the message gone, the copy in Trash under
 * the UID the row now names — rather than about which methods were called.
 * The rows and repositories are in memory, since the claim is the server's
 * side and the row's address; the folder provisioner is a stand-in that
 * makes the folder on the server, since its own decisions are
 * ImapFolderProvisionerTest's.
 *
 * Covered here too, from what used to be a test of its own: a UID that has
 * left the source folder is re-resolved, not reported (the vanished-UID
 * report), and a move a previous attempt got halfway through is not copied
 * twice.
 */
final class ApplyImapFlagsOutboundOperationsTest extends KernelTestCase
{
    use UsesDovecot;

    /** The Message-ID of the message every test puts on the server. */
    private const string MESSAGE_ID = 'outbound@example.test';

    /** The test's own view of the mailbox: the same user, another session. */
    private ImapSession $server;

    private string $user;

    /** @var list<string> what the handler asked the provisioner for */
    private array $provisioned = [];

    private bool $provisionable = true;

    /** @var list<array{level: string, message: string}> */
    private array $logged = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->user          = 'outbound-' . bin2hex(random_bytes(6));
        $this->server        = $this->dovecotSession(user: $this->user);
        $this->provisioned   = [];
        $this->provisionable = true;
        $this->logged        = [];
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->logout();
        }

        parent::tearDown();
    }

    // ── flags, in place ────────────────────────────────────────────────────

    /** @return iterable<string, array{string, string, bool}> */
    public static function flagActions(): iterable
    {
        yield 'marking read sets \Seen'      => ['seen', '\\Seen', true];
        yield 'marking unread clears \Seen'  => ['unseen', '\\Seen', false];
        yield 'starring sets \Flagged'       => ['flag', '\\Flagged', true];
        yield 'unstarring clears \Flagged'   => ['unflag', '\\Flagged', false];
    }

    #[DataProvider('flagActions')]
    public function testAFlagActionChangesTheFlagOnTheServerAndNothingElse(string $action, string $flag, bool $set): void
    {
        $uid = $this->put('INBOX', true === $set ? [] : [$flag]);

        $this->issue('INBOX', $uid, $action);

        $flags = $this->server->uidFetch('INBOX', (string) $uid, ['FLAGS'])[$uid]['FLAGS'] ?? null;

        self::assertIsArray($flags, 'and the message is still where it was: a flag change is never a move');
        self::assertSame($set, in_array($flag, $flags, true));
    }

    /**
     * IMAP keeps flags per copy, so marking an email read marks every copy the
     * server keeps of it — here one in INBOX and one in Archive (#415) — and
     * each copy remembers that the change was ours.
     */
    public function testAFlagActionReachesEveryCopyOfTheEmail(): void
    {
        $inUid      = $this->put('INBOX');
        $this->server->create('Archive');
        $archiveUid = $this->put('Archive');

        $account                 = new Account();
        $account->usr            = new User();
        $account->imapHost       = (string) getenv('IMAP_TEST_HOST');
        $account->imapPort       = 31143;
        $account->imapEncryption = null;
        $account->username       = $this->user;
        $account->password       = (string) getenv('IMAP_TEST_PASSWORD');
        $account->authType       = 'password';

        $inbox   = $this->mailbox($account, 'INBOX', MailboxSpecialUse::INBOX);
        $archive = $this->mailbox($account, 'Archive', MailboxSpecialUse::ARCHIVE);
        new ReflectionProperty(Mailbox::class, 'id')->setValue($inbox, 10);
        new ReflectionProperty(Mailbox::class, 'id')->setValue($archive, 11);

        $row            = new Message();
        $row->account   = $account;
        $row->messageId = self::MESSAGE_ID;
        $row->addLocation($inbox, $inUid);
        $row->addLocation($archive, $archiveUid);
        new ReflectionProperty(Message::class, 'id')->setValue($row, 1);

        $messages = $this->createStub(MessageRepository::class);
        $messages->method('findBy')->willReturn([$row]);

        $folders = $this->createStub(MailboxRepository::class);
        $folders->method('find')->willReturnCallback(static fn (int $id): ?Mailbox => [10 => $inbox, 11 => $archive][$id] ?? null);

        $handler = new ApplyImapFlagsHandler(
            $messages,
            $folders,
            $this->logger(),
            self::getContainer()->get(ImapConnector::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(ImapFolderProvisioner::class),
            $this->createStub(AccountRepository::class),
        );

        $handler(new ApplyImapFlagsMessage([1 => 10], 'seen', null, [1 => $inUid], [[1, 10, $inUid], [1, 11, $archiveUid]]));

        foreach (['INBOX' => $inUid, 'Archive' => $archiveUid] as $folder => $uid) {
            $flags = $this->server->uidFetch($folder, (string) $uid, ['FLAGS'])[$uid]['FLAGS'] ?? [];

            self::assertContains('\\Seen', $flags, "the {$folder} copy is read");
            self::assertSame(['\\Seen'], $row->locationAt($folder === 'INBOX' ? $inbox : $archive, $uid)?->serverFlags, "and the {$folder} copy knows it was us");
        }
    }

    /**
     * `delete` expunges this one UID. A plain EXPUNGE also removes every
     * message another client has only flagged \Deleted and may still undelete.
     */
    public function testDeleteExpungesOnlyItsOwnUid(): void
    {
        $uid   = $this->put('INBOX');
        $other = $this->put('INBOX', ['\\Deleted'], 'flagged-elsewhere@example.test');

        $this->issue('INBOX', $uid, 'delete');

        self::assertSame([$other], $this->server->uidSearch('INBOX', 'ALL'), 'gone, and only it');
    }

    // ── moves ──────────────────────────────────────────────────────────────

    /**
     * Trash is the account's Trash folder by special use, and the row takes
     * the address the server gave the copy (COPYUID): the server's own answer,
     * not a guess at the next UID — Trash already holds two messages here, so
     * a guess from an empty folder would be wrong.
     */
    public function testTrashingMovesToTheTrashFolderAndTheRowTakesTheServersUid(): void
    {
        $this->server->create('Trash');
        $this->put('Trash', [], 'already-1@example.test');
        $this->put('Trash', [], 'already-2@example.test');
        $uid = $this->put('INBOX');

        $row = $this->issue('INBOX', $uid, 'trash', rowLandsIn: 'Trash');

        self::assertSame([], $this->server->uidSearch('INBOX', 'ALL'), 'it left the inbox');
        self::assertSame(3, $row->imapUid);
        self::assertSame('<' . self::MESSAGE_ID . '>', $this->server->uidFetchHeader('Trash', '3', 'Message-ID')[3]);
    }

    /**
     * A message already in the Trash stays there and keeps its address.
     * Sending the MOVE anyway once failed outright (#269).
     */
    public function testTrashingAMessageAlreadyInTheTrashDoesNotMoveIt(): void
    {
        $this->server->create('Trash');
        $uid = $this->put('Trash');

        $row = $this->issue('Trash', $uid, 'trash', rowLandsIn: 'Trash');

        self::assertSame([$uid], $this->server->uidSearch('Trash', 'ALL'));
        self::assertSame($uid, $row->imapUid, 'the row should have its address in Trash back');
    }

    public function testArchivingMovesToTheArchiveFolder(): void
    {
        $this->server->create('Archive');
        $uid = $this->put('INBOX');

        $this->issue('INBOX', $uid, 'archive', archive: 'Archive');

        self::assertSame([], $this->server->uidSearch('INBOX', 'ALL'));
        self::assertCount(1, $this->server->uidSearch('Archive', 'ALL'));
        self::assertSame([], $this->provisioned, 'nothing is created when the folder is there');
    }

    /**
     * An explicit `move` goes exactly where the envelope says. A stored path
     * is modified UTF-7 already, and is handed to the server as it is: GMX's
     * Trash is "Gelöscht", and a path encoded twice named no folder at all.
     */
    public function testAnExplicitMoveGoesToThePathTheEnvelopeNamesAsTheServerSpellsIt(): void
    {
        $this->server->create('Gel&APY-scht');
        $uid = $this->put('INBOX');

        $this->issue('INBOX', $uid, 'move', destinationPath: 'Gel&APY-scht', rowLandsIn: 'Gel&APY-scht');

        self::assertCount(1, $this->server->uidSearch('Gel&APY-scht', 'ALL'));
    }

    /**
     * Archiving on a server with no Archive folder creates one rather than
     * leaving the message out of the inbox here and in it there.
     */
    public function testArchivingCreatesTheArchiveFolderWhenTheServerHasNotGotOne(): void
    {
        $uid = $this->put('INBOX');

        $this->issue('INBOX', $uid, 'archive');

        self::assertSame([MailboxSpecialUse::ARCHIVE->value], $this->provisioned);
        self::assertCount(1, $this->server->uidSearch('Archive', 'ALL'));
    }

    /**
     * A CREATE the server refuses degrades to the old behaviour, and does not
     * throw: the caller is a Messenger handler, which would re-attempt the
     * same refused CREATE on a schedule for ever.
     */
    public function testARefusedFolderCreationLeavesTheMessageWhereItWasRatherThanCrashing(): void
    {
        $this->provisionable = false;
        $uid                 = $this->put('INBOX');

        $this->issue('INBOX', $uid, 'archive');

        self::assertSame([$uid], $this->server->uidSearch('INBOX', 'ALL'));
    }

    /** Another client can delete a folder at any time; an explicit move makes it again. */
    public function testAnExplicitMoveRecreatesADestinationTheServerHasLost(): void
    {
        $uid = $this->put('INBOX');

        $this->issue('INBOX', $uid, 'move', destinationPath: 'Projects', rowLandsIn: 'Projects');

        self::assertSame(['Projects'], $this->provisioned);
        self::assertCount(1, $this->server->uidSearch('Projects', 'ALL'));
    }

    // ── a UID that has left the source folder ─────────────────────────────

    /**
     * The row must not go on naming an address the server does not have. A row
     * with no UID is the one shape SentCopyReconciler::claim() can reconcile
     * onto the real copy; a row with a wrong UID is the one shape that
     * guarantees it cannot. And it is not reported as a warning: it is the
     * normal result of the mail having already moved.
     */
    public function testAVanishedUidLeavesTheRowReconcilableAndIsNotAWarning(): void
    {
        $row = $this->issue('INBOX', 41, 'seen', rowKeepsUid: true);

        self::assertNull($row->imapUid);
        self::assertSame([], array_filter($this->logged, static fn (array $entry): bool => 'info' !== $entry['level']));
    }

    /**
     * The job ran before and the message is already in Trash. The row finds
     * it there by its Message-ID — one with a quote and a backslash in it,
     * which goes to the server as a value and cannot become search syntax.
     */
    public function testAMoveThatAlreadyHappenedIsFoundInTheDestinationByItsMessageId(): void
    {
        $hostile = 'odd"id\\x@example.test';

        $this->server->create('Trash');
        $copy = $this->put('Trash', [], $hostile);

        $row = $this->issue('INBOX', 41, 'trash', rowLandsIn: 'Trash', messageId: $hostile);

        self::assertSame($copy, $row->imapUid);
    }

    /**
     * The one way this handler could put a real duplicate on the server: a
     * server without MOVE is sent COPY + STORE \Deleted + EXPUNGE, and a
     * connection that dies between the COPY and the EXPUNGE leaves the copy in
     * the destination and the original flagged. Moving it again on redelivery
     * would copy it twice. The flag is the evidence.
     */
    public function testAHalfFinishedMoveIsNotCopiedIntoTheDestinationTwice(): void
    {
        $this->server->create('Trash');
        $copy = $this->put('Trash');
        $uid  = $this->put('INBOX', ['\\Deleted']);

        $row = $this->issue('INBOX', $uid, 'trash', rowLandsIn: 'Trash');

        self::assertSame([$copy], $this->server->uidSearch('Trash', 'ALL'), 'one copy, not two');
        self::assertSame($copy, $row->imapUid, 'and the row has its address');
    }

    // ── fixture ────────────────────────────────────────────────────────────

    /**
     * Put a message on the server.
     *
     * @param list<string> $flags
     */
    private function put(string $folder, array $flags = [], string $messageId = self::MESSAGE_ID): int
    {
        return (int) $this->server->append($folder, sprintf("From: a@example.test\r\nSubject: Outbound\r\nMessage-ID: <%s>\r\n\r\nBody.\r\n", $messageId), $flags);
    }

    /**
     * Run the handler for one message, as the job a user's action queues.
     *
     * @param string|null $rowLandsIn  the folder a move re-pointed the row at before queueing it; the row has no UID then
     * @param bool        $rowKeepsUid a row that still claims $uid, for an action that is not a move
     */
    private function issue(
        string  $source,
        int     $uid,
        string  $action,
        ?string $destinationPath = null,
        ?string $rowLandsIn = null,
        ?string $archive = null,
        string  $messageId = self::MESSAGE_ID,
        bool    $rowKeepsUid = false,
    ): Message {
        $account                 = new Account();
        $account->usr            = new User();
        $account->imapHost       = (string) getenv('IMAP_TEST_HOST');
        $account->imapPort       = 31143;
        $account->imapEncryption = null;
        $account->username       = $this->user;
        $account->password       = (string) getenv('IMAP_TEST_PASSWORD');
        $account->authType       = 'password';

        $mailboxes = [
            'INBOX' => $this->mailbox($account, 'INBOX', MailboxSpecialUse::INBOX),
            'Trash' => $this->mailbox($account, 'Trash', MailboxSpecialUse::TRASH),
        ];

        if (null !== $archive) {
            $mailboxes[$archive] = $this->mailbox($account, $archive, MailboxSpecialUse::ARCHIVE);
        }

        foreach ([$source, $rowLandsIn, $destinationPath] as $path) {
            if (null !== $path) {
                $mailboxes[$path] ??= $this->mailbox($account, $path);
            }
        }

        $row            = new Message();
        $row->account   = $account;
        $row->messageId = $messageId;

        // A row mid-move carries no UID — relocateTo() cleared it the moment
        // the user acted, and the envelope is where the source address
        // survives. A row that still held it would hide a wrong adoption.
        null !== $rowLandsIn
            ? $row->relocateTo($mailboxes[$rowLandsIn])
            : $row->relocateTo($mailboxes[$source], true === $rowKeepsUid || in_array($action, ['seen', 'unseen', 'flag', 'unflag', 'delete'], true) ? $uid : null);

        new ReflectionProperty(Message::class, 'id')->setValue($row, 1);

        $messages = $this->createStub(MessageRepository::class);
        $messages->method('findBy')->willReturn([$row]);

        $folders = $this->createStub(MailboxRepository::class);
        $folders->method('find')->willReturn($mailboxes[$source]);
        $folders->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($mailboxes): ?Mailbox {
                foreach ($mailboxes as $mailbox) {
                    if (($criteria['specialUse'] ?? null) === $mailbox->specialUse) {
                        return $mailbox;
                    }
                }

                return null;
            },
        );

        // Makes the folder on the server, as the real one does, and says
        // which it was asked for.
        $provisioner = $this->createStub(ImapFolderProvisioner::class);
        $provisioner->method('ensureSpecialUse')->willReturnCallback(
            function (Account $account, ImapSession $session, MailboxSpecialUse $specialUse): ?string {
                $this->provisioned[] = $specialUse->value;

                return $this->provisionable ? $this->made($session, ltrim($specialUse->value, '\\')) : null;
            },
        );
        $provisioner->method('ensureExactPath')->willReturnCallback(
            function (Account $account, ImapSession $session, string $path): ?string {
                $this->provisioned[] = $path;

                return $this->provisionable ? $this->made($session, $path) : null;
            },
        );

        $handler = new ApplyImapFlagsHandler(
            $messages,
            $folders,
            $this->logger(),
            self::getContainer()->get(ImapConnector::class),
            $this->createStub(EntityManagerInterface::class),
            $provisioner,
            $this->createStub(AccountRepository::class),
        );

        $handler(new ApplyImapFlagsMessage([1 => 10], $action, $destinationPath, [1 => $uid]));

        return $row;
    }

    private function made(ImapSession $session, string $path): string
    {
        $session->create($path);

        return $path;
    }

    private function mailbox(Account $account, string $path, ?MailboxSpecialUse $specialUse = null): Mailbox
    {
        $mailbox             = new Mailbox();
        $mailbox->account    = $account;
        $mailbox->name       = $path;
        $mailbox->fullPath   = $path;
        $mailbox->specialUse = $specialUse;

        return $mailbox;
    }

    private function logger(): AbstractLogger
    {
        return new class ($this->logged) extends AbstractLogger {
            /** @param list<array{level: string, message: string}> $logged */
            public function __construct(public array &$logged)
            {
            }

            /** @param array<mixed> $context */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };
    }
}
