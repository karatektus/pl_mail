<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\User\User;
use App\Service\Mail\AttachmentThumbnailer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Previews are only worth having if they stay cheap, so the gate matters more
 * than the picture: the interesting cases are the ones that must *not* decode.
 *
 * A pixel ceiling is the one that is easy to get wrong. File size does not
 * bound decoded size — a few KB of PNG can expand to gigabytes of bitmap — so
 * the check has to read the header and refuse before allocating anything.
 */
final class AttachmentThumbnailerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private AttachmentThumbnailer $thumbnailer;
    private string $projectDir;
    private Message $message;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->thumbnailer = $container->get(AttachmentThumbnailer::class);
        $this->projectDir = $container->getParameter('kernel.project_dir');

        $this->connection->beginTransaction();
        $this->seed();
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnImageGetsAThumbnailBoundedByTheLongEdge(): void
    {
        if (false === function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('the image needs the gd extension for previews');
        }

        $part = $this->partFor($this->writePng('wide.png', 600, 300), 'image/png');

        $path = $this->thumbnailer->thumbnailPath($part);

        self::assertNotNull($path, 'a plain PNG should preview');
        $this->written[] = $path;

        $info = getimagesize($path);
        self::assertNotFalse($info);
        self::assertSame(IMAGETYPE_WEBP, $info[2]);
        self::assertSame(160, $info[0], 'the long edge is the one that gets clamped');
        self::assertSame(80, $info[1], 'aspect ratio is preserved');
    }

    public function testTheSecondCallReusesTheCachedFile(): void
    {
        if (false === function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('the image needs the gd extension for previews');
        }

        $part = $this->partFor($this->writePng('cached.png', 200, 200), 'image/png');

        $first = $this->thumbnailer->thumbnailPath($part);
        self::assertNotNull($first);
        $this->written[] = $first;

        $stamp = filemtime($first);
        clearstatcache();

        self::assertSame($first, $this->thumbnailer->thumbnailPath($part));
        self::assertSame($stamp, filemtime($first), 'a cached thumbnail should not be rewritten');
    }

    public function testANonImageIsNotPreviewable(): void
    {
        $part = $this->partFor($this->writePng('note.png', 10, 10), 'text/plain');

        self::assertFalse($this->thumbnailer->isPreviewable($part));
        self::assertNull($this->thumbnailer->thumbnailPath($part));
    }

    public function testAnSvgIsNotPreviewable(): void
    {
        // Not a GD format, and rendering a stranger's XML is not a preview.
        $part = $this->partFor($this->writePng('vector.png', 10, 10), 'image/svg+xml');

        self::assertFalse($this->thumbnailer->isPreviewable($part));
    }

    public function testAnOversizeAttachmentIsNotPreviewable(): void
    {
        $part = $this->partFor($this->writePng('huge.png', 10, 10), 'image/png');
        $part->size = 64 * 1024 * 1024;

        self::assertFalse($this->thumbnailer->isPreviewable($part));
    }

    public function testAFileThatIsNotReallyAnImageYieldsNoThumbnail(): void
    {
        // The content type is attacker-controlled; the bytes decide.
        $path = $this->projectDir . '/var/attachments/test-thumbs/liar.png';
        $this->ensureDirectory(dirname($path));
        file_put_contents($path, 'this is not a png');
        $this->written[] = $path;

        $part = $this->partFor('var/attachments/test-thumbs/liar.png', 'image/png');

        self::assertTrue($this->thumbnailer->isPreviewable($part), 'the gate trusts the header only later');
        self::assertNull($this->thumbnailer->thumbnailPath($part));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function seed(): void
    {
        $user = new User();
        $user->email = 'thumbs-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Thumb';
        $user->nameLast = 'Nailer';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account
            ->setUsr($user)
            ->setEmail('Thumb Nailer')
            ->setUsername('thumbs@example.test')
            ->setImapHost('localhost')
            ->setImapPort(993)
            ->setImapEncryption('ssl')
            ->setSmtpHost('localhost')
            ->setSmtpPort(587)
            ->setSmtpEncryption('starttls')
            ->setPassword('x')
            ->setAuthType('password')
            ->setIsActive(true);
        $this->em->persist($account);

        $this->message = new Message();
        $this->message->account = $account;
        $this->message->subject = 'Thumbnail fixture';
        $this->message->fromAddress = 'sender@example.test';
        $this->message->fromName = 'Sender';
        $this->message->bodyText = 'body';
        $this->message->receivedAt = new \DateTimeImmutable('2026-07-01 12:00:00');
        $this->message->hasAttachments = true;
        $this->em->persist($this->message);

        $this->em->flush();
    }

    private function partFor(string $relativePath, string $contentType): MessagePart
    {
        $part = new MessagePart();
        $part->message     = $this->message;
        $part->contentType = $contentType;
        $part->filename    = basename($relativePath);
        $part->disposition = 'attachment';
        $part->storagePath = $relativePath;
        $part->isInline    = false;

        $this->em->persist($part);
        $this->em->flush();

        return $part;
    }

    /** @return string the path relative to the project root */
    private function writePng(string $name, int $width, int $height): string
    {
        $relative = 'var/attachments/test-thumbs/' . $name;
        $absolute = $this->projectDir . '/' . $relative;

        $this->ensureDirectory(dirname($absolute));

        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 80, 160));
        imagepng($image, $absolute);
        imagedestroy($image);

        $this->written[] = $absolute;

        return $relative;
    }

    private function ensureDirectory(string $path): void
    {
        if (false === is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}
