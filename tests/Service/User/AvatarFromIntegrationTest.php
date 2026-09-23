<?php

declare(strict_types=1);

namespace App\Tests\Service\User;

use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\AvatarRefusedException;
use App\Domain\Exception\IntegrationException;
use App\Domain\Helper\AvatarStorage;
use App\Domain\Interface\IntegrationDriverInterface;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationRepository;
use App\Service\Integration\IntegrationDriverRegistry;
use App\Service\User\AvatarFromIntegration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * A picture picked from a connected service is judged by its bytes.
 *
 * Production answered a picked photo with a 500: the service labelled the
 * download application/octet-stream, the label was trusted, and the refusal
 * was a RuntimeException nothing caught. Both halves are pinned — a real image
 * under a wrong label is kept, and a non-image under an image label is refused
 * with a reason the form can show, as is a download that never arrives.
 */
final class AvatarFromIntegrationTest extends TestCase
{
    /** The smallest PNG there is: one transparent pixel. */
    private const string PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89"
        . "\0\0\0\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/avatar-test-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            $entry->isDir() ? rmdir((string) $entry) : unlink((string) $entry);
        }
        rmdir($this->root);
    }

    public function testARealImageUnderAWrongLabelIsKeptAsWhatItIs(): void
    {
        $user = new User();

        $this->picker(new RemoteFile('photo', 'application/octet-stream', self::PNG))
            ->apply($user, $this->integration(), 'f1');

        self::assertNotNull($user->avatar);
        self::assertStringEndsWith('.png', $user->avatar);
    }

    public function testSomethingElseUnderAnImageLabelIsRefusedWithAReason(): void
    {
        $user = new User();

        try {
            $this->picker(new RemoteFile('notes.jpg', 'image/jpeg', "just some text\n"))
                ->apply($user, $this->integration(), 'f1');
            self::fail('a text file became an avatar');
        } catch (AvatarRefusedException $refused) {
            self::assertSame(AvatarRefusedException::NOT_AN_IMAGE, $refused->reason);
        }

        self::assertNull($user->avatar);
    }

    public function testADownloadThatFailsIsRefusedRatherThanThrown(): void
    {
        $this->expectExceptionObject(new AvatarRefusedException(AvatarRefusedException::UNAVAILABLE));

        $this->picker(null)->apply(new User(), $this->integration(), 'f1');
    }

    private function picker(?RemoteFile $file): AvatarFromIntegration
    {
        $driver = new class ($file) implements IntegrationDriverInterface {
            public function __construct(private readonly ?RemoteFile $file)
            {
            }

            public function supports(Provider $provider): bool
            {
                return true;
            }

            public function verify(Integration $integration): void
            {
            }

            public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
            {
                return new Listing([]);
            }

            public function download(Integration $integration, string $fileId): RemoteFile
            {
                return $this->file ?? throw new IntegrationException('the service is down');
            }

            public function upload(Integration $integration, string $absolutePath, string $filename, string $mime, ?string $folderId = null): string
            {
                return 'unused';
            }

            public function shareLink(Integration $integration, string $fileId): ?string
            {
                return null;
            }

            public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
            {
                return null;
            }
        };

        return new AvatarFromIntegration(
            $this->createStub(IntegrationRepository::class),
            new IntegrationDriverRegistry([$driver]),
            new AvatarStorage($this->root, 'var'),
            $this->createStub(EntityManagerInterface::class),
        );
    }

    private function integration(): Integration
    {
        return new Integration(new User(), Provider::Nextcloud, 'Nextcloud');
    }
}
