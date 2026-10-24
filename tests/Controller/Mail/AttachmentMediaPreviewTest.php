<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pictures and videos open in a viewer, as PDFs do (#295).
 *
 * The viewer's own behaviour is the browser's and is media-preview.spec's to
 * check. What is worth pinning here is the server side: which parts get a
 * viewer at all, and that the one new way of serving bytes — the video route —
 * labels them from a fixed list, keeps the sandbox on, and answers ranges.
 */
final class AttachmentMediaPreviewTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private string $dir;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->client->loginUser($this->user);

        $this->dir = static::getContainer()->getParameter('kernel.project_dir') . '/var/test-media-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);

        parent::tearDown();
    }

    public function testAPictureOpensOnTheOrdinaryAttachmentUrl(): void
    {
        $part    = $this->part('image/png', 'photo.png');
        $crawler = $this->client->request('GET', '/mail/attachment/' . $part->id . '/preview');

        self::assertResponseIsSuccessful();
        self::assertSame('/mail/attachment/' . $part->id, $crawler->filter('#modal img')->attr('src'));
    }

    public function testAVideoOpensOnTheVideoRoute(): void
    {
        $part    = $this->part('video/mp4', 'clip.mp4');
        $crawler = $this->client->request('GET', '/mail/attachment/' . $part->id . '/preview');

        self::assertResponseIsSuccessful();
        self::assertSame('/mail/attachment/' . $part->id . '/video', $crawler->filter('#modal video')->attr('src'));
    }

    public function testAnythingElseHasNoViewer(): void
    {
        foreach ([['text/plain', 'notes.txt'], ['image/svg+xml', 'logo.svg']] as [$type, $name]) {
            $this->client->request('GET', '/mail/attachment/' . $this->part($type, $name)->id . '/preview');

            self::assertResponseStatusCodeSame(404, $type);
        }
    }

    public function testTheVideoRouteServesAFixedTypeInsideTheSandbox(): void
    {
        $part = $this->part('application/octet-stream', 'clip.webm');

        $this->client->request('GET', '/mail/attachment/' . $part->id . '/video');
        $headers = $this->client->getResponse()->headers;

        self::assertResponseIsSuccessful();
        self::assertSame('video/webm', $headers->get('Content-Type'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; sandbox", $headers->get('Content-Security-Policy'));
        self::assertStringStartsWith('inline', (string) $headers->get('Content-Disposition'));
    }

    /** Seeking is a Range request; without a 206 the player has to fetch it all. */
    public function testTheVideoRouteAnswersARange(): void
    {
        $part = $this->part('video/mp4', 'clip.mp4', '0123456789');

        $this->client->request('GET', '/mail/attachment/' . $part->id . '/video', server: ['HTTP_RANGE' => 'bytes=2-5']);

        self::assertResponseStatusCodeSame(206);
        self::assertSame('bytes 2-5/10', $this->client->getResponse()->headers->get('Content-Range'));
    }

    /** The video route is not a back door to serving anything else inline. */
    public function testTheVideoRouteServesNothingButVideo(): void
    {
        foreach ([['text/html', 'clip.mp4'], ['image/png', 'photo.png']] as [$type, $name]) {
            $this->client->request('GET', '/mail/attachment/' . $this->part($type, $name)->id . '/video');

            self::assertResponseStatusCodeSame(404, $type);
        }
    }

    private function part(string $contentType, string $filename, string $bytes = 'bytes'): MessagePart
    {
        /** @var Message $message */
        $message = $this->thread('Mit Anhang')->messages->first();

        $name = bin2hex(random_bytes(4));
        file_put_contents($this->dir . '/' . $name, $bytes);

        $part              = new MessagePart();
        $part->contentType = $contentType;
        $part->filename    = $filename;
        $part->disposition = 'attachment';
        $part->isInline    = false;
        $part->size        = strlen($bytes);
        $part->storagePath = 'var/' . basename($this->dir) . '/' . $name;

        $message->addMessagePart($part);
        $this->em->persist($part);
        $this->em->flush();

        return $part;
    }
}
