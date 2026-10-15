<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Settings → Mail → Images records "always show remote images" (#124).
 *
 * What the switch does to a message is AlwaysShowImagesTest's. Here: that the
 * page shows where it stands, that off is the absent key, and that a post
 * without a token cannot turn it on — the one direction that matters, since
 * on is every later message telling its sender it was opened.
 */
final class ImageSettingsTest extends WebTestCase
{
    private const string EMAIL = 'images-pref@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = self::EMAIL;
        $user->nameFirst = 'Image';
        $user->nameLast  = 'Pref';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItIsOffForSomebodyWhoHasNeverBeenAsked(): void
    {
        self::assertFalse($this->reread()->alwaysShowImages);

        $page = $this->client->request('GET', '/settings?section=images');

        self::assertResponseIsSuccessful();
        self::assertSame('0', $page->filter('input[name="alwaysShow"]:checked')->attr('value'));
        self::assertCount(1, $page->filter('nav a[href="/settings?section=images"][aria-current="page"]'));
    }

    public function testSwitchedOnAndOffAgain(): void
    {
        $this->post(['alwaysShow' => '1']);

        self::assertResponseRedirects('/settings?section=images');
        self::assertTrue($this->reread()->alwaysShowImages);

        $page = $this->client->request('GET', '/settings?section=images');

        self::assertSame('1', $page->filter('input[name="alwaysShow"]:checked')->attr('value'));

        // And off again is back to the absent key.
        $this->post(['alwaysShow' => '0']);

        self::assertFalse($this->reread()->alwaysShowImages);
        self::assertNull($this->reread()->getSetting(User::SETTING_IMAGES_ALWAYS_SHOW));
    }

    /** A post that does not mention the switch does not move it. */
    public function testAPostWithoutTheFieldChangesNothing(): void
    {
        $this->post(['alwaysShow' => '1']);
        $this->post([]);

        self::assertTrue($this->reread()->alwaysShowImages);
    }

    public function testAPostWithoutATokenIsRefused(): void
    {
        $this->client->request('POST', '/settings/images', ['alwaysShow' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reread()->alwaysShowImages);
    }

    /** @param array<string, string> $fields */
    private function post(array $fields): void
    {
        $page  = $this->client->request('GET', '/settings?section=images');
        $token = (string) $page->filter('form[action="/settings/images"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/images', $fields + ['_token' => $token]);
    }

    private function reread(): User
    {
        $this->em->clear();

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
