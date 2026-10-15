<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Infrastructure\Imap\Utf8AwareMessageDecoder;
use App\Service\Imap\ImapUidPresence;
use App\Service\Imap\MessageSyncer;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * A batch of fetched mail reaches the database, and one message the database
 * will not take costs that message rather than the folder.
 *
 * MessageSyncer writes a batch of fifty in one flush, and a flush is all or
 * nothing. A raw latin-1 attachment name was enough to have it refused
 * (`invalid byte sequence for encoding "UTF8": 0xe4 0x72 0x75`), and the
 * refusal named no message: the mark stayed below the batch, the next sync
 * fetched the same fifty, and the folder never got past them.
 *
 * Real parsed mail through the real processBatch(), because both faults live
 * where webklex's output meets the schema, and a double of either side would
 * assert the answer into existence.
 */
final class BatchStorageTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;
    private int $mailboxId;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();

        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = 'INBOX';
        $mailbox->fullPath      = 'INBOX';
        $mailbox->isSyncEnabled = true;

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

    public function testAnAttachmentNamedInRawLatin1IsStoredUnderItsName(): void
    {
        // Not an encoded word: the bytes a Windows client writes when it does
        // not bother with RFC 2047 or 2231 at all.
        $this->storeBatch($this->mail(101, attachment: "Erkl\xE4rung.pdf"));

        self::assertSame(
            ['Erklärung.pdf'],
            $this->connection->fetchFirstColumn(
                'SELECT p.filename FROM message_part p JOIN message m ON m.id = p.message_id WHERE m.mailbox_id = ?',
                [$this->mailboxId],
            ),
        );
    }

    public function testABodyThatSaysUtf8AndCarriesAWindowsEuroIsStoredReadable(): void
    {
        // Valid UTF-8 ("für") beside one raw windows-1252 byte (0x80, "€"),
        // under a label that says UTF-8. webklex converts nothing when the
        // label already names the target, so the bytes went to body_html as
        // they came, and the INSERT was refused on its 30th parameter.
        $this->storeBatch($this->mail(101, html: "<p>Nur 10 \x80 für Sie</p>"));

        self::assertSame(
            ['<p>Nur 10 € für Sie</p>'],
            $this->connection->fetchFirstColumn(
                'SELECT body_html FROM message WHERE mailbox_id = ?',
                [$this->mailboxId],
            ),
            'the valid UTF-8 around the one bad byte must survive the repair',
        );
    }

    public function testABodyLabelledUsAsciiAndWrittenInLatin1IsStoredReadable(): void
    {
        // #81. webklex converts nothing under this label, on the reasoning
        // that ASCII is UTF-8 already, so the decoder that guards every other
        // body is never asked and the raw 0xFC went to the column as it came.
        $this->storeBatch(
            $this->parsed(101, "Content-Type: text/plain; charset=us-ascii\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\nGr\xFC\xDFe\r\n"),
            $this->parsed(102, "Content-Type: text/html; charset=us-ascii\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n<p>Gr\xFC\xDFe</p>\r\n"),
        );

        self::assertSame(
            [['body_text' => 'Grüße', 'body_html' => ''], ['body_text' => '', 'body_html' => '<p>Grüße</p>']],
            array_map(
                static fn (array $row): array => array_map(trim(...), $row),
                $this->connection->fetchAllAssociative(
                    'SELECT body_text, body_html FROM message WHERE mailbox_id = ? ORDER BY imap_uid',
                    [$this->mailboxId],
                ),
            ),
        );
    }

    public function testAnIdWithARawByteInItIsStoredAndStillAnswersItsReply(): void
    {
        // The id is ASCII by the standard and a mailer that builds one out of
        // an unencoded host name does not care. All three headers lost their
        // message on it, and they have to come out of the repair the same,
        // or the reply stops finding what it answers.
        $this->storeBatch(
            $this->parsed(101, self::PLAIN, id: "<pr\xFCf-1@example.test>"),
            $this->parsed(
                102,
                self::PLAIN,
                id: '<reply@example.test>',
                headers: "In-Reply-To: <pr\xFCf-1@example.test>\r\nReferences: <pr\xFCf-1@example.test>\r\n",
            ),
        );

        $rows = $this->connection->fetchAllAssociative(
            'SELECT message_id, in_reply_to, thread_references FROM message WHERE mailbox_id = ? ORDER BY imap_uid',
            [$this->mailboxId],
        );

        self::assertCount(2, $rows, 'both messages are stored');
        self::assertSame('prüf-1@example.test', $rows[0]['message_id']);
        self::assertSame(['prüf-1@example.test'], json_decode((string) $rows[1]['in_reply_to'], true));
        self::assertSame(['prüf-1@example.test'], json_decode((string) $rows[1]['thread_references'], true));
    }

    public function testAnAttachmentWhoseTypeOrIdCarriesARawByteIsStored(): void
    {
        // Neither token is decoded by anything on the way to the row, and
        // either one refused the part's INSERT and the message with it.
        $part = static fn (string $type, string $id): string
            => "Content-Type: multipart/mixed; boundary=\"B\"\r\n\r\n"
            . "--B\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nAnbei.\r\n"
            . "--B\r\nContent-Type: {$type}; name=\"a.pdf\"\r\n"
            . "Content-Disposition: attachment; filename=\"a.pdf\"\r\n{$id}"
            . "Content-Transfer-Encoding: base64\r\n\r\nJVBERi0xLjQK\r\n--B--\r\n";

        $this->storeBatch(
            $this->parsed(101, $part("application/x-b\xFCro", '')),
            $this->parsed(102, $part('application/pdf', "Content-ID: <b\xFCro@example.test>\r\n")),
        );

        $parts = $this->connection->fetchAllAssociative(
            'SELECT p.content_type, p.content_id FROM message_part p JOIN message m ON m.id = p.message_id'
            . ' WHERE m.mailbox_id = ? ORDER BY m.imap_uid',
            [$this->mailboxId],
        );

        // The first part's id is not asserted: it has no Content-ID, and
        // webklex makes one up out of a hash of the content.
        self::assertSame(['application/x-büro', 'application/pdf'], array_column($parts, 'content_type'));
        self::assertSame('büro@example.test', $parts[1]['content_id']);
    }

    public function testAMessageTheDatabaseRefusesCostsOnlyThatMessage(): void
    {
        // Stands in for whatever the database refuses next. Thrown after the
        // row's INSERT, inside the flush's transaction, which is where a real
        // refusal surfaces and what makes Doctrine close the manager.
        $this->em->getEventManager()->addEventListener([Events::postPersist], new class {
            public function postPersist(PostPersistEventArgs $args): void
            {
                $entity = $args->getObject();

                if ($entity instanceof Message && 102 === $entity->imapUid) {
                    throw new \RuntimeException('SQLSTATE[22021]: invalid byte sequence for encoding "UTF8"');
                }
            }
        });

        $this->storeBatch($this->mail(101), $this->mail(102), $this->mail(103));

        self::assertSame(
            [101, 103],
            array_map('intval', $this->connection->fetchFirstColumn(
                'SELECT imap_uid FROM message WHERE mailbox_id = ? ORDER BY imap_uid',
                [$this->mailboxId],
            )),
            'the whole batch was refused along with the one message',
        );

        $mailbox = $this->connection->fetchAssociative(
            'SELECT failed_uid, failed_uid_attempts, last_seen_uid FROM mailbox WHERE id = ?',
            [$this->mailboxId],
        );

        self::assertSame(102, (int) $mailbox['failed_uid'], 'the refused message is the one held back');
        self::assertSame(1, (int) $mailbox['failed_uid_attempts'], 'and it counts towards being let go');
        self::assertSame(101, (int) $mailbox['last_seen_uid'], 'the mark stays below it, so it is asked for again');
    }

    /**
     * A row that reached this folder after the run began is not a failure.
     *
     * The syncer reads the UIDs a folder holds once, before it fetches. A move
     * made in the app while it is fetching files a row under a UID that list
     * does not have, so the batch tries to insert it a second time and the
     * unique index on (mailbox, uid) refuses. That refusal is right. What was
     * wrong is what came after: every such message was logged as one that
     * could not be built and held back for a retry (#37).
     */
    public function testAUidFiledWhileTheRunWasFetchingIsNotStoredTwiceOrHeldBack(): void
    {
        // The other writer, landing between the snapshot and the batch.
        $this->storeBatch($this->mail(102));

        // The run that began before it: its list of held UIDs is empty.
        $this->storeBatch($this->mail(101), $this->mail(102), $this->mail(103));

        self::assertSame(
            [101, 102, 103],
            array_map('intval', $this->connection->fetchFirstColumn(
                'SELECT imap_uid FROM message WHERE mailbox_id = ? ORDER BY imap_uid',
                [$this->mailboxId],
            )),
        );

        $mailbox = $this->connection->fetchAssociative(
            'SELECT failed_uid, failed_uid_attempts, last_seen_uid FROM mailbox WHERE id = ?',
            [$this->mailboxId],
        );

        self::assertNull($mailbox['failed_uid'], 'a message the folder already holds was held back as a failure');
        self::assertSame(0, (int) $mailbox['failed_uid_attempts']);
        self::assertSame(103, (int) $mailbox['last_seen_uid'], 'the mark must pass a UID that is already stored');
    }

    /**
     * A message the library cannot build never reached this code: the fetch
     * threw, the page never arrived, and nothing counted the failure — so the
     * folder stopped at it on every sync for as long as the message existed
     * (#45). It arrives beside its page now, and is held like any other
     * message that would not store: the rest of the page is written, the mark
     * waits below it, and the mailbox records which UID it is waiting for.
     */
    public function testAMessageTheLibraryCouldNotReadIsHeldForARetryAndCostsNothingElse(): void
    {
        $this->storeBatchWith([102 => new \RuntimeException('no content found')], $this->mail(101), $this->mail(103));

        self::assertSame([101, 103], $this->storedUids(), 'its neighbours are stored');

        $mailbox = $this->mailboxRow();

        self::assertSame(102, (int) $mailbox['failed_uid']);
        self::assertSame(1, (int) $mailbox['failed_uid_attempts']);
        self::assertSame(101, (int) $mailbox['last_seen_uid'], 'the mark waits below it, so the next sync asks again');
    }

    /**
     * Held, not held for ever. A message the parser will never take is let go
     * after MessageSyncer::MAX_UID_ATTEMPTS syncs, and the mark moves past it
     * — which is the whole difference between losing one message and losing a
     * folder.
     */
    public function testAfterEnoughSyncsItIsLetGoAndTheMarkMovesPastIt(): void
    {
        $unreadable = [102 => new \RuntimeException('no content found')];

        for ($sync = 1; $sync <= 4; ++$sync) {
            $this->storeBatchWith($unreadable, $this->mail(101), $this->mail(103));

            self::assertSame(101, (int) $this->mailboxRow()['last_seen_uid'], sprintf('still held after sync %d', $sync));
        }

        $this->storeBatchWith($unreadable, $this->mail(101), $this->mail(103));

        $mailbox = $this->mailboxRow();

        self::assertSame(5, (int) $mailbox['failed_uid_attempts']);
        self::assertSame(103, (int) $mailbox['last_seen_uid'], 'the fifth failure lets it go');
        self::assertSame([101, 103], $this->storedUids(), 'and nothing was stored twice on the way');
    }

    /**
     * It is given up even when it is the newest message in the folder, with
     * nothing above it to carry the mark past. Otherwise it would be fetched
     * and fail on every poll until other mail arrived.
     */
    public function testTheNewestMessageInTheFolderIsLetGoToo(): void
    {
        for ($sync = 1; $sync <= 5; ++$sync) {
            $this->storeBatchWith([102 => new \RuntimeException('no content found')], $this->mail(101));
        }

        self::assertSame(102, (int) $this->mailboxRow()['last_seen_uid']);
    }

    /**
     * The retry counter belongs to the LOWEST failure of a run. Told of the
     * unreadable messages in any other order than by UID, two of them in one
     * folder would take the counter from each other on every sync, neither
     * would reach the attempt that lets it go, and the folder would be held
     * between them for good.
     */
    public function testTwoUnreadableMessagesDoNotHoldAFolderBetweenThem(): void
    {
        $unreadable = [
            104 => new \RuntimeException('no content found'),
            102 => new \RuntimeException('no content found'),
        ];

        for ($sync = 1; $sync <= 5; ++$sync) {
            $this->storeBatchWith($unreadable, $this->mail(101), $this->mail(103), $this->mail(105));
        }

        $mailbox = $this->mailboxRow();

        self::assertSame(103, (int) $mailbox['last_seen_uid'], 'the lower one was counted five times and let go');
        self::assertSame(104, (int) $mailbox['failed_uid'], 'and the counter has moved on to the next');
        self::assertSame(1, (int) $mailbox['failed_uid_attempts']);
    }

    /**
     * `lastSeenUid+1:*` hands back the newest message on every sync. If the
     * library trips over it this time, it is still a message this folder
     * holds, and holding it back would reset the counter a real failure is
     * being counted on.
     */
    public function testAMessageAlreadyStoredIsNotAFailureWhateverTheLibraryMadeOfItThisTime(): void
    {
        $this->storeBatch($this->mail(101));

        $synced = [101 => true];

        $this->invokeProcessBatch([], $synced, [101 => new \RuntimeException('no content found')]);

        $mailbox = $this->mailboxRow();

        self::assertNull($mailbox['failed_uid']);
        self::assertSame(101, (int) $mailbox['last_seen_uid']);
    }

    private function storeBatch(ImapMessage ...$batch): void
    {
        $synced = [];

        $this->invokeProcessBatch($batch, $synced, []);
    }

    /**
     * One sync's worth: the mark and the stored UIDs read fresh, as
     * syncMailbox() reads them before it fetches.
     *
     * @param array<int, \Throwable> $unreadable
     */
    private function storeBatchWith(array $unreadable, ImapMessage ...$batch): void
    {
        $synced = array_fill_keys($this->storedUids(), true);

        $this->invokeProcessBatch($batch, $synced, $unreadable, (int) $this->mailboxRow()['last_seen_uid']);
    }

    /** @return list<int> */
    private function storedUids(): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT imap_uid FROM message WHERE mailbox_id = ? ORDER BY imap_uid',
            [$this->mailboxId],
        ));
    }

    /** @return array<string, mixed> */
    private function mailboxRow(): array
    {
        $this->em->clear();

        return (array) $this->connection->fetchAssociative(
            'SELECT failed_uid, failed_uid_attempts, last_seen_uid FROM mailbox WHERE id = ?',
            [$this->mailboxId],
        );
    }

    /**
     * @param array<int, ImapMessage> $batch
     * @param array<int, bool>        $synced
     * @param array<int, \Throwable>  $unreadable
     */
    private function invokeProcessBatch(array $batch, array &$synced, array $unreadable, int $lastSeenUid = 0): void
    {
        // The run-wide lowest held UID: a run of one batch starts with none.
        $lowest   = null;
        $presence = new ImapUidPresence(
            $this->account,
            static::getContainer()->get(ImapConnectionFactory::class),
            new NullLogger(),
        );

        new \ReflectionMethod(MessageSyncer::class, 'processBatch')->invokeArgs(
            static::getContainer()->get(MessageSyncer::class),
            [$batch, $this->mailboxId, (int) $this->account->id, $lastSeenUid, &$synced, &$lowest, $presence, $unreadable],
        );
    }

    private const string PLAIN = "Content-Type: text/plain; charset=UTF-8\r\n\r\nAnbei.\r\n";

    /**
     * A message whose body, id and extra headers are the test's to write byte
     * for byte, for the cases mail() has no parameter for.
     *
     * @param string $entity the entity headers and the body, from Content-Type on
     */
    private function parsed(int $uid, string $entity, ?string $id = null, string $headers = ''): ImapMessage
    {
        $id ??= "<batch-{$uid}@example.test>";

        $raw = "From: Sender <sender@example.test>\r\n"
            . "To: {$this->account->email}\r\n"
            . "Subject: Unterlagen {$uid}\r\n"
            . "Date: Mon, 21 Sep 2026 10:00:00 +0200\r\n"
            . "Message-ID: {$id}\r\n"
            . $headers
            . "MIME-Version: 1.0\r\n"
            . $entity;

        return ImapMessage::fromString($raw, Config::make([
            'decoding' => ['decoder' => ['message' => Utf8AwareMessageDecoder::class]],
        ]))->setUid($uid);
    }

    /** Parsed through the decoder ImapConnectionFactory installs, not the default. */
    private function mail(int $uid, ?string $attachment = null, ?string $html = null): ImapMessage
    {
        $raw = "From: Sender <sender@example.test>\r\n"
            . "To: {$this->account->email}\r\n"
            . "Subject: Unterlagen {$uid}\r\n"
            . "Date: Mon, 21 Sep 2026 10:00:00 +0200\r\n"
            . "Message-ID: <batch-{$uid}@example.test>\r\n"
            . "MIME-Version: 1.0\r\n";

        if (null !== $html) {
            $raw .= "Content-Type: text/html; charset=utf-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $html . "\r\n";
        } elseif (null === $attachment) {
            $raw .= "Content-Type: text/plain; charset=UTF-8\r\n\r\nAnbei.\r\n";
        } else {
            $raw .= "Content-Type: multipart/mixed; boundary=\"B\"\r\n\r\n"
                . "--B\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                . "Anbei.\r\n"
                . "--B\r\n"
                . "Content-Type: application/pdf; name=\"{$attachment}\"\r\n"
                . "Content-Disposition: attachment; filename=\"{$attachment}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . "JVBERi0xLjQK\r\n"
                . "--B--\r\n";
        }

        return ImapMessage::fromString($raw, Config::make([
            'decoding' => ['decoder' => ['message' => Utf8AwareMessageDecoder::class]],
        ]))->setUid($uid);
    }
}
