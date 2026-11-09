<?php

declare(strict_types=1);

namespace App\Tests\Controller\Integration;

use App\Domain\DTO\Integration\Entry;
use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Interface\IntegrationDriverInterface;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Service\Integration\IntegrationDriverRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Attach inline" in the compose picker: a picture from a service, handed to
 * the browser to place in the body.
 *
 * The bytes come from somebody else's server with a type that server chose,
 * so what is pinned here is that they only ever leave as an untyped download,
 * that only pictures leave at all, and that the connection is the user's own.
 */
final class InlineImageFromServiceTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAPictureComesBackAsUntypedBytesWithItsTypeBeside(): void
    {
        $client = $this->signIn();
        $integration = $this->seedIntegration();

        $client->request('GET', '/integrations/' . $integration->id . '/inline-image?file=p1');

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();

        self::assertSame('PNGBYTES', $response->getContent());
        self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('image/png', $response->headers->get('X-File-Type'));
        self::assertSame('holiday%20photo.png', $response->headers->get('X-File-Name'));
    }

    public function testSomethingThatIsNotAPictureIsRefused(): void
    {
        $client = $this->signIn();
        $integration = $this->seedIntegration();

        $client->request('GET', '/integrations/' . $integration->id . '/inline-image?file=d1');

        self::assertResponseStatusCodeSame(415);
        self::assertSame('', $client->getResponse()->getContent());
    }

    public function testAnotherUsersConnectionIsRefused(): void
    {
        $client = $this->signIn();
        $integration = $this->seedIntegration();

        $client->loginUser($this->seedUser('stranger'));
        $client->request('GET', '/integrations/' . $integration->id . '/inline-image?file=p1');

        self::assertResponseStatusCodeSame(403);
    }

    /** The button is offered when attaching, and not when choosing a profile picture. */
    public function testThePickerOffersItWhenAttachingOnly(): void
    {
        $client = $this->signIn();
        $integration = $this->seedIntegration();

        $crawler = $client->request('GET', '/integrations/' . $integration->id . '/browse?draft=7');
        self::assertCount(1, $crawler->filter('[data-action*="integration-picker#attachInline"]'));

        $crawler = $client->request('GET', '/integrations/' . $integration->id . '/browse?mode=avatar');
        self::assertCount(0, $crawler->filter('[data-action*="integration-picker#attachInline"]'));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function seedIntegration(): Integration
    {
        $integration = new Integration($this->user, Provider::Nextcloud, 'Cloud');
        $integration->baseUrl = 'https://cloud.example.test';

        $this->em->persist($integration);
        $this->em->flush();

        return $integration;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $container->set(IntegrationDriverRegistry::class, new IntegrationDriverRegistry([new InlineImageFakeDriver()]));

        $this->connection->beginTransaction();

        $this->user = $this->seedUser('owner');
        $client->loginUser($this->user);

        return $client;
    }

    private function seedUser(string $tag): User
    {
        $user = new User();
        $user->email = 'inline-image-' . $tag . '-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Inline';
        $user->nameLast = 'Image';
        $user->roles = ['ROLE_USER'];
        $user->password = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}

/** A file store with one picture and one PDF in it. */
final class InlineImageFakeDriver implements IntegrationDriverInterface
{
    public function supports(Provider $provider): bool
    {
        return true;
    }

    public function verify(Integration $integration): void
    {
    }

    public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        return new Listing(
            [
                new Entry('p1', 'holiday photo.png', false, 8, 'image/png'),
                new Entry('d1', 'scan.pdf', false, 8, 'application/pdf'),
            ],
            [Entry::folder('', 'Files')],
        );
    }

    public function download(Integration $integration, string $fileId): RemoteFile
    {
        return 'p1' === $fileId
            ? new RemoteFile('holiday photo.png', 'image/png', 'PNGBYTES')
            : new RemoteFile('scan.pdf', 'application/pdf', '%PDF-1.7');
    }

    public function upload(Integration $integration, string $absolutePath, string $filename, string $mime, ?string $folderId = null): string
    {
        throw new \LogicException('Nothing is uploaded here.');
    }

    public function shareLink(Integration $integration, string $fileId): string
    {
        return 'https://share.example.test/' . $fileId;
    }

    public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
    {
        return null;
    }
}
