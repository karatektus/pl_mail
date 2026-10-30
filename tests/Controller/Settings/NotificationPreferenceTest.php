<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\User\User;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Settings → Notifications → "Notify for" and "Only notify for unread mail" (#369),
 * and the answer the service worker reads the second back through.
 *
 * What the worker does with the answer is JavaScript and is not tested here.
 * What is worth pinning is the server's half: the switch is off for somebody
 * who never chose, off is the absent key, a post without a token cannot move
 * it, and the endpoint reports the setting and the Inbox's unread count — the
 * Inbox's, so unread mail elsewhere does not keep a notification alive.
 */
final class NotificationPreferenceTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->client->loginUser($this->user);
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
        self::assertFalse($this->user->pushSkipsRead);

        $page = $this->client->request('GET', '/settings?section=notifications');

        self::assertResponseIsSuccessful();
        self::assertSame('0', $page->filter('input[name="skipRead"]:checked')->attr('value'));
    }

    public function testSwitchedOnAndOffAgain(): void
    {
        $this->post(['skipRead' => '1']);

        self::assertResponseRedirects('/settings?section=notifications');
        self::assertTrue($this->reread()->pushSkipsRead);

        $this->post(['skipRead' => '0']);

        // Off is the absent key, not a stored false.
        self::assertFalse($this->reread()->pushSkipsRead);
        self::assertNull($this->reread()->getSetting(User::SETTING_PUSH_SKIP_READ));
    }

    /** A post that does not mention the switch does not move it. */
    public function testAPostWithoutTheFieldChangesNothing(): void
    {
        $this->post(['skipRead' => '1']);
        $this->post([]);

        self::assertTrue($this->reread()->pushSkipsRead);
    }

    public function testAPostWithoutATokenIsRefused(): void
    {
        $this->client->request('POST', '/settings/notifications', ['skipRead' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reread()->pushSkipsRead);
    }

    public function testEveryTabNotifiesUntilSomeAreChosen(): void
    {
        self::assertFalse($this->user->pushFiltersCategories);
        self::assertCount(5, $this->user->pushCategories);

        $page = $this->client->request('GET', '/settings?section=notifications');

        self::assertCount(5, $page->filter('input[name="categories[]"]:checked'));

        $this->post(['categoriesSent' => '1', 'categories' => ['primary', 'social']]);

        self::assertSame([MessageCategory::Primary, MessageCategory::Social], $this->reread()->pushCategories);
        self::assertTrue($this->reread()->pushNotifiesFor(MessageCategory::Social));
        self::assertFalse($this->reread()->pushNotifiesFor(MessageCategory::Promotions));

        // A post that does not mention the boxes leaves them alone.
        $this->post(['skipRead' => '1']);
        self::assertTrue($this->reread()->pushFiltersCategories);

        // Unchecking every box changes nothing: no tab at all is notifications off.
        $this->post(['categoriesSent' => '1']);
        self::assertSame([MessageCategory::Primary, MessageCategory::Social], $this->reread()->pushCategories);

        // Everything again is the absent key.
        $this->post(['categoriesSent' => '1', 'categories' => ['primary', 'social', 'promotions', 'updates', 'forums', 'bogus']]);

        self::assertFalse($this->reread()->pushFiltersCategories);
        self::assertNull($this->reread()->getSetting(User::SETTING_PUSH_CATEGORIES));
    }

    public function testTheEndpointReportsTheSettingAndTheInboxUnread(): void
    {
        $state = $this->inboxState();

        self::assertFalse($state['skipRead']);
        self::assertSame(0, $state['unread']);
        self::assertGreaterThan(0, $state['delay']);

        $this->thread('Still unread', unread: 1);
        $this->thread('Already read');
        $this->post(['skipRead' => '1']);

        $state = $this->inboxState();

        self::assertTrue($state['skipRead']);
        self::assertSame(1, $state['unread']);
    }

    /** Unread mail in a label other than the Inbox says nothing about an arrival. */
    public function testUnreadMailOutsideTheInboxIsNotCounted(): void
    {
        $archive = $this->seedLabel('Archive', LabelRole::Archive);

        $thread = $this->thread('Filed away', unread: 1);
        $thread->removeLabel($this->inbox);
        $thread->addLabel($archive);
        $this->em->flush();

        self::assertSame(0, $this->inboxState()['unread']);
    }

    public function testTheEndpointIsNotForSomebodySignedOut(): void
    {
        $this->client->request('GET', '/logout');
        $this->client->restart();

        $this->client->request('GET', '/settings/push/inbox-state');

        self::assertResponseRedirects();
    }

    /** @return array{skipRead: bool, unread: int, delay: int} */
    private function inboxState(): array
    {
        $this->client->request('GET', '/settings/push/inbox-state');

        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string|list<string>> $fields */
    private function post(array $fields): void
    {
        $page  = $this->client->request('GET', '/settings?section=notifications');
        $token = (string) $page->filter('form[action="/settings/notifications"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/notifications', $fields + ['_token' => $token]);
    }

    private function reread(): User
    {
        $id = $this->user->id;

        $this->em->clear();

        $user = $this->em->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
