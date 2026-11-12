<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Interface\MailSenderInterface;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\User\User;
use App\Repository\Label\Label\LabelRepository;
use App\Repository\Mail\Mailbox\MailboxRepository;
use App\Service\Crypto\AutocryptHeader;
use App\Service\Crypto\SealedDrafts;
use App\Service\Crypto\SealGuard;
use App\Service\Crypto\SendingKey;
use App\Service\Imap\ImapConnector;
use App\Service\Imap\MessageSendService;
use App\Service\Imap\MessageThreader;
use App\Service\Label\LabelResolver;
use App\Service\Label\ThreadLabelSynchronizer;
use App\Service\Mail\AttachmentResolver;
use App\Service\Mail\DraftAttachmentService;
use App\Service\Mail\DraftPersister;
use App\Service\Mail\MailChangeRecorder;
use App\Service\Mail\MailSenderRegistry;
use App\Service\Mail\RawMessageResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * A draft's image sent smaller keeps its original only until the mail goes
 * (#502).
 *
 * The draft row stays on as the Sent message, parts and all, so an original
 * nobody forgot would stay on disk for as long as the mail is kept: every
 * photo twice, GPS position included — the very thing sending it smaller had
 * left out. What was sent is the smaller copy, and that is what stays.
 *
 * And not a moment before the provider has it: a send that fails leaves the
 * draft exactly as it was, original included, so the next attempt — or the
 * person changing their mind about the size — still has it.
 */
final class SendDropsImageOriginalsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private DraftAttachmentService $attachments;
    private AttachmentStorageHelper $storage;

    private Account $account;

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

    public function testASentMessageKeepsTheSmallerCopyAndDropsTheOriginal(): void
    {
        [$draft, $part] = $this->draftWithScaledImage();

        $copy     = $this->storage->getAbsolutePath((string) $part->storagePath);
        $original = $this->storage->getAbsolutePath((string) $part->originalStoragePath);

        self::assertTrue($this->sendService(succeeds: true)->send($draft));

        self::assertNotNull($draft->sentAt);
        self::assertNull($part->originalStoragePath);
        self::assertNull($part->originalSize);
        self::assertFileDoesNotExist($original, 'the original stayed on disk after the mail went');
        self::assertFileExists($copy, 'what was sent is what the Sent message keeps');

        $this->em->refresh($part);
        self::assertNull($part->originalStoragePath, 'the row still points at a deleted file');

        $this->attachments->deleteStoredFiles($draft);
    }

    public function testAFailedSendKeepsTheOriginal(): void
    {
        [$draft, $part] = $this->draftWithScaledImage();

        $original = $this->storage->getAbsolutePath((string) $part->originalStoragePath);

        try {
            $this->sendService(succeeds: false)->send($draft);
            self::fail('the stand-in sender refuses every mail');
        } catch (\Throwable) {
        }

        self::assertNull($draft->sentAt);
        self::assertNotNull($part->originalStoragePath);
        self::assertFileExists($original, 'a send that did not go out took the original with it');

        $this->attachments->deleteStoredFiles($draft);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return array{Message, MessagePart} */
    private function draftWithScaledImage(): array
    {
        $draft                 = new Message();
        $draft->subject        = 'Photos from the lake';
        $draft->bodyHtml       = '<p>Here they are.</p>';
        $draft->hasAttachments = false;
        $draft->toAddresses    = [['name' => null, 'address' => 'mara@example.test']];

        self::getContainer()->get(DraftPersister::class)->save($draft, $this->account);

        self::assertNull($this->attachments->attach($draft, [$this->png('photo.png', 8)]));

        $part = $draft->messageParts->last();
        self::assertInstanceOf(MessagePart::class, $part);

        self::assertNull($this->attachments->replaceWithScaled($part, $this->png('photo.png', 4), false));
        self::assertNotNull($part->originalStoragePath);

        return [$draft, $part];
    }

    /** A real PNG of `$side` × `$side`: the type check reads the bytes. */
    private function png(string $name, int $side): UploadedFile
    {
        $image = imagecreatetruecolor($side, $side);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        $path = (string) tempnam(sys_get_temp_dir(), 'send-originals-');
        file_put_contents($path, (string) ob_get_clean());

        return new UploadedFile($path, $name, null, null, true);
    }

    /** See SentCopyReconcilerTest::sendService() for why the sender is a stand-in. */
    private function sendService(bool $succeeds): MessageSendService
    {
        $container = self::getContainer();

        $sender = new class ($succeeds) implements MailSenderInterface {
            public function __construct(private readonly bool $succeeds)
            {
            }

            public function supports(Account $account): bool
            {
                return true;
            }

            public function send(SymfonyEmail $email, Account $account): bool
            {
                if (false === $this->succeeds) {
                    throw new \RuntimeException('550 refused');
                }

                return true;
            }

            public function filesSentCopy(): bool
            {
                return true;
            }
        };

        return new MessageSendService(
            $container->get(MailboxRepository::class),
            $this->em,
            new MailSenderRegistry([$sender]),
            $container->get(ImapConnector::class),
            $container->get(AttachmentResolver::class),
            $container->get(LabelResolver::class),
            $container->get(LabelRepository::class),
            $container->get(MailChangeRecorder::class),
            $container->get(MessageThreader::class),
            $container->get(ThreadLabelSynchronizer::class),
            $container->get(SealGuard::class),
            $container->get(SealedDrafts::class),
            $container->get(AutocryptHeader::class),
            $container->get(SendingKey::class),
            $container->get(RawMessageResolver::class),
            $this->attachments,
        );
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'originals-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Image';
        $user->nameLast  = 'Originals';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $this->account                 = new Account();
        $this->account->usr            = $user;
        $this->account->email          = 'originals-fixture@example.test';
        $this->account->username       = 'originals-fixture@example.test';
        $this->account->imapHost       = 'localhost';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->smtpHost       = 'localhost';
        $this->account->smtpPort       = 587;
        $this->account->smtpEncryption = 'starttls';
        $this->account->password       = 'x';
        $this->account->authType       = 'password';
        $this->account->mailEnabled    = true;
        $this->em->persist($this->account);

        $sent                = new Mailbox();
        $sent->account       = $this->account;
        $sent->name          = 'Sent';
        $sent->fullPath      = 'Sent';
        $sent->specialUse    = MailboxSpecialUse::SENT;
        $sent->isSyncEnabled = true;
        $sent->isIdleEnabled = false;
        $this->em->persist($sent);

        $this->em->flush();

        $user->addAccount($this->account);

        $labels = self::getContainer()->get(LabelResolver::class);
        $labels->bindMailbox($labels->systemLabel(LabelRole::Sent, $this->account), $sent);

        $this->em->flush();
    }
}
