<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Entity\Mail\Mailbox;
use App\Infrastructure\Imap\Utf8AwareMessageDecoder;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Service\Imap\ImapUidPresence;
use App\Service\Imap\MessageSyncer;
use App\Tests\Infrastructure\ImapClient\FakeImapServer;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * A message the sender wrote in HTML alone is found by the words in it (#477).
 *
 * Through the real IMAP batch path, like CryptoIngestTest, because the claim is
 * about the row as it is stored: search_vector is generated on insert, and the
 * words have to be in a column it reads by then. body_text stays what the
 * sender sent, which for this message is nothing.
 */
final class HtmlOnlyIngestTest extends KernelTestCase
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

    public function testTheWordsOfAnHtmlOnlyBodyAreSearchable(): void
    {
        $this->store(
            "Content-Type: text/html; charset=utf-8\r\n\r\n"
            . "<html><head><style>.gravatt { color: red }</style></head><body>"
            . "<table><tr><td>Your parcel arrives <b>tomorrow</b> in Gravatt.</td></tr></table>"
            . "<p>Gr&uuml;&szlig;e from Microsoft&nbsp;Teams</p></body></html>\r\n",
        );

        $row = $this->connection->fetchAssociative(
            "SELECT body_text, body_html,
                    search_vector @@ plainto_tsquery('english', 'gravatt')  AS gravatt,
                    search_vector @@ plainto_tsquery('english', 'tomorrow') AS tomorrow,
                    search_vector @@ plainto_tsquery('english', 'teams')    AS teams,
                    search_vector @@ plainto_tsquery('english', 'grüße')    AS umlaut,
                    search_vector @@ plainto_tsquery('english', 'color')    AS stylesheet
               FROM message WHERE mailbox_id = ?",
            [$this->mailboxId],
        );

        self::assertIsArray($row, 'the message was not stored at all');
        self::assertSame('', (string) $row['body_text'], 'body_text is the sender\'s plain part, and there was none');
        self::assertStringContainsString('Gravatt', (string) $row['body_html']);
        self::assertTrue($row['gravatt'], 'a word inside a table cell was not indexed');
        self::assertTrue($row['tomorrow'], 'a word inside <b> was not indexed');
        self::assertTrue($row['teams'], 'a word after &nbsp; was not indexed');
        self::assertTrue($row['umlaut'], 'an entity-encoded word was not indexed as the word it spells');
        self::assertFalse($row['stylesheet'], 'the stylesheet was indexed as if it were prose');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function store(string $mime): void
    {
        $raw = "From: Sender <sender@example.test>\r\n"
            . "To: {$this->account->email}\r\n"
            . "Subject: Out for delivery\r\n"
            . "Date: Mon, 21 Sep 2026 10:00:00 +0200\r\n"
            . "Message-ID: <html-only-" . bin2hex(random_bytes(4)) . "@example.test>\r\n"
            . "MIME-Version: 1.0\r\n"
            . $mime;

        $message = ImapMessage::fromString($raw, Config::make([
            'decoding' => ['decoder' => ['message' => Utf8AwareMessageDecoder::class]],
        ]))->setUid(101);

        $synced   = [];
        $lowest   = null;
        $presence = new ImapUidPresence(new FakeImapServer(static::getContainer()->get(ImapLoop::class))->session(), new NullLogger());

        new \ReflectionMethod(MessageSyncer::class, 'processBatch')->invokeArgs(
            static::getContainer()->get(MessageSyncer::class),
            [[$message], $this->mailboxId, (int) $this->account->id, 0, &$synced, &$lowest, $presence, []],
        );
    }
}
