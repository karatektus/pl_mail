<?php

declare(strict_types=1);

namespace App\Tests\Command\Backfill;

use App\Command\Backfill\InlineAttachmentBackfillTask;
use App\Domain\Enum\Mail\MessageFlag;
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
 * Attachments stored as inline parts that nothing displays become attachments
 * again, and their message gets its paperclip back (#753).
 *
 * Seeded as the old detector left Apple Mail's mail: a PDF with
 * "Content-Disposition: inline" and no Content-ID, stored with is_inline set
 * and has_attachments false. The two controls are the parts that must stay
 * inline: an image the body embeds by its cid, and a calendar invite, which
 * GmailMessageBuilder stores as inline by type so that it shows as an invite
 * card rather than as a chip.
 *
 * Against the real container and database, because what is worth pinning is
 * the walk's query and the reclassifier together: a repository that left the
 * Apple part out of the set would pass any test of the rule alone.
 */
final class InlineAttachmentBackfillTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;
    private InlineAttachmentBackfillTask $task;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->task       = $container->get(InlineAttachmentBackfillTask::class);

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

    public function testAnInlinePartWithoutAContentIdBecomesAnAttachmentRatherThanStayingHidden(): void
    {
        $id = $this->seed('<p>See attached.</p>', [
            ['report.pdf', 'application/pdf', null],
        ]);

        self::assertSame(Command::SUCCESS, $this->runTask());

        self::assertSame(['report.pdf' => false], $this->inlineByName($id));
        self::assertTrue($this->hasAttachments($id), 'the message must get its paperclip back');
    }

    public function testAnImageTheBodyEmbedsStaysInline(): void
    {
        $id = $this->seed('<p>Hello</p><img src="cid:logo@example.test">', [
            ['logo.png', 'image/png', 'logo@example.test'],
            ['photo.jpg', 'image/jpeg', null],
        ]);

        $this->runTask();

        self::assertSame(
            ['logo.png' => true, 'photo.jpg' => false],
            $this->inlineByName($id),
            'only the part the body never embeds is listed',
        );
    }

    /**
     * Beside a part the walk repairs, so the message is read: an invite alone
     * is left out by the walk's query and would pass without the guard.
     */
    public function testACalendarInviteStaysInlineRatherThanBecomingAChip(): void
    {
        $id = $this->seed('<p>Meeting, agenda attached</p>', [
            ['invite.ics', 'text/calendar', null],
            ['agenda.pdf', 'application/pdf', null],
        ]);

        $this->runTask();

        self::assertSame(['invite.ics' => true, 'agenda.pdf' => false], $this->inlineByName($id));
    }

    /**
     * A picture pasted into the editor is stored inline before the autosave
     * that writes its cid: into the body. The repair must not catch it there.
     */
    public function testADraftsInlineImageStaysInlineBeforeTheBodyNamesIt(): void
    {
        $id = $this->seed('<p>Look at this</p>', [
            ['pasted.png', 'image/png', 'a1b2@plmail'],
        ], [MessageFlag::DRAFT->value]);

        $this->runTask();

        self::assertSame(['pasted.png' => true], $this->inlineByName($id));
        self::assertFalse($this->hasAttachments($id));
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $id = $this->seed('<p>See attached.</p>', [
            ['report.pdf', 'application/pdf', null],
        ]);

        $this->runTask();
        $this->runTask();

        self::assertSame(['report.pdf' => false], $this->inlineByName($id));
        self::assertTrue($this->hasAttachments($id));
    }

    /**
     * Stored as the old detector stored it: every part inline, no attachments.
     *
     * @param list<array{string, string, string|null}> $parts filename, content type, Content-ID
     * @param list<string>                             $flags
     */
    private function seed(string $bodyHtml, array $parts, array $flags = []): int
    {
        $message                 = new Message();
        $message->flags          = $flags;
        $message->account        = $this->account;
        $message->subject        = 'Fwd: report';
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new DateTimeImmutable();
        $message->bodyHtml       = $bodyHtml;
        $message->hasAttachments = false;

        $this->em->persist($message);

        foreach ($parts as [$filename, $contentType, $contentId]) {
            $part              = new MessagePart();
            $part->message     = $message;
            $part->contentType = $contentType;
            $part->filename    = $filename;
            $part->contentId   = $contentId;
            $part->disposition = 'inline';
            $part->isInline    = true;
            $part->size        = 10;
            $part->storagePath = 'gmail://' . $filename;
            $message->messageParts->add($part);
            $this->em->persist($part);
        }

        $this->em->flush();

        return (int) $message->id;
    }

    /** @return array<string, bool> */
    private function inlineByName(int $messageId): array
    {
        $this->em->clear();

        $rows = $this->connection->fetchAllAssociative(
            'SELECT filename, is_inline FROM message_part WHERE message_id = ? ORDER BY id',
            [$messageId],
        );

        return array_column(
            array_map(static fn (array $row): array => [$row['filename'], (bool) $row['is_inline']], $rows),
            1,
            0,
        );
    }

    private function hasAttachments(int $messageId): bool
    {
        return (bool) $this->connection->fetchOne('SELECT has_attachments FROM message WHERE id = ?', [$messageId]);
    }

    private function runTask(): int
    {
        return $this->task->run(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()));
    }
}
