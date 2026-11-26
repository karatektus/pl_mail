<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Mail\ComposeAction;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The compose window's buttons in an order of one's own: what Done stores, what
 * is refused, and that the window is drawn from it.
 *
 * The drawing half is here and not only in the browser spec because the order
 * is the server's: each button's CSS `order` is rendered, and a window that
 * reordered itself in the browser after loading would pass a browser test
 * while moving every button under the pointer on every open.
 */
final class ComposeActionOrderTest extends WebTestCase
{
    private const string PATH = '/settings/compose/action-order';

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        $this->user            = new User();
        $this->user->email     = 'action-order-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Action';
        $this->user->nameLast  = 'Order';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';
        $this->em->persist($this->user);

        // Somebody with no mail account has no compose window to draw.
        $account                 = new Account();
        $account->usr            = $this->user;
        $account->name           = 'Action order';
        $account->email          = uniqid('action-order-', true) . '@example.test';
        $account->username       = uniqid('action-order-', true);
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->mailEnabled    = true;
        $this->em->persist($account);
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

    public function testWithoutATokenNothingIsStored(): void
    {
        $this->client->request('POST', self::PATH, server: ['CONTENT_TYPE' => 'application/json'], content: '{"order":["discard"]}');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->reread()->getSetting(User::SETTING_COMPOSE_ACTION_ORDER));
    }

    /** What was posted, in its order, then what it left out in the default order. */
    public function testAnOrderIsStoredWholeAndTheWindowIsDrawnFromIt(): void
    {
        $this->post(['order' => ['discard', 'attach']]);

        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        $stored = $this->reread()->getSetting(User::SETTING_COMPOSE_ACTION_ORDER);

        self::assertSame(['discard', 'attach', 'format', 'link'], array_slice($stored, 0, 4));
        self::assertCount(count(ComposeAction::cases()), $stored);

        $crawler = $this->client->request('GET', '/compose/new', server: ['HTTP_TURBO_FRAME' => 'compose_dock']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('order: 1', (string) $crawler->filter('[data-compose-action="discard"]')->attr('style'));
        self::assertStringContainsString('order: 2', (string) $crawler->filter('[data-compose-action="attach"]')->attr('style'));
        self::assertStringContainsString('order: 3', (string) $crawler->filter('[data-compose-action="format"]')->attr('style'));
        // ⋮ stays last, whatever the order.
        self::assertStringContainsString(
            'order: ' . (count(ComposeAction::cases()) + 1),
            (string) $crawler->filter('[data-compose--action-overflow-target="more"]')->attr('style'),
        );
    }

    /** Reset, and an order arranged back to the default, both forget the choice. */
    public function testTheDefaultIsNotStored(): void
    {
        $this->post(['order' => ['discard']]);
        self::assertNotNull($this->reread()->getSetting(User::SETTING_COMPOSE_ACTION_ORDER));

        $this->post(['order' => null]);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        self::assertArrayNotHasKey(User::SETTING_COMPOSE_ACTION_ORDER, $this->settings());

        $this->post(['order' => ['discard']]);
        $this->post(['order' => array_map(static fn (ComposeAction $action): string => $action->value, ComposeAction::cases())]);
        self::assertArrayNotHasKey(User::SETTING_COMPOSE_ACTION_ORDER, $this->settings());
    }

    /** @return iterable<string, array{mixed}> */
    public static function refused(): iterable
    {
        yield 'a button the window does not have' => [['attach', 'teleport']];
        yield 'one button twice' => [['attach', 'attach']];
        yield 'not a list' => [['first' => 'attach']];
        yield 'not even an array' => ['attach'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testAnOrderItCannotDrawIsRefusedWhole(mixed $order): void
    {
        $this->post(['order' => $order]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->reread()->getSetting(User::SETTING_COMPOSE_ACTION_ORDER));
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload): void
    {
        $page  = $this->client->request('GET', '/mail/inbox');
        $token = (string) $page->filter('meta[name="csrf-token"]')->attr('content');

        $this->client->request(
            'POST',
            self::PATH,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function reread(): User
    {
        $this->em->clear();

        $user = $this->em->find(User::class, $this->user->id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return (array) (new \ReflectionProperty(User::class, 'settings'))->getValue($this->reread());
    }
}
