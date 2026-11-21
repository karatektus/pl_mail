<?php

declare(strict_types=1);

namespace App\Tests\Command\Backfill;

use App\Command\Backfill\AttachmentNameBackfillTask;
use App\Domain\Helper\MessageParser;
use App\Domain\Helper\RawMessageStorage;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Attachments stored under webklex's hash get their real name back from the
 * message's own stored original.
 *
 * Seeded as the sync used to leave them: the part row named with the hash
 * webklex computes for it. The unnamed part is the positive control for the
 * rule MisfiledBodyDetector depends on — a part with no name anywhere must
 * keep its hash.
 */
final class AttachmentNameBackfillTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;
    private AttachmentNameBackfillTask $task;
    private RawMessageStorage $rawStorage;

    /** @var list<string> */
    private array $storedRawPaths = [];

    private ?Mailbox $folder = null;

    private int $lastUid = 0;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->task       = $container->get(AttachmentNameBackfillTask::class);
        $this->rawStorage = $container->get(RawMessageStorage::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
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

    public function testAnAttachmentNamedOnlyInContentTypeGetsItsNameBack(): void
    {
        [$id, $hashes] = $this->seed([
            "Content-Type: application/octet-stream; name=TNNTST02_10561121.PDF\r\nContent-Disposition: attachment\r\n",
            "Content-Type: application/octet-stream\r\nContent-Disposition: attachment\r\n",
        ]);

        self::assertSame(Command::SUCCESS, $this->runTask());

        self::assertSame(
            ['TNNTST02_10561121.PDF', $hashes[1]],
            $this->names($id),
            'the named part is renamed; the part that names nothing keeps its hash',
        );
    }

    public function testRunningItAgainChangesNothing(): void
    {
        [$id] = $this->seed([
            "Content-Type: application/octet-stream; name=statement.pdf\r\nContent-Disposition: attachment\r\n",
        ]);

        $this->runTask();
        $this->runTask();

        self::assertSame(['statement.pdf'], $this->names($id));
    }

    public function testAPartWhoseHashMatchesNothingInTheOriginalIsLeftAlone(): void
    {
        // A name of the right shape that is not this part's hash: a mismatch
        // must not borrow another part's name.
        [$id] = $this->seed(
            ["Content-Type: application/octet-stream; name=statement.pdf\r\nContent-Disposition: attachment\r\n"],
            storedNames: ['0badf00d'],
        );

        $this->runTask();

        self::assertSame(['0badf00d'], $this->names($id));
    }

    /**
     * @param list<string>      $partHeaders
     * @param list<string>|null $storedNames what the rows are called; defaults to webklex's hashes
     *
     * @return array{int, list<string>}
     */
    private function seed(array $partHeaders, ?array $storedNames = null): array
    {
        $raw = "From: NoReply@example.test\r\n"
            . "To: {$this->account->email}\r\n"
            . "Subject: [00000001] Account Statement\r\n"
            . "Date: Tue, 17 Nov 2026 03:15:55 +0000\r\n"
            . sprintf("Message-ID: <%s@example.test>\r\n", uniqid('statement-', true))
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"B\"\r\n\r\n"
            . "--B\r\n"
            . "Content-Type: text/html; charset=us-ascii\r\n\r\n"
            . "<p>Your statement is attached.</p>\r\n";

        foreach ($partHeaders as $i => $headers) {
            $raw .= "--B\r\n" . $headers . "Content-Transfer-Encoding: base64\r\n\r\n"
                . base64_encode("%PDF-1.7 part {$i}") . "\r\n";
        }

        $raw .= "--B--\r\n";

        $hashes = [];

        foreach (ImapMessage::fromString($raw, MessageParser::config())->getAttachments() as $attachment) {
            $hashes[] = (string) $attachment->getHash();
        }

        self::assertCount(count($partHeaders), $hashes);

        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = 'Account Statement';
        $message->fromAddress    = 'NoReply@example.test';
        $message->receivedAt     = new DateTimeImmutable();
        $message->hasAttachments = true;
        $message->mailbox        = $this->folder();
        $message->imapUid        = ++$this->lastUid;

        $this->em->persist($message);

        foreach ($storedNames ?? $hashes as $i => $name) {
            $part              = new MessagePart();
            $part->message     = $message;
            $part->contentType = 'application/octet-stream';
            $part->filename    = $name;
            $part->disposition = 'attachment';
            $part->isInline    = false;
            $part->size        = 17;
            $part->storagePath = 'var/attachments/test/' . $name . '-' . $i;
            $message->messageParts->add($part);
            $this->em->persist($part);
        }

        $this->em->flush();

        $message->rawPath       = $this->rawStorage->store((int) $this->account->id, (int) $message->id, $raw);
        $this->storedRawPaths[] = $message->rawPath;

        $this->em->flush();

        return [(int) $message->id, $hashes];
    }

    private function folder(): Mailbox
    {
        if (null === $this->folder) {
            $this->folder                = new Mailbox();
            $this->folder->account       = $this->account;
            $this->folder->name          = 'INBOX';
            $this->folder->fullPath      = 'INBOX';
            $this->folder->isSyncEnabled = true;
            $this->folder->isIdleEnabled = false;
            $this->em->persist($this->folder);
        }

        return $this->folder;
    }

    /** @return list<string> */
    private function names(int $messageId): array
    {
        $this->em->clear();

        return array_values($this->connection->fetchFirstColumn(
            'SELECT filename FROM message_part WHERE message_id = ? ORDER BY id',
            [$messageId],
        ));
    }

    private function runTask(): int
    {
        return $this->task->run(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()));
    }
}
