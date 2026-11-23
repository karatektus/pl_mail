<?php

declare(strict_types=1);

namespace App\Tests\Command\Backfill;

use App\Command\Backfill\AttachmentNameBackfillTask;
use App\Domain\Helper\AttachmentStorageHelper;
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

/**
 * Attachments stored under webklex's checksum get their real name back from
 * the message's own stored original (#718).
 *
 * Seeded as webklex left them: the row named with an eight-character
 * checksum, the bytes on disk, the original beside them. The unnamed part is
 * the positive control for the rule MisfiledBodyDetector depends on — a part
 * with no name anywhere keeps its checksum. Written first by James Morrison
 * in #721, against webklex's checksum; matched by the bytes since #82.
 */
final class AttachmentNameBackfillTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;
    private AttachmentNameBackfillTask $task;
    private RawMessageStorage $rawStorage;
    private AttachmentStorageHelper $attachmentStorage;

    /** @var list<string> */
    private array $storedRawPaths = [];

    /** @var list<string> */
    private array $storedFiles = [];

    private ?Mailbox $folder = null;

    private int $lastUid = 0;

    protected function setUp(): void
    {
        self::bootKernel();

        $container               = self::getContainer();
        $this->em                = $container->get(EntityManagerInterface::class);
        $this->connection        = $container->get(Connection::class);
        $this->task              = $container->get(AttachmentNameBackfillTask::class);
        $this->rawStorage        = $container->get(RawMessageStorage::class);
        $this->attachmentStorage = $container->get(AttachmentStorageHelper::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
    }

    protected function tearDown(): void
    {
        foreach ($this->storedRawPaths as $path) {
            $this->rawStorage->delete($path);
        }

        foreach ($this->storedFiles as $path) {
            $this->attachmentStorage->delete($path);
        }

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnAttachmentNamedOnlyInContentTypeGetsItsNameBack(): void
    {
        $id = $this->seed([
            ['57d5c7cb', "Content-Type: application/octet-stream; name=TNNTST02_10561121.PDF\r\nContent-Disposition: attachment\r\n", '%PDF-1.7 statement'],
            ['09704cea', "Content-Type: application/octet-stream\r\nContent-Disposition: attachment\r\n", '%PDF-1.7 nameless'],
        ]);

        self::assertSame(Command::SUCCESS, $this->runTask());

        self::assertSame(
            ['TNNTST02_10561121.PDF', '09704cea'],
            $this->names($id),
            'the named part is renamed; the part that names nothing keeps its checksum',
        );
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $id = $this->seed([
            ['57d5c7cb', "Content-Type: application/octet-stream; name=statement.pdf\r\nContent-Disposition: attachment\r\n", '%PDF-1.7 statement'],
        ]);

        $this->runTask();
        $this->runTask();

        self::assertSame(['statement.pdf'], $this->names($id));
    }

    /** A stored file that is not any attachment of the original borrows no name. */
    public function testAPartWhoseBytesMatchNothingInTheOriginalIsLeftAlone(): void
    {
        $id = $this->seed(
            [['0badf00d', "Content-Type: application/octet-stream; name=statement.pdf\r\nContent-Disposition: attachment\r\n", '%PDF-1.7 statement']],
            onDisk: ['something else entirely'],
        );

        $this->runTask();

        self::assertSame(['0badf00d'], $this->names($id));
    }

    /** The same bytes under two names: either would be a guess, so neither. */
    public function testBytesAttachedUnderTwoNamesAreLeftAlone(): void
    {
        $id = $this->seed([
            ['57d5c7cb', "Content-Type: application/pdf; name=one.pdf\r\nContent-Disposition: attachment\r\n", '%PDF-1.7 same'],
            ['two.pdf', "Content-Type: application/pdf\r\nContent-Disposition: attachment; filename=two.pdf\r\n", '%PDF-1.7 same'],
        ]);

        $this->runTask();

        self::assertSame(['57d5c7cb', 'two.pdf'], $this->names($id));
    }

    /**
     * @param list<array{string, string, string}> $parts       stored name, part headers, content
     * @param list<string>|null                   $onDisk      what the stored files hold; defaults to each part's content
     */
    private function seed(array $parts, ?array $onDisk = null): int
    {
        $raw = "From: NoReply@example.test\r\n"
            . "To: {$this->account->email}\r\n"
            . "Subject: Account Statement\r\n"
            . "Date: Tue, 17 Nov 2026 03:15:55 +0000\r\n"
            . sprintf("Message-ID: <%s@example.test>\r\n", uniqid('statement-', true))
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"B\"\r\n\r\n"
            . "--B\r\n"
            . "Content-Type: text/html; charset=us-ascii\r\n\r\n"
            . "<p>Your statement is attached.</p>\r\n";

        foreach ($parts as [, $headers, $content]) {
            $raw .= "--B\r\n" . $headers . "Content-Transfer-Encoding: base64\r\n\r\n"
                . base64_encode($content) . "\r\n";
        }

        $raw .= "--B--\r\n";

        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = 'Account Statement';
        $message->fromAddress    = 'NoReply@example.test';
        $message->receivedAt     = new DateTimeImmutable();
        $message->hasAttachments = true;
        $message->mailbox        = $this->folder();
        $message->imapUid        = ++$this->lastUid;

        $this->em->persist($message);

        foreach ($parts as $index => [$name, , $content]) {
            $bytes = $onDisk[$index] ?? $content;

            $part              = new MessagePart();
            $part->message     = $message;
            $part->contentType = 'application/octet-stream';
            $part->filename    = $name;
            $part->disposition = 'attachment';
            $part->isInline    = false;
            $part->size        = strlen($bytes);
            $part->storagePath = $this->attachmentStorage->store((int) $this->account->id, 0, $this->lastUid, $name, $bytes);
            $this->storedFiles[] = $part->storagePath;
            $message->messageParts->add($part);
            $this->em->persist($part);
        }

        $this->em->flush();

        $message->rawPath       = $this->rawStorage->store((int) $this->account->id, (int) $message->id, $raw);
        $this->storedRawPaths[] = $message->rawPath;

        $this->em->flush();

        return (int) $message->id;
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
