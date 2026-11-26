<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\SenderPictureKind;
use App\Domain\Helper\SenderPictureReference;
use App\Entity\Contacts\Contact;
use App\Entity\Mail\SenderPicture;
use App\Entity\User\User;
use App\Service\Mail\SenderPicture\SenderPictureStore;
use App\Tests\Support\Mail\SenderPictureFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The sender picture route serves exactly the file a reference names, to
 * whoever may see it (#639).
 *
 * Pinned: a stored picture comes back cached as immutable, which is only safe
 * because a reference with a stale version is refused; an SVG is served under
 * a policy that runs nothing; and a contact's photo is its owner's alone —
 * the reference is a number, and numbers can be guessed.
 */
final class SenderPictureControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    /** @var list<string> files this test wrote, removed afterwards */
    private array $written = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user = $this->user('viewer');
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        foreach ($this->written as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function testAStoredPictureIsServedAsAnImmutableImage(): void
    {
        $picture = $this->stored(SenderPictureFixtures::png(128, 128), 'png');

        $this->client->request('GET', '/sender-picture/' . SenderPictureReference::for(SenderPictureReference::STORED, (int) $picture->id, (string) $picture->file));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertStringContainsString('immutable', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testAReferenceToAnOlderPictureIsRefusedRatherThanServingTheNewOne(): void
    {
        $picture = $this->stored(SenderPictureFixtures::png(128, 128), 'png');

        $this->client->request('GET', '/sender-picture/' . SenderPictureReference::for(SenderPictureReference::STORED, (int) $picture->id, 'an-older-file.png'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnSvgIsServedUnderAPolicyThatRunsNothing(): void
    {
        $picture = $this->stored(SenderPictureFixtures::svg(), 'svg');

        $this->client->request('GET', '/sender-picture/' . SenderPictureReference::for(SenderPictureReference::STORED, (int) $picture->id, (string) $picture->file));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('sandbox', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
    }

    public function testSomebodyElsesContactPhotoIsNotFound(): void
    {
        $contact            = Contact::create($this->user('owner'), 'Private Person');
        $contact->photoPath = 'contacts/1/private.jpg';
        $this->em->persist($contact);
        $this->em->flush();

        $this->client->request('GET', '/sender-picture/' . SenderPictureReference::for(SenderPictureReference::CONTACT, (int) $contact->id, 'contacts/1/private.jpg'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testNobodySignedOutGetsAPicture(): void
    {
        $picture = $this->stored(SenderPictureFixtures::png(128, 128), 'png');

        $this->client->restart();
        $this->client->request('GET', '/sender-picture/' . SenderPictureReference::for(SenderPictureReference::STORED, (int) $picture->id, (string) $picture->file));

        self::assertResponseRedirects();
    }

    private function stored(string $bytes, string $extension): SenderPicture
    {
        $store = static::getContainer()->get(SenderPictureStore::class);
        $file  = $store->store($bytes, $extension);

        $this->written[] = (string) $store->path($file);

        $picture = new SenderPicture(SenderPictureKind::Website, 'brand-' . bin2hex(random_bytes(4)) . '.example');
        $picture->found($file, 'test');
        $this->em->persist($picture);
        $this->em->flush();

        return $picture;
    }

    private function user(string $name): User
    {
        $user            = new User();
        $user->email     = sprintf('%s-%s@plmail.test', $name, bin2hex(random_bytes(4)));
        $user->nameFirst = ucfirst($name);
        $user->nameLast  = 'Test';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
