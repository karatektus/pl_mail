<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Tests\Infrastructure\ImapClient\UsesDovecot;
use App\Entity\Mail\Mailbox;
use App\Service\Imap\ImapMailboxImporter;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An unsent draft has no sentAt, whoever wrote it.
 *
 * The editor reads "sentAt is set" as "this has gone out" and answers 404, so a
 * draft another client left in the Drafts folder, stamped with its Date as
 * sentAt on the way in, could be seen in the thread and never opened.
 * receivedAt keeps the Date: it is when the row appeared.
 *
 * A draft is a draft as the server marks it. webklex, which fetched IMAP mail
 * before plMail's own client, stripped the backslash, so a server's `\Draft`
 * was stored as `Draft` and the app (Message::isDraft) did not treat it as a
 * draft at all. The flags are stored in the app's own spelling now
 * (MessageFlag::canonicalList, see ImapPagedFetch), and sentAt follows the
 * stored flag exactly as isDraft() reads it, so a row is never half of each.
 *
 * Through the importer and against the test stack's Dovecot, because
 * buildMessage() is private and the claim is about what a server's flags
 * become in the database.
 */
final class ImapDraftSentAtTest extends KernelTestCase
{
    use SeedsMarkerFixtures;
    use UsesDovecot;

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
        $mailbox->lastSeenUid    = 2;
        $mailbox->importFloorUid = 3;

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
        $session = $this->dovecotSession();
        $session->create('Drafts');
        $session->append('Drafts', $this->mail(1), ['\\Draft']);
        $session->append('Drafts', $this->mail(2), ['\\Seen']);

        $this->importer->importPage($this->mailboxId, $session);
        $session->logout();

        // Both were stored, each with its Date as receivedAt
        // (Mon, 21 Sep 2026 10:00:00 +0200 = 08:00:00 UTC).
        $rows = $this->rows();

        self::assertSame([1, 2], array_keys($rows));

        foreach ($rows as $uid => $row) {
            self::assertSame(
                gmmktime(8, 0, 0, 9, 21, 2026),
                (new \DateTimeImmutable((string) $row['received_at'], new \DateTimeZone('UTC')))->getTimestamp(),
                "uid {$uid} keeps its receivedAt",
            );
        }

        self::assertSame(['\\Draft'], json_decode((string) $rows[1]['flags'], true), 'stored as the app spells it');
        self::assertNull($rows[1]['sent_at'], 'a draft is an unsent draft');

        // And the ordinary message is untouched.
        self::assertSame(['\\Seen'], json_decode((string) $rows[2]['flags'], true));
        self::assertNotNull($rows[2]['sent_at']);
    }

    private function mail(int $uid): string
    {
        $raw = "From: Sender <sender@example.test>\r\n"
            . "To: {$this->account->email}\r\n"
            . "Subject: Draft {$uid}\r\n"
            . "Date: Mon, 21 Sep 2026 10:00:00 +0200\r\n"
            . "Message-ID: <draft-{$uid}@example.test>\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\nHello.\r\n";

        return $raw;
    }

    /**
     * @return array<int, array<string, mixed>> keyed by IMAP uid
     */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT imap_uid, sent_at, received_at, flags FROM message WHERE mailbox_id = ? ORDER BY imap_uid',
            [$this->mailboxId],
        ) as $row) {
            $rows[(int) $row['imap_uid']] = $row;
        }

        return $rows;
    }
}
