<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\User\User;
use App\Service\Mail\DraftAttachmentService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * What may be attached to a draft, what happens to the bytes, and what the
 * flag says afterwards.
 *
 * The refusals are the reason this is worth its own test: they are the only
 * feedback the compose window can give about an upload it will not take, and
 * they have to be decided before anything is written — a batch containing one
 * bad file must leave the good ones unattached rather than half-attached.
 */
final class DraftAttachmentServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private DraftAttachmentService $attachments;
    private AttachmentStorageHelper $storage;

    private Account $account;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em          = $container->get(EntityManagerInterface::class);
        $this->connection  = $container->get(Connection::class);
        $this->attachments = $container->get(DraftAttachmentService::class);
        $this->storage     = $container->get(AttachmentStorageHelper::class);

        $this->connection->beginTransaction();

        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── refusals ──────────────────────────────────────────────────────────

    /**
     * php.ini's own limit, reported per file. The window shows this string, so
     * it says what happened rather than "an error occurred".
     */
    public function testAnUploadThatHitThePhpLimitIsRefused(): void
    {
        $file = new UploadedFile($this->tempFile('x'), 'huge.pdf', null, UPLOAD_ERR_INI_SIZE, true);

        self::assertSame('File too large', $this->attachments->attach(new Message(), [$file]));
    }

    public function testAnUploadThatDidNotArriveIntactIsRefused(): void
    {
        $file = new UploadedFile($this->tempFile('x'), 'broken.pdf', null, UPLOAD_ERR_PARTIAL, true);

        self::assertSame('Upload failed', $this->attachments->attach(new Message(), [$file]));
    }

    /**
     * The application's own ceiling, which is lower than the one PHP enforces
     * — so this branch is reached by a file PHP was perfectly happy with, and
     * the message names the limit because the user cannot otherwise know it.
     */
    public function testAFileOverTheApplicationCeilingIsRefusedWithTheLimit(): void
    {
        $file = new UploadedFile(
            $this->sparseFile(DraftAttachmentService::MAX_BYTES + 1),
            'big.bin',
            null,
            null,
            true,
        );

        self::assertSame('File too large (max 25 MB)', $this->attachments->attach(new Message(), [$file]));
    }

    /**
     * The one that made the check worth writing as two passes: a rejected file
     * must not leave the ones before it attached to the draft.
     */
    public function testOneBadFileLeavesTheWholeBatchUnattached(): void
    {
        $message = new Message();

        $refusal = $this->attachments->attach($message, [
            new UploadedFile($this->tempFile('fine'), 'fine.txt', null, null, true),
            new UploadedFile($this->tempFile('x'), 'huge.pdf', null, UPLOAD_ERR_INI_SIZE, true),
        ]);

        self::assertSame('File too large', $refusal);
        self::assertCount(0, $message->messageParts, 'the acceptable file was attached anyway');
    }

    // ── storing and dropping ──────────────────────────────────────────────

    public function testAnAcceptedFileIsStoredAgainstTheDraftAndAnnounced(): void
    {
        $message = $this->draft();
        $since   = $this->latestSequence();

        $refusal = $this->attachments->attach($message, [
            new UploadedFile($this->tempFile('the bytes'), 'notes.txt', null, null, true),
        ]);

        self::assertNull($refusal);
        self::assertCount(1, $message->messageParts);

        $part = $message->messageParts->first();

        self::assertSame('notes.txt', $part->filename);
        self::assertSame('attachment', $part->disposition);
        self::assertFalse((bool) $part->isInline);
        self::assertSame('the bytes', file_get_contents($this->storage->getAbsolutePath($part->storagePath)));
        self::assertTrue((bool) $message->hasAttachments, 'the draft still says it has no files');
        self::assertNotSame([], $this->emailRowsSince($since), 'no client was told the draft changed');

        $this->attachments->deleteStoredFiles($message);
    }

    public function testRemovingAnAttachmentDeletesItsBytesAndClearsTheFlag(): void
    {
        $message = $this->draft();

        $this->attachments->attach($message, [
            new UploadedFile($this->tempFile('the bytes'), 'notes.txt', null, null, true),
        ]);

        $part = $message->messageParts->first();
        $path = $this->storage->getAbsolutePath($part->storagePath);

        $this->attachments->remove($part);

        self::assertFileDoesNotExist($path);
        self::assertCount(0, $message->messageParts);
        self::assertFalse((bool) $message->hasAttachments);
    }

    /** Discarding a draft takes its uploads with it; the rows cascade. */
    public function testDiscardingADraftDeletesEveryStoredFile(): void
    {
        $message = $this->draft();

        $this->attachments->attach($message, [
            new UploadedFile($this->tempFile('one'), 'one.txt', null, null, true),
            new UploadedFile($this->tempFile('two'), 'two.txt', null, null, true),
        ]);

        $paths = [];

        foreach ($message->messageParts as $part) {
            $paths[] = $this->storage->getAbsolutePath($part->storagePath);
        }

        $this->attachments->deleteStoredFiles($message);

        foreach ($paths as $path) {
            self::assertFileDoesNotExist($path);
        }
    }

    // ── the flag ──────────────────────────────────────────────────────────

    /**
     * Derived from the parts on every save, never assigned: autosave runs on
     * every keystroke and goes through here, and hardcoding false used to wipe
     * the flag off a draft that had files attached to it.
     */
    public function testTheFlagFollowsTheParts(): void
    {
        $message = new Message();
        $message->hasAttachments = true;

        $this->attachments->syncFlag($message);
        self::assertFalse((bool) $message->hasAttachments, 'a draft with no parts claimed to have files');

        $message->addMessagePart($this->part(isInline: false));
        $this->attachments->syncFlag($message);
        self::assertTrue((bool) $message->hasAttachments);
    }

    /**
     * An inline image is part of the body, not an attachment: a signature logo
     * must not put a paperclip on every mail in the list.
     */
    public function testInlinePartsAreNotAttachments(): void
    {
        $message = new Message();
        $message->addMessagePart($this->part(isInline: true));

        $this->attachments->syncFlag($message);

        self::assertFalse((bool) $message->hasAttachments);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function part(bool $isInline): MessagePart
    {
        $part              = new MessagePart();
        $part->contentType = 'text/plain';
        $part->filename    = 'part.txt';
        $part->isInline    = $isInline;

        return $part;
    }

    private function tempFile(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'draft-attachment-');

        file_put_contents($path, $contents);

        return $path;
    }

    /** Sparse, so "bigger than the ceiling" costs no disk to say. */
    private function sparseFile(int $bytes): string
    {
        $path   = (string) tempnam(sys_get_temp_dir(), 'draft-attachment-big-');
        $handle = fopen($path, 'w');

        ftruncate($handle, $bytes);
        fclose($handle);

        return $path;
    }

    private function latestSequence(): int
    {
        return (int) $this->connection->fetchOne('SELECT COALESCE(MAX(sequence), 0) FROM jmap_change_log');
    }

    /**
     * @return list<string>
     */
    private function emailRowsSince(int $sequence): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            "SELECT entity_id FROM jmap_change_log
             WHERE sequence > ? AND object_type = 'Email'
             ORDER BY sequence",
            [$sequence],
        ));
    }

    private function draft(): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $this->mailbox;
        $message->subject        = 'Attachment fixture';
        $message->fromAddress    = 'composer@example.test';
        $message->hasAttachments = false;
        $message->messageId      = sprintf('<attachment-%s@example.test>', uniqid('', true));

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'attachments-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Draft';
        $user->nameLast  = 'Attachments';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $email = 'attachments-fixture-' . uniqid('', true) . '@example.test';

        $this->account                 = new Account();
        $this->account->usr            = $user;
        $this->account->email          = $email;
        $this->account->username       = $email;
        $this->account->imapHost       = 'localhost';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->smtpHost       = 'localhost';
        $this->account->smtpPort       = 587;
        $this->account->smtpEncryption = 'starttls';
        $this->account->password       = 'x';
        $this->account->authType       = 'password';
        $this->account->isActive       = true;
        $this->em->persist($this->account);

        $this->mailbox                = new Mailbox();
        $this->mailbox->account       = $this->account;
        $this->mailbox->name          = 'INBOX';
        $this->mailbox->fullPath      = 'INBOX';
        $this->mailbox->isSyncEnabled = true;
        $this->mailbox->isIdleEnabled = false;
        $this->em->persist($this->mailbox);

        $this->em->flush();

        $user->addAccount($this->account);
    }
}
