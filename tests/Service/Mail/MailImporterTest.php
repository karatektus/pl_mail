<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Mailbox;
use App\Infrastructure\Messaging\Message\ImportMailMessage;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Repository\Mail\MailboxRepository;
use App\Service\Mail\InitialImportState;
use App\Service\Mail\MailImporter;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * An account's history is started once, on the import queue, in the order a
 * person would want it — and is not started again while it is moving.
 *
 * The import is a chain of short jobs, each asking for the next (see
 * MailImporter). What is pinned here is everything around a page rather than
 * the page itself, which ImapMailboxImporterTest covers: what starts the
 * chain, what keeps a second one from starting beside it, which folder is
 * first, and what "still importing" means now that a folder's first sync ends
 * at once.
 */
final class MailImporterTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;
    private MailImporter $importer;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);
        $this->importer   = static::getContainer()->get(MailImporter::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnAccountWithHistoryToFetchIsStartedOnTheImportQueue(): void
    {
        $this->folder('INBOX', MailboxSpecialUse::INBOX, floor: 500);

        $this->importer->ensureRunning($this->account);

        $sent = $this->queue('ingest_backlog')->getSent();

        self::assertCount(1, $sent);
        self::assertInstanceOf(ImportMailMessage::class, $sent[0]->getMessage());
        self::assertSame($this->account->id, $sent[0]->getMessage()->accountId);
        self::assertSame([], $this->queue('ingest')->getSent(), 'history is never put on the queue new mail arrives on');
    }

    /**
     * Every sync of the account calls ensureRunning(). While the import is
     * moving, that must not put a second chain beside the first.
     */
    public function testAnImportThatIsMovingIsNotStartedAgain(): void
    {
        $this->folder('INBOX', MailboxSpecialUse::INBOX, floor: 500);

        $this->importer->ensureRunning($this->account);
        $this->importer->ensureRunning($this->account);
        $this->importer->ensureRunning($this->account);

        self::assertCount(1, $this->queue('ingest_backlog')->getSent());
    }

    /** The safety net: a chain that broke is picked up once it has gone quiet. */
    public function testAnImportThatHasGoneQuietIsStartedAgain(): void
    {
        $this->folder('INBOX', MailboxSpecialUse::INBOX, floor: 500);

        $this->importer->ensureRunning($this->account);

        $this->account->importBeatAt = new DateTimeImmutable(sprintf('-%d seconds', MailImporter::QUIET_SECONDS + 5));
        $this->em->flush();

        $this->importer->ensureRunning($this->account);

        self::assertCount(2, $this->queue('ingest_backlog')->getSent());
    }

    /**
     * A Microsoft account has no Mailbox rows to ask. Its import is the list
     * of folders that have not been read to their end, and it used to have no
     * import at all: its history travelled on the live queue.
     */
    public function testAMicrosoftAccountWithFoldersStillToReadIsStartedToo(): void
    {
        $this->account->authType      = AuthType::OAuth2->value;
        $this->account->oauthProvider = 'microsoft';
        $this->account->graphImport   = [['folder' => 'AAMkAD-inbox', 'since' => 1_800_000_000, 'next' => null]];
        $this->em->flush();

        self::assertTrue($this->importer->hasWork($this->account));

        $this->importer->ensureRunning($this->account);

        self::assertCount(1, $this->queue('ingest_backlog')->getSent());

        $this->account->graphImport = [];
        $this->em->flush();

        self::assertFalse($this->importer->hasWork($this->account), 'and is done when the list is empty');
    }

    public function testAnAccountWithNothingLeftToFetchIsLeftAlone(): void
    {
        $this->folder('INBOX', MailboxSpecialUse::INBOX, floor: 0);

        $this->importer->ensureRunning($this->account);

        self::assertFalse($this->importer->hasWork($this->account));
        self::assertSame([], $this->queue('ingest_backlog')->getSent());
    }

    /**
     * The order is the priority: the Inbox, what was written, a person's own
     * folders, the archive, and last the two nobody is waiting for.
     */
    public function testFoldersAreReadInTheOrderSomebodyWouldAskFor(): void
    {
        $this->folder('Trash', MailboxSpecialUse::TRASH, floor: 10);
        $this->folder('Archive', MailboxSpecialUse::ARCHIVE, floor: 10);
        $this->folder('Junk', MailboxSpecialUse::JUNK, floor: 10);
        $this->folder('Projects', null, floor: 10);
        $this->folder('Drafts', MailboxSpecialUse::DRAFTS, floor: 10);
        $this->folder('Sent', MailboxSpecialUse::SENT, floor: 10);
        $this->folder('INBOX', MailboxSpecialUse::INBOX, floor: 10);
        $this->folder('Already in', null, floor: 0);

        $order = array_map(
            static fn (Mailbox $mailbox): string => (string) $mailbox->fullPath,
            static::getContainer()->get(MailboxRepository::class)->findImporting($this->account),
        );

        self::assertSame(['INBOX', 'Sent', 'Drafts', 'Projects', 'Archive', 'Junk', 'Trash'], $order);
    }

    /** Nothing fetches these, so nothing would ever finish them. */
    public function testFoldersNobodySyncsAreNotWaitedFor(): void
    {
        $this->folder('INBOX', MailboxSpecialUse::INBOX, floor: 0);

        $off = $this->folder('Switched off', null, floor: null);
        $off->isSyncEnabled = false;

        $placeholder = $this->folder('[Gmail]', null, floor: null);
        $placeholder->isSelectable = false;

        $gone = $this->folder('Gone', null, floor: null);
        $gone->missingSince = new DateTimeImmutable();

        $this->em->flush();

        self::assertFalse($this->importer->hasWork($this->account));
    }

    /**
     * A folder gets its syncedAt at the end of its first sync, and that sync
     * now ends at once with the history still to come. "The import is over"
     * has to be read off the folder's own marker, or a mailbox's worth of old
     * mail is treated as mail that has just arrived.
     */
    public function testAFolderThatHasSyncedOnceIsStillImportingUntilItsHistoryIsIn(): void
    {
        $state = static::getContainer()->get(InitialImportState::class);

        $inbox = $this->folder('INBOX', MailboxSpecialUse::INBOX, floor: 500);
        $inbox->syncedAt = new DateTimeImmutable();
        $this->em->flush();

        self::assertFalse($state->isComplete($this->account));

        $inbox->importFloorUid = 0;
        $this->em->flush();

        self::assertTrue($state->isComplete($this->account));
    }

    /**
     * The poll, a push and the Sync button each queue a sync. One that began
     * after a request was made, and went through, has already seen what the
     * request was about.
     */
    public function testASyncRequestIsRedundantOnlyOnceALaterSyncHasGoneThrough(): void
    {
        $requestedAt = new SyncAccountMessage((int) $this->account->id)->requestedAt;

        self::assertNotNull($requestedAt, 'every request is stamped when it is made');
        self::assertFalse($this->account->isSyncedSince($requestedAt), 'nothing has synced at all');

        // A sync that was already running when the request was made.
        $this->account->syncBeganAt = new DateTimeImmutable('@' . ($requestedAt - 30));
        $this->account->recordSyncSuccess();

        self::assertFalse($this->account->isSyncedSince($requestedAt), 'it may have read the server before the request');

        // One that began afterwards, and has not finished.
        $this->account->syncBeganAt  = new DateTimeImmutable('@' . ($requestedAt + 5));
        $this->account->lastSyncedAt = new DateTimeImmutable('@' . ($requestedAt - 10));

        self::assertFalse($this->account->isSyncedSince($requestedAt), 'begun is not done');

        // And finished.
        $this->account->lastSyncedAt = new DateTimeImmutable('@' . ($requestedAt + 9));

        self::assertTrue($this->account->isSyncedSince($requestedAt));

        // Unless it failed.
        $this->account->recordSyncFailure('the server went away');

        self::assertFalse($this->account->isSyncedSince($requestedAt));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function folder(string $path, ?MailboxSpecialUse $use, ?int $floor): Mailbox
    {
        $mailbox                 = new Mailbox();
        $mailbox->account        = $this->account;
        $mailbox->name           = $path;
        $mailbox->fullPath       = $path;
        $mailbox->specialUse     = $use;
        $mailbox->isSyncEnabled  = true;
        $mailbox->importFloorUid = $floor;

        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }

    private function queue(string $name): InMemoryTransport
    {
        return static::getContainer()->get(sprintf('messenger.transport.%s', $name));
    }
}
