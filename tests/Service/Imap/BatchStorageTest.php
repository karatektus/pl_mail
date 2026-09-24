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

    private function storeBatch(ImapMessage ...$batch): void
    {
        $synced   = [];
        $lowest   = null;
        $presence = new ImapUidPresence(
            $this->account,
            static::getContainer()->get(ImapConnectionFactory::class),
            new NullLogger(),
        );

        new \ReflectionMethod(MessageSyncer::class, 'processBatch')->invokeArgs(
            static::getContainer()->get(MessageSyncer::class),
            [$batch, $this->mailboxId, (int) $this->account->id, 0, &$synced, &$lowest, $presence],
        );
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
