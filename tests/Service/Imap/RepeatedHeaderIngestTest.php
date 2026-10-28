<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Helper\RawMessageStorage;
use App\Entity\Mail\Mailbox;
use App\Service\Imap\ImapMailboxImporter;
use App\Tests\Infrastructure\ImapClient\UsesDovecot;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A message whose body arrives with its own header block in front is stored
 * once, with the body as its body (#173).
 *
 * Some messages on a Stalwart server answer RFC822.TEXT with the whole message.
 * webklex parsed that as the body, so a single-part HTML mail was shown with
 * `Delivered-To: …` and the rest of its headers above the content, and the
 * .eml written beside it held the headers twice. A multipart message rendered
 * correctly — its parts start after the boundary — but its .eml was doubled
 * just the same, which is why both shapes are here.
 *
 * Whether the doubling was the server's answer to RFC822.TEXT alone or is in
 * the stored message itself was never settled. plMail now fetches the whole
 * message in one piece (BODY[]), which avoids the first, and the repair stays
 * for the second: the doubled messages here are put on the test stack's
 * Dovecot as they are, and come back through the real fetch.
 *
 * Through the importer, like ImapDraftSentAtTest, because buildMessage() and
 * rawOf() are private and the claim is about what is stored.
 */
final class RepeatedHeaderIngestTest extends KernelTestCase
{
    use SeedsMarkerFixtures;
    use UsesDovecot;

    private Connection $connection;
    private ImapMailboxImporter $importer;
    private RawMessageStorage $rawStorage;
    private int $mailboxId;

    /** @var list<string> .eml files written to disk, which no rollback removes */
    private array $storedRawPaths = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);
        $this->importer   = static::getContainer()->get(ImapMailboxImporter::class);
        $this->rawStorage = static::getContainer()->get(RawMessageStorage::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();

        $mailbox                 = new Mailbox();
        $mailbox->account        = $this->account;
        $mailbox->name           = 'Deleted Items';
        $mailbox->fullPath       = 'Deleted Items';
        $mailbox->isSyncEnabled  = true;
        $mailbox->lastSeenUid    = 3;
        $mailbox->importFloorUid = 4;

        $this->em->persist($mailbox);
        $this->em->flush();

        $this->mailboxId = (int) $mailbox->id;
    }

    protected function tearDown(): void
    {
        foreach ($this->storedRawPaths as $path) {
            $this->rawStorage->delete($path);
        }

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheBodyIsTheBodyRatherThanTheHeadersAgain(): void
    {
        $html = "<!DOCTYPE html>\r\n<html><body><p>Your parcel is on its way.</p></body></html>\r\n";

        $this->import([
            1 => $this->doubled(1, "Content-Type: text/html; charset=utf-8\r\n", "\r\n\r\n" . $html),
            // No blank line between the repeated block and the DOCTYPE, the
            // other shape the issue reports.
            2 => $this->doubled(2, "Content-Type: text/html; charset=utf-8\r\n", "\r\n" . $html),
        ]);

        $rows = $this->rows();

        self::assertSame([1, 2], array_keys($rows));

        foreach ($rows as $uid => $row) {
            self::assertStringNotContainsString('Delivered-To', (string) $row['body_html'], "uid {$uid}: the headers are not body text");
            self::assertStringStartsWith('<!DOCTYPE html>', (string) $row['body_html'], "uid {$uid}");
            self::assertStringNotContainsString('Delivered-To', (string) $row['body_html_safe'], "uid {$uid}: nor on screen");
            self::assertSame(1, substr_count($this->raw($row), 'Delivered-To: '), "uid {$uid}: the .eml carries the headers once");
        }

        // Header and body separated by exactly one blank line, which is what
        // makes the .eml a message any client opens — including the one that
        // came without a blank line at all.
        self::assertStringContainsString("<parcel-2@example.test>\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<!DOCTYPE html>", $this->raw($rows[2]));
    }

    public function testAMultipartMessageIsStoredOnceRatherThanWithItsHeadersTwice(): void
    {
        $parts = "\r\n\r\n--B\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n\r\nYour parcel is on its way.\r\n"
            . "--B\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n\r\n<p>Your parcel is on its way.</p>\r\n"
            . "--B--\r\n";

        $this->import([
            1 => $this->doubled(1, "Content-Type: multipart/alternative; boundary=\"B\"\r\n", $parts),
        ]);

        $row = $this->rows()[1];

        self::assertSame(1, substr_count($this->raw($row), 'Delivered-To: '));
        self::assertStringContainsString('<p>Your parcel is on its way.</p>', (string) $row['body_html']);
        self::assertSame('Your parcel is on its way.', trim((string) $row['body_text']));
        self::assertFalse((bool) $row['has_attachments'], 'no part became an attachment on the way');
    }

    public function testAnOrdinaryMessageIsStoredAsItCame(): void
    {
        $header = $this->header(1, "Content-Type: text/plain; charset=utf-8\r\n");

        $this->import([
            1 => $header . "\r\nDelivered-To: is a line of this body.\r\n",
        ]);

        $row = $this->rows()[1];

        // A body that opens like a header is still the body. Only an exact
        // repeat of the header block is taken off.
        self::assertSame('Delivered-To: is a line of this body.', trim((string) $row['body_text']));
        self::assertSame(2, substr_count($this->raw($row), 'Delivered-To: '));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The message as such a server answers it: the header block, a blank line,
     * the same header block, then $rest — which starts with the blank line,
     * or with a lone line break where the server sent none.
     */
    private function doubled(int $uid, string $contentType, string $rest): string
    {
        $header = rtrim($this->header($uid, $contentType), "\r\n");

        return $header . "\r\n\r\n" . $header . $rest;
    }

    /**
     * Put the messages in the folder on the server, in UID order, and import
     * the page that holds them.
     *
     * @param array<int, string> $messages UID => the message; a fresh mailbox numbers from 1
     */
    private function import(array $messages): void
    {
        $session = $this->dovecotSession();
        $session->create('Deleted Items');

        foreach ($messages as $uid => $raw) {
            self::assertSame($uid, $session->append('Deleted Items', $raw, ['\\Seen']));
        }

        $this->importer->importPage($this->mailboxId, $session);
        $session->logout();
    }

    private function header(int $uid, string $contentType): string
    {
        return "Delivered-To: {$this->account->email}\r\n"
            . "From: Parcels <parcels@example.test>\r\n"
            . "To: {$this->account->email}\r\n"
            . "Subject: Parcel {$uid}\r\n"
            . "Date: Mon, 21 Sep 2026 10:00:00 +0200\r\n"
            . "Message-ID: <parcel-{$uid}@example.test>\r\n"
            . "MIME-Version: 1.0\r\n"
            . $contentType;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function raw(array $row): string
    {
        $path = (string) $row['raw_path'];

        self::assertNotSame('', $path, 'the sync wrote no .eml');

        $this->storedRawPaths[] = $path;

        return (string) file_get_contents($this->rawStorage->getAbsolutePath($path));
    }

    /**
     * @return array<int, array<string, mixed>> keyed by IMAP uid
     */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT imap_uid, body_html, body_html_safe, body_text, has_attachments, raw_path FROM message WHERE mailbox_id = ? ORDER BY imap_uid',
            [$this->mailboxId],
        ) as $row) {
            $rows[(int) $row['imap_uid']] = $row;
        }

        return $rows;
    }
}
