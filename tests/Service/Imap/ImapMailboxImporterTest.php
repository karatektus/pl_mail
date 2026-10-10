<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Mailbox;
use App\Service\Imap\ImapImportOutcome;
use App\Service\Imap\ImapMailboxImporter;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * A folder's history arrives newest first, a page at a time, and no single
 * message can stop it.
 *
 * A first sync used to read a folder from its oldest message to its newest in
 * one job. The mail somebody was waiting for came last, the folder after it
 * waited for all of it, and so did every other account (#42). The importer is
 * what brings the history in now: this is what one call to it does to a
 * folder, checked in the database it writes to.
 *
 * The server is a script (FakeImportClient). Everything else is real — the
 * importer, the paged fetch, MessageSyncer and the pipeline behind it — since
 * the claims are about what ends up stored and where the floor stands, and
 * those are the things a double would be asserting into existence.
 *
 * PAGE_SIZE is fifty; the folders here hold a hundred and thirty, which is two
 * full pages and a part of one.
 */
final class ImapMailboxImporterTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private const int TOP = 130;

    private Connection $connection;
    private ImapMailboxImporter $importer;
    private int $mailboxId;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);
        $this->importer   = static::getContainer()->get(ImapMailboxImporter::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();

        // As ImapImportPlan leaves a folder whose highest UID is TOP: new mail
        // is everything above it, and the history starts just above too.
        $mailbox                 = new Mailbox();
        $mailbox->account        = $this->account;
        $mailbox->name           = 'INBOX';
        $mailbox->fullPath       = 'INBOX';
        $mailbox->isSyncEnabled  = true;
        $mailbox->lastSeenUid    = self::TOP;
        $mailbox->importFloorUid = self::TOP + 1;

        $this->em->persist($mailbox);
        $this->em->flush();

        $this->mailboxId = (int) $mailbox->id;
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheFirstPageIsTheNewestFiftyRatherThanTheOldest(): void
    {
        $server = FakeImportClient::holding($this->mails(range(1, self::TOP)));

        $outcome = $this->importer->importPage($this->mailboxId, $server);

        self::assertSame(ImapImportOutcome::More, $outcome);
        self::assertSame(range(81, 130), $this->storedUids());

        $mailbox = $this->row();

        self::assertSame(81, (int) $mailbox['import_floor_uid'], 'the floor stands at the lowest UID brought in');
        self::assertSame(130, (int) $mailbox['import_total']);
        self::assertSame(80, (int) $mailbox['import_remaining']);
        self::assertSame(self::TOP, (int) $mailbox['last_seen_uid'], 'the mark for new mail is not the importer\'s to move');
    }

    public function testPageAfterPageReachesTheBottomAndThenSaysSo(): void
    {
        $server = FakeImportClient::holding($this->mails(range(1, self::TOP)));

        self::assertSame(ImapImportOutcome::More, $this->importer->importPage($this->mailboxId, $server));
        self::assertSame(ImapImportOutcome::More, $this->importer->importPage($this->mailboxId, $server));
        self::assertSame(ImapImportOutcome::Finished, $this->importer->importPage($this->mailboxId, $server));

        self::assertSame(range(1, 130), $this->storedUids());
        self::assertSame(0, (int) $this->row()['import_floor_uid'], 'zero is "nothing left below"');
        self::assertSame(0, (int) $this->row()['import_remaining']);

        // And a finished folder is left alone: nothing more is asked of the server.
        $asked = count($server->fetched);

        self::assertSame(ImapImportOutcome::Finished, $this->importer->importPage($this->mailboxId, $server));
        self::assertCount($asked, $server->fetched);
    }

    /**
     * UIDs have holes. A page is fifty MESSAGES, found by asking what exists,
     * and not a span of fifty UIDs — which in a folder somebody has tidied is
     * a handful of messages, or none.
     */
    public function testAPageIsFiftyMessagesHoweverTheirUidsAreSpread(): void
    {
        // Every third UID only.
        $server = FakeImportClient::holding($this->mails(range(1, self::TOP, 3)));

        $this->importer->importPage($this->mailboxId, $server);

        self::assertCount(44, $this->storedUids(), 'all forty-four of them fit one page');
        self::assertSame(0, (int) $this->row()['import_floor_uid']);
    }

    /**
     * What is already here is not downloaded again to find that out. Gmail
     * over IMAP shows every message in All Mail as well as in its folder, and
     * a folder the old oldest-first pass had half read is planned again from
     * the top; either way the stored messages are simply not asked for.
     */
    public function testMessagesAlreadyStoredAreNeverFetchedAgain(): void
    {
        $server = FakeImportClient::holding($this->mails(range(1, self::TOP)));

        // Fifty of them are here already: 11 to 60, one page of a folder that
        // held sixty at the time.
        $this->importer->importPage($this->mailboxId, FakeImportClient::holding($this->mails(range(1, 60))));
        self::assertSame(range(11, 60), $this->storedUids());

        // Planned again from the top, as an upgrade or a rebuilt folder is.
        $this->connection->executeStatement(
            'UPDATE mailbox SET import_floor_uid = ?, import_total = NULL, import_remaining = NULL WHERE id = ?',
            [self::TOP + 1, $this->mailboxId],
        );
        $this->em->clear();

        $this->importer->importPage($this->mailboxId, $server);
        $this->importer->importPage($this->mailboxId, $server);

        $asked = array_merge(...$server->fetched);

        self::assertSame([], array_values(array_intersect($asked, range(11, 60))), 'none of the stored fifty was asked for');
        self::assertSame(80, (int) $this->row()['import_total'], 'the total is what was missing, not what the folder holds');
    }

    /**
     * A message that will not store holds the floor where it is, and the rest
     * of its page is stored all the same. The next call is that message again
     * with whatever is next below it.
     */
    public function testAMessageThatWillNotStoreCostsItselfAndIsAskedForAgain(): void
    {
        $script       = $this->mails(range(1, self::TOP));
        $script[100]  = new \RuntimeException('no content found');
        $server       = FakeImportClient::holding($script);

        self::assertSame(ImapImportOutcome::Retry, $this->importer->importPage($this->mailboxId, $server));

        self::assertSame(array_values(array_diff(range(81, 130), [100])), $this->storedUids());
        self::assertSame(self::TOP + 1, (int) $this->row()['import_floor_uid'], 'the floor has not moved past it');
        self::assertSame(1, (int) $this->row()['import_page_attempts']);

        // It parses now — a fix shipped, say. The same page is asked for
        // again: the one that failed, and the forty-nine below what is stored.
        $server->script[100] = $this->mails([100])[100];

        self::assertSame(ImapImportOutcome::More, $this->importer->importPage($this->mailboxId, $server));

        self::assertContains(100, $this->storedUids());
        self::assertSame(0, (int) $this->row()['import_page_attempts'], 'a page that goes through earns the count back');
    }

    /**
     * Held, not held for ever. After MAX_PAGE_ATTEMPTS the floor moves past
     * the message, and the folder's history goes on without it.
     */
    public function testAMessageThatNeverStoresIsPassedOverAfterFiveTries(): void
    {
        $script      = $this->mails(range(1, self::TOP));
        $script[100] = new \RuntimeException('no content found');
        $server      = FakeImportClient::holding($script);

        for ($try = 1; $try < ImapMailboxImporter::MAX_PAGE_ATTEMPTS; ++$try) {
            self::assertSame(ImapImportOutcome::Retry, $this->importer->importPage($this->mailboxId, $server), sprintf('try %d', $try));
        }

        self::assertNotSame(ImapImportOutcome::Retry, $this->importer->importPage($this->mailboxId, $server));
        self::assertLessThan(100, (int) $this->row()['import_floor_uid'], 'the floor is below it now');

        // And it is not asked for again: the folder finishes without it.
        while (ImapImportOutcome::Finished !== $this->importer->importPage($this->mailboxId, $server)) {
            continue;
        }

        self::assertSame(array_values(array_diff(range(1, 130), [100])), $this->storedUids());
    }

    /**
     * The counter new mail uses is not touched from down here. The two ends
     * of a folder are read by different workers, and on one ledger a message
     * failing at each end would take the count from the other for good.
     */
    public function testTheImportKeepsItsOwnCountAndLeavesTheOneForNewMailAlone(): void
    {
        $this->connection->executeStatement(
            'UPDATE mailbox SET failed_uid = 131, failed_uid_attempts = 3 WHERE id = ?',
            [$this->mailboxId],
        );
        $this->em->clear();

        $script      = $this->mails(range(1, self::TOP));
        $script[100] = new \RuntimeException('no content found');

        $this->importer->importPage($this->mailboxId, FakeImportClient::holding($script));

        self::assertSame(131, (int) $this->row()['failed_uid']);
        self::assertSame(3, (int) $this->row()['failed_uid_attempts']);
    }

    public function testAnEmptyFolderIsFinishedAtOnce(): void
    {
        self::assertSame(ImapImportOutcome::Finished, $this->importer->importPage($this->mailboxId, FakeImportClient::holding([])));
        self::assertSame(0, (int) $this->row()['import_floor_uid']);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** @return list<int> */
    private function storedUids(): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT imap_uid FROM message WHERE mailbox_id = ? ORDER BY imap_uid',
            [$this->mailboxId],
        ));
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $this->em->clear();

        return (array) $this->connection->fetchAssociative(
            'SELECT last_seen_uid, import_floor_uid, import_total, import_remaining, import_page_attempts, failed_uid, failed_uid_attempts FROM mailbox WHERE id = ?',
            [$this->mailboxId],
        );
    }

    /**
     * @param list<int> $uids
     *
     * @return array<int, ImapMessage>
     */
    private function mails(array $uids): array
    {
        $mails = [];

        foreach ($uids as $uid) {
            $raw = "From: Sender <sender@example.test>\r\n"
                . "To: {$this->account->email}\r\n"
                . "Subject: History {$uid}\r\n"
                . "Date: Mon, 21 Sep 2026 10:00:00 +0200\r\n"
                . "Message-ID: <history-{$uid}@example.test>\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\nHello.\r\n";

            $mails[$uid] = ImapMessage::fromString($raw, ImapConnectionFactory::config())->setUid($uid);
        }

        return $mails;
    }
}
