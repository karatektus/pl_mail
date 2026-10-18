<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Service\Mail\InlineAttachmentSigner;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * An inline image answers a browser that holds no session (#213).
 *
 * The mail body renders in a sandbox with no `allow-same-origin`, so the frame
 * sends no cookie with its `<img>` loads; behind ROLE_USER every `cid:` image
 * was a 302 to /login and an empty box. The route is authorized by an HMAC over
 * the part id instead, and serves nothing but raster images.
 */
final class InlineAttachmentAccessTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    /** 1×1 transparent GIF. */
    private const string GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

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

        $this->dir = static::getContainer()->getParameter('kernel.project_dir') . '/var/test-inline-' . bin2hex(random_bytes(4));
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

    public function testAnAnonymousRequestWithAValidSignatureGetsTheImage(): void
    {
        $part = $this->part('image/gif');

        // No loginUser(): this is the sandbox frame's cookieless request.
        $this->client->request('GET', $this->signedUrl($part));

        $response = $this->client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertFalse($response->isRedirection(), 'the frame must not be bounced to the login page');
        self::assertSame('image/gif', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testWithoutASignatureTheImageIsNotThere(): void
    {
        $part = $this->part('image/gif');

        $this->client->request('GET', '/mail/inline/' . $part->id);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/mail/inline/' . $part->id, ['s' => 'deadbeefdeadbeefdeadbeefdeadbeef']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testASignatureForAnotherPartDoesNotOpenThisOne(): void
    {
        $mine  = $this->part('image/gif');
        $other = $this->part('image/gif');

        $this->client->request('GET', '/mail/inline/' . $mine->id, [
            's' => static::getContainer()->get(InlineAttachmentSigner::class)->sign((int) $other->id),
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testEvenASignedRequestNeverServesWhatIsNotARasterImage(): void
    {
        $svg = $this->part('image/svg+xml');
        $pdf = $this->part('application/pdf');

        foreach ([$svg, $pdf] as $part) {
            $this->client->request('GET', $this->signedUrl($part));

            self::assertResponseStatusCodeSame(404, (string) $part->contentType . ' must stay behind the login');
        }
    }

    private function signedUrl(MessagePart $part): string
    {
        return static::getContainer()->get(InlineAttachmentSigner::class)->urlFor((int) $part->id);
    }

    private function part(string $contentType): MessagePart
    {
        /** @var Message $message */
        $message = $this->thread('Mit Bild')->messages->first();

        $name = bin2hex(random_bytes(4));
        file_put_contents($this->dir . '/' . $name, base64_decode(self::GIF, true));

        $part              = new MessagePart();
        $part->contentType = $contentType;
        $part->filename    = $name;
        $part->contentId   = $name . '@test';
        $part->disposition = 'inline';
        $part->isInline    = true;
        $part->size        = 43;
        $part->storagePath = 'var/' . basename($this->dir) . '/' . $name;

        $message->addMessagePart($part);
        $this->em->persist($part);
        $this->em->flush();

        return $part;
    }
}
