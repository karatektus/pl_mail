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

        $page = $this->client->request('GET', '/settings/reading');

        self::assertResponseIsSuccessful();
        self::assertSame('0', $page->filter('input[name="alwaysShow"]:checked')->attr('value'));
        self::assertCount(1, $page->filter('nav a[href="/settings/reading"][aria-current="page"]'));
    }

    public function testSwitchedOnAndOffAgain(): void
    {
        $this->post(['alwaysShow' => '1']);

        self::assertResponseRedirects('/settings/reading');
        self::assertTrue($this->reread()->alwaysShowImages);

        $page = $this->client->request('GET', '/settings/reading');

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

    /**
     * The allowlist on the card (#807): several entries from one field, the
     * ones that mean nothing named back, and the rest listed with the form
     * each one has.
     */
    public function testSendersAreAddedSeveralAtOnceAndListed(): void
    {
        $this->postTo('/settings/images/senders', ['entries' => "news@heise.de, stripe.com;\ninvoice+*@stripe.com *.bad.test"]);

        self::assertResponseRedirects('/settings/reading#card-images');

        $page = $this->client->followRedirect();

        // Named back in the flash rather than dropped in silence.
        self::assertStringContainsString('*.bad.test', $page->text());
        self::assertSame(
            ['Everyone at stripe.com', 'invoice+*@stripe.com', 'news@heise.de'],
            $page->filter('[data-testid="trusted-image-sender"] span')->each(static fn ($span): string => trim($span->text())),
        );
    }

    public function testOneSenderIsRemovedAndThenAllOfThem(): void
    {
        $this->postTo('/settings/images/senders', ['entries' => 'news@heise.de stripe.com']);

        $page   = $this->client->request('GET', '/settings/reading');
        $remove = $page->filter('[data-testid="trusted-image-sender"] form')->first();

        $this->client->request('POST', (string) $remove->attr('action'), [
            '_token' => (string) $remove->filter('input[name="_token"]')->attr('value'),
        ]);

        self::assertResponseRedirects('/settings/reading#card-images');
        self::assertCount(1, $this->client->request('GET', '/settings/reading')->filter('[data-testid="trusted-image-sender"]'));

        $this->postTo('/settings/images/senders/clear', []);

        self::assertCount(0, $this->client->request('GET', '/settings/reading')->filter('[data-testid="trusted-image-sender"]'));
    }

    /** Adding a sender is the dangerous direction, like the switch: no token, no entry. */
    public function testAddingASenderWithoutATokenIsRefused(): void
    {
        $this->client->request('POST', '/settings/images/senders', ['entries' => 'stripe.com']);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->client->request('GET', '/settings/reading')->filter('[data-testid="trusted-image-sender"]'));
    }

    /** @param array<string, string> $fields */
    private function postTo(string $action, array $fields): void
    {
        $page  = $this->client->request('GET', '/settings/reading');
        $token = (string) $page->filter('form[action="' . $action . '"] input[name="_token"]')->attr('value');

        $this->client->request('POST', $action, $fields + ['_token' => $token]);
    }

    /** @param array<string, string> $fields */
    private function post(array $fields): void
    {
        $page  = $this->client->request('GET', '/settings/reading');
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
