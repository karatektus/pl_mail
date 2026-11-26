<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Mail\SenderPictureKind;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\QueueSenderPicturesMessage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Settings → Reading → Sender pictures, and Admin → Sender pictures (#639).
 *
 * Pinned: what a reader who never chose gets (Gravatar off, the rest on);
 * that switching a source on looks up the inbox already there, since without
 * it nobody sees a picture for weeks; that a post without a token cannot
 * switch Gravatar on, which would send a hash of every correspondent to a
 * third party; and that only an administrator moves the server-wide switch.
 */
final class SenderPictureSettingsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user            = new User();
        $this->user->email     = sprintf('pictures-%s@plmail.test', bin2hex(random_bytes(4)));
        $this->user->nameFirst = 'Picture';
        $this->user->nameLast  = 'Pref';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';
        $this->em->persist($this->user);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAReaderWhoNeverChoseHasGravatarOffAndTheRestOn(): void
    {
        $page = $this->client->request('GET', '/settings/reading');

        self::assertResponseIsSuccessful();
        self::assertSame('1', $page->filter('input[name="bimi"]:checked')->attr('value'));
        self::assertSame('0', $page->filter('input[name="hashed"]:checked')->attr('value'));
        self::assertSame('1', $page->filter('input[name="website"]:checked')->attr('value'));
    }

    public function testSwitchingASourceOnLooksUpTheInboxAlreadyThere(): void
    {
        $this->post(['hashed' => '1']);

        self::assertResponseRedirects('/settings/reading');
        self::assertTrue($this->reread()->allowsSenderPicture(SenderPictureKind::Hashed));
        self::assertCount(1, $this->queued());
    }

    public function testSwitchingBackToTheDefaultStoresNothing(): void
    {
        $this->post(['hashed' => '1']);
        $this->post(['hashed' => '0', 'website' => '0']);

        $user = $this->reread();

        self::assertFalse($user->allowsSenderPicture(SenderPictureKind::Hashed));
        self::assertFalse($user->allowsSenderPicture(SenderPictureKind::Website));
        self::assertSame(['website' => false], $user->getSetting(User::SETTING_SENDER_PICTURES), 'only what differs from the default is kept');
    }

    public function testAPostWithoutATokenIsRefused(): void
    {
        $this->client->request('POST', '/settings/sender-pictures', ['hashed' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reread()->allowsSenderPicture(SenderPictureKind::Hashed));
    }

    public function testOnlyAnAdministratorReachesTheServerSwitch(): void
    {
        $this->client->request('GET', '/admin/sender-pictures');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAdministratorSwitchingLookupsOnLooksUpEveryUsersInbox(): void
    {
        $this->user->roles = ['ROLE_USER', 'ROLE_ADMIN'];
        $this->em->flush();
        $this->client->loginUser($this->user);

        $page  = $this->client->request('GET', '/admin/sender-pictures');
        $token = (string) $page->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/sender-pictures', ['lookupsEnabled' => '1', '_token' => $token]);

        self::assertResponseIsSuccessful();
        self::assertNotEmpty($this->queued());
    }

    /** @param array<string, string> $fields */
    private function post(array $fields): void
    {
        $page  = $this->client->request('GET', '/settings/reading');
        $token = (string) $page->filter('form[action="/settings/sender-pictures"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/sender-pictures', $fields + ['_token' => $token]);
    }

    /** @return list<object> */
    private function queued(): array
    {
        $transport = static::getContainer()->get('messenger.transport.maintenance');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof QueueSenderPicturesMessage,
        ));
    }

    private function reread(): User
    {
        $this->em->clear();

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $this->user->email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
