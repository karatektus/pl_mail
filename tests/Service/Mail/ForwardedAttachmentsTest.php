<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\User\User;
use App\Service\Mail\DraftAttachmentService;
use App\Service\Mail\ForwardedAttachments;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a forward carries along: the original's real attachments, as files the
 * draft owns, and nothing else.
 *
 * A forward that drops the invoice it was sent to pass on is the bug this
 * exists for, so the first test is the plain case. The rest pin what must not
 * come along: pictures that are part of the body, and files the compose window
 * would itself have refused.
 */
final class ForwardedAttachmentsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private ForwardedAttachments $forwarded;
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
        $this->forwarded   = $container->get(ForwardedAttachments::class);
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

    public function testARealAttachmentIsCopiedOntoTheDraft(): void
    {
        $original = $this->received();
        $this->receive($original, 'invoice.pdf', 'application/pdf', '%PDF-1.7 the invoice');
        $draft = $this->message();

        self::assertTrue($this->forwarded->has($original));
        self::assertSame(1, $this->forwarded->copy($original, $draft));

        self::assertCount(1, $draft->messageParts);

        $copy = $draft->messageParts->first();

        self::assertSame('invoice.pdf', $copy->filename);
        self::assertSame('application/pdf', $copy->contentType);
        self::assertSame('attachment', $copy->disposition);
        self::assertSame('%PDF-1.7 the invoice', file_get_contents($this->storage->getAbsolutePath($copy->storagePath)));
        self::assertTrue((bool) $draft->hasAttachments, 'the draft still says it has no files');
    }

    /**
     * Copied, not shared: dropping the file from the forward deletes the
     * draft's bytes, and the mail it was forwarded from must keep its own.
     */
    public function testTheCopyDoesNotShareStorageWithTheOriginal(): void
    {
        $original = $this->received();
        $part     = $this->receive($original, 'invoice.pdf', 'application/pdf', 'the invoice');
        $draft    = $this->message();

        $this->forwarded->copy($original, $draft);
        $this->attachments->remove($draft->messageParts->first());

        self::assertSame('the invoice', file_get_contents($this->storage->getAbsolutePath($part->storagePath)));
        self::assertCount(1, $original->messageParts);
        self::assertFalse((bool) $draft->hasAttachments);
    }

    /** A picture in the body is already in the quote; a second copy would show twice. */
    public function testAnInlineImageIsNotAttached(): void
    {
        $original = $this->received();
        $inline   = $this->receive($original, 'logo.png', 'image/png', 'png bytes');
        $inline->isInline    = true;
        $inline->disposition = 'inline';
        $this->em->flush();

        $draft = $this->message();

        self::assertFalse($this->forwarded->has($original));
        self::assertSame(0, $this->forwarded->copy($original, $draft));
        self::assertCount(0, $draft->messageParts);
    }

    public function testEveryAttachmentComesAlongNotJustTheFirst(): void
    {
        $original = $this->received();
        $this->receive($original, 'a.txt', 'text/plain', 'first');
        $this->receive($original, 'b.txt', 'text/plain', 'second');
        $draft = $this->message();

        self::assertSame(2, $this->forwarded->copy($original, $draft));
        self::assertCount(2, $draft->messageParts);
    }

    /**
     * Over the ceiling an upload gets: skipped, and the others still arrive,
     * because a forward that will not open over one file is worse than one
     * that opens without it.
     */
    public function testAFileOverTheComposeLimitIsSkippedAndTheRestStillCopied(): void
    {
        $original = $this->received();
        $big      = $this->receive($original, 'big.bin', 'application/octet-stream', 'small on disk');
        $big->size = DraftAttachmentService::MAX_BYTES + 1;
        $this->receive($original, 'ok.txt', 'text/plain', 'fine');
        $this->em->flush();

        $draft = $this->message();

        self::assertSame(1, $this->forwarded->copy($original, $draft));
        self::assertSame('ok.txt', $draft->messageParts->first()->filename);
    }

    /** The signature signs the original; on the forward it is a stray file. */
    public function testASignatureIsNotAttachedButTheSignedMailsFilesAre(): void
    {
        $original         = $this->received();
        $original->crypto = MessageCrypto::PgpSigned;
        $this->receive($original, 'invoice.pdf', 'application/pdf', 'the invoice');
        $this->receive($original, 'signature.asc', 'application/pgp-signature', 'armored signature');
        $this->receive($original, 'smime.p7s', 'application/pkcs7-signature; name=smime.p7s', 'der signature');

        $draft = $this->message();

        self::assertSame(1, $this->forwarded->copy($original, $draft));
        self::assertSame('invoice.pdf', $draft->messageParts->first()->filename);
    }

    /** An encrypted mail's parts are ciphertext for this mailbox's key alone. */
    public function testAnEncryptedMailHasNothingToCopy(): void
    {
        $original         = $this->received();
        $original->crypto = MessageCrypto::PgpEncrypted;
        $this->receive($original, 'encrypted.asc', 'application/octet-stream', 'ciphertext');

        self::assertFalse($this->forwarded->has($original));
        self::assertSame(0, $this->forwarded->copy($original, $this->message()));
    }

    public function testAMailWithoutFilesHasNothingToCopy(): void
    {
        self::assertFalse($this->forwarded->has($this->received()));
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function received(): Message
    {
        return $this->message('Verkoopfactuur');
    }

    private function message(string $subject = 'Fwd: Verkoopfactuur'): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $this->mailbox;
        $message->subject        = $subject;
        $message->fromAddress    = 'sender@example.test';
        $message->hasAttachments = false;
        $message->messageId      = sprintf('<forwarded-%s@example.test>', uniqid('', true));

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /** A part as the importer leaves it: bytes stored, row attached. */
    private function receive(Message $message, string $filename, string $contentType, string $contents): MessagePart
    {
        $part              = new MessagePart();
        $part->message     = $message;
        $part->contentType = $contentType;
        $part->filename    = $filename;
        $part->disposition = 'attachment';
        $part->size        = strlen($contents);
        $part->isInline    = false;
        $part->storagePath = $this->storage->store(
            (int) $this->account->id,
            (int) $this->mailbox->id,
            (int) $message->id,
            $filename,
            $contents,
        );

        $message->addMessagePart($part);
        $message->hasAttachments = true;
        $this->em->persist($part);
        $this->em->flush();

        return $part;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'forwarded-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Forward';
        $user->nameLast  = 'Attachments';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $email = 'forwarded-fixture-' . uniqid('', true) . '@example.test';

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
