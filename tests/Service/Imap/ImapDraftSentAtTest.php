<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Mailbox;
use App\Service\Imap\ImapMailboxImporter;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Webklex\PHPIMAP\Message as ImapMessage;
use Webklex\PHPIMAP\Support\FlagCollection;

/**
 * An unsent draft has no sentAt, whoever wrote it.
 *
 * The editor reads "sentAt is set" as "this has gone out" and answers 404, so a
 * draft another client left in the Drafts folder, stamped with its Date as
 * sentAt on the way in, could be seen in the thread and never opened.
 * receivedAt keeps the Date: it is when the row appeared.
 *
 * webklex strips the backslash, so what a real server's `\Draft` arrives as is
 * `Draft`, and the row stores it that way: the app (Message::isDraft) does not
 * treat it as a draft until something rewrites the flags, which is separate
 * from this. What this pins is that a row is never half of each — "not a draft
 * and never sent" — so sentAt follows the stored flag exactly as isDraft()
 * reads it.
 *
 * Through the importer, like ImapMailboxImporterTest, because buildMessage() is
 * private and the claim is about what is stored.
 */
final class ImapDraftSentAtTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

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

        $mailbox                 = new Mailbox();
        $mailbox->account        = $this->account;
        $mailbox->name           = 'Drafts';
        $mailbox->fullPath       = 'Drafts';
        $mailbox->isSyncEnabled  = true;
        $mailbox->lastSeenUid    = 3;
        $mailbox->importFloorUid = 4;

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

    public function testSentAtFollowsTheStoredDraftFlag(): void
    {
        $this->importer->importPage($this->mailboxId, FakeImportClient::holding([
            1 => $this->mail(1, ['Draft']),
            2 => $this->mail(2, ['\\Draft']),
            3 => $this->mail(3, ['Seen']),
        ]));

        // Presence companions: all three were stored, each with its Date as
        // receivedAt (Mon, 21 Sep 2026 10:00:00 +0200 = 08:00:00 UTC).
        $rows = $this->rows();

        self::assertSame([1, 2, 3], array_keys($rows));

        foreach ($rows as $uid => $row) {
            self::assertSame(
                gmmktime(8, 0, 0, 9, 21, 2026),
                (new \DateTimeImmutable((string) $row['received_at'], new \DateTimeZone('UTC')))->getTimestamp(),
                "uid {$uid} keeps its receivedAt",
            );
        }

        self::assertNull($rows[2]['sent_at'], 'a stored `\\Draft` is an unsent draft');

        // Not a draft as the app reads the flag, so not a half-state either.
        self::assertNotNull($rows[1]['sent_at'], '`Draft` is not `\\Draft` to isDraft()');

        // And the ordinary message is untouched.
        self::assertNotNull($rows[3]['sent_at']);
    }

    /**
     * @param list<string> $flags
     */
    private function mail(int $uid, array $flags): ImapMessage
    {
        $raw = "From: Sender <sender@example.test>\r\n"
            . "To: {$this->account->email}\r\n"
            . "Subject: Draft {$uid}\r\n"
            . "Date: Mon, 21 Sep 2026 10:00:00 +0200\r\n"
            . "Message-ID: <draft-{$uid}@example.test>\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\nHello.\r\n";

        return ImapMessage::fromString($raw, ImapConnectionFactory::config())
            ->setUid($uid)
            ->setFlags(FlagCollection::make(array_combine($flags, $flags)));
    }

    /**
     * @return array<int, array<string, mixed>> keyed by IMAP uid
     */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT imap_uid, sent_at, received_at FROM message WHERE mailbox_id = ? ORDER BY imap_uid',
            [$this->mailboxId],
        ) as $row) {
            $rows[(int) $row['imap_uid']] = $row;
        }

        return $rows;
    }
}
