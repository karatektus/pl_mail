<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\Mail\InboxCategory;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The card where a reader makes their own tabs, from the browser's end.
 *
 * What is pinned: the card is there and works end to end through the real
 * forms; a refused name says why and changes nothing; and every write is the
 * reader's OWN — another reader's category id in the URL is refused, and leaves
 * their category exactly as it was.
 */
final class InboxCategoryControllerTest extends WebTestCase
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

    /**
     * One list, in the reader's order: Primary fixed above it, then the rest
     * — built-ins and their own mixed — with a switched-off built-in still
     * listed, so it can be switched back on.
     */
    public function testThePageListsEveryTabInTheReadersOrder(): void
    {
        $client   = $this->signIn();
        $shipping = $this->category($this->user, 'Shipping');

        $this->user->categorySorting->arrange(['updates', $shipping->tabKey()]);
        $this->user->categorySorting->switchOff([\App\Domain\Enum\Mail\MessageCategory::Forums]);
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=categories');

        self::assertResponseIsSuccessful();
        self::assertSame('primary', $crawler->filter('[data-tab-fixed]')->attr('data-tab-fixed'));
        self::assertSame(
            ['updates', $shipping->tabKey(), 'social', 'promotions', 'forums'],
            $crawler->filter('[data-tab-key]')->each(static fn ($row): string => (string) $row->attr('data-tab-key')),
        );
        self::assertSame('Shipping', $crawler->filter('[data-tab-key="' . $shipping->tabKey() . '"] input[name="name"]')->attr('value'));
        self::assertNotNull(
            $crawler->filter('[data-tab-key="forums"] input[name="on"][value="0"]')->attr('checked'),
            'the switched-off tab is listed, shown as off',
        );
    }

    /**
     * Off releases the conversations dragged into the category — the re-sort
     * skips pinned ones, and one pinned to a tab that is not there would be in
     * no tab for ever — and queues the re-sort. A conversation pinned to a
     * different built-in keeps its pin.
     */
    public function testSwitchingABuiltInOffReleasesItsPinsAndResorts(): void
    {
        $client = $this->signIn();

        $account = new \App\Entity\Mail\Account();
        $account->usr      = $this->user;
        $account->name     = 'Mail';
        $account->email    = 'switch-' . uniqid('', true) . '@example.test';
        $account->username = $account->email;
        $account->authType = \App\Domain\Enum\Account\AuthType::Password->value;
        $account->imapHost = 'imap.example.test';
        $account->isActive = true;
        $this->em->persist($account);

        $forum   = $this->pinnedThread($account, \App\Domain\Enum\Mail\MessageCategory::Forums);
        $updates = $this->pinnedThread($account, \App\Domain\Enum\Mail\MessageCategory::Updates);
        $this->em->flush();

        $client->request('POST', '/settings/categories/switch', [
            '_token' => $this->token($client),
            'key'    => 'forums',
            'on'     => '0',
        ]);

        self::assertResponseRedirects('/settings?section=categories');

        $this->em->clear();
        $user = $this->em->find(User::class, $this->user->id);
        self::assertSame(['forums'], $user->categorySorting->disabled);

        $pins = $this->connection->fetchAllKeyValue(
            'SELECT id, category_pinned_at IS NOT NULL FROM message_thread WHERE id IN (:a, :b)',
            ['a' => $forum->id, 'b' => $updates->id],
        );
        self::assertFalse((bool) $pins[$forum->id], 'a conversation pinned to the switched-off tab kept its pin');
        self::assertTrue((bool) $pins[$updates->id], 'a pin to another tab was released too');

        /** @var \Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.maintenance');
        $sent      = $transport->getSent();
        self::assertCount(1, array_filter(
            $sent,
            static fn ($envelope): bool => $envelope->getMessage() instanceof \App\Infrastructure\Messaging\Message\ResortMailboxMessage,
        ));
    }

    /** Primary is where a switched-off tab's mail goes, so it cannot be one. */
    public function testPrimaryCannotBeSwitchedOff(): void
    {
        $client = $this->signIn();

        $client->request('POST', '/settings/categories/switch', [
            '_token' => $this->token($client),
            'key'    => 'primary',
            'on'     => '0',
        ]);

        $this->em->clear();
        self::assertSame([], $this->em->find(User::class, $this->user->id)->categorySorting->disabled);
    }

    /**
     * The order keeps only keys the reader has: a key for somebody else's
     * category, a made-up one and Primary are dropped, the rest kept in the
     * order sent.
     */
    public function testTheOrderKeepsOnlyTheReadersOwnKeys(): void
    {
        $client   = $this->signIn();
        $mine     = $this->category($this->user, 'Shipping');
        $stranger = $this->category($this->seedUser('stranger'), 'Theirs');

        $client->request(
            'POST',
            '/settings/categories/order',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->token($client)],
            content: (string) json_encode(['keys' => ['forums', $stranger->tabKey(), 'quatsch', $mine->tabKey(), 'primary', 'social']]),
        );

        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertSame(
            ['forums', $mine->tabKey(), 'social'],
            $this->em->find(User::class, $this->user->id)->categorySorting->tabOrder,
        );
    }

    public function testACategoryIsCreatedThroughTheRealForm(): void
    {
        $client  = $this->signIn();
        $crawler = $client->request('GET', '/settings?section=categories');

        $form = $crawler->filter('form[action$="/settings/categories"]')->form(['name' => '  Shipping ']);
        $client->submit($form);

        self::assertResponseRedirects('/settings?section=categories');

        $rows = $this->connection->fetchFirstColumn('SELECT name FROM inbox_category WHERE usr_id = :id', ['id' => $this->user->id]);
        self::assertSame(['Shipping'], $rows);

        $client->followRedirect();
        // Escaped, because it is read back out of the page's markup.
        self::assertStringContainsString('Created &quot;Shipping&quot;', (string) $client->getResponse()->getContent());
    }

    public function testARefusedNameSaysWhyAndChangesNothing(): void
    {
        $client = $this->signIn();
        $this->category($this->user, 'Shipping');

        $crawler = $client->request('GET', '/settings?section=categories');
        $client->submit($crawler->filter('form[action$="/settings/categories"]')->form(['name' => 'shipping']));

        self::assertResponseRedirects('/settings?section=categories');
        $client->followRedirect();

        self::assertStringContainsString('You already have a category with that name', (string) $client->getResponse()->getContent());
        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_category WHERE usr_id = :id', ['id' => $this->user->id]),
        );
    }

    public function testACategoryIsRenamedAndDeleted(): void
    {
        $client   = $this->signIn();
        $shipping = $this->category($this->user, 'Shipping');

        $crawler = $client->request('GET', '/settings?section=categories');
        $client->submit($crawler->filter('form[action$="/settings/categories/' . $shipping->id . '/rename"]')->form(['name' => 'Parcels']));

        self::assertResponseRedirects();
        self::assertSame('Parcels', $this->connection->fetchOne('SELECT name FROM inbox_category WHERE id = :id', ['id' => $shipping->id]));

        $crawler = $client->request('GET', '/settings?section=categories');
        $client->submit($crawler->filter('form[action$="/settings/categories/' . $shipping->id . '/delete"]')->form());

        self::assertResponseRedirects();
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_category WHERE id = :id', ['id' => $shipping->id]));
    }

    /**
     * The id in the URL is the reader's input and ownership is the only thing
     * between it and somebody else's tab. Both writes, and the category must be
     * exactly as it was — name, and still there.
     */
    public function testSomebodyElsesCategoryCanNeitherBeRenamedNorDeleted(): void
    {
        $client  = $this->signIn();
        $theirs  = $this->category($this->seedUser('stranger'), 'Theirs');
        $token   = $this->token($client);

        $client->request('POST', '/settings/categories/' . $theirs->id . '/rename', ['_token' => $token, 'name' => 'Mine now']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/settings/categories/' . $theirs->id . '/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame('Theirs', $this->connection->fetchOne('SELECT name FROM inbox_category WHERE id = :id', ['id' => $theirs->id]));
    }

    public function testAWriteWithoutTheTokenIsRefused(): void
    {
        $client = $this->signIn();
        $client->request('GET', '/settings?section=categories');

        $client->request('POST', '/settings/categories', ['name' => 'Sneaky']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbox_category WHERE usr_id = :id', ['id' => $this->user->id]));
    }

    /**
     * The filter list is where a reader checks what a filter files into, so its
     * chip names the category — and says so when the category has gone, the way
     * it does for a deleted label.
     */
    public function testAFilterChipNamesItsCategoryAndSaysWhenItIsGone(): void
    {
        $client   = $this->signIn();
        $shipping = $this->category($this->user, 'Shipping');

        $rule          = new MailRule();
        $rule->usr     = $this->user;
        $rule->name    = 'Carriers';
        $rule->actions = [['type' => 'fileInCategory', 'categoryId' => $shipping->id]];
        $this->em->persist($rule);
        $this->em->flush();

        $client->request('GET', '/settings?section=filters');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Shipping', (string) $client->getResponse()->getContent());

        $this->connection->executeStatement('DELETE FROM inbox_category WHERE id = :id', ['id' => $shipping->id]);

        $client->request('GET', '/settings?section=filters');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('(deleted category)', (string) $client->getResponse()->getContent());
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function pinnedThread(\App\Entity\Mail\Account $account, \App\Domain\Enum\Mail\MessageCategory $category): \App\Entity\Mail\MessageThread
    {
        $thread                    = new \App\Entity\Mail\MessageThread();
        $thread->account           = $account;
        $thread->subject           = $category->value;
        $thread->normalizedSubject = $category->value;
        $thread->threadingMethod   = \App\Domain\Enum\Mail\ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new \DateTimeImmutable('-1 hour');
        $thread->unreadCount       = 0;
        $thread->category          = $category;
        $thread->categoryPinnedAt  = new \DateTimeImmutable('-1 hour');
        $this->em->persist($thread);

        return $thread;
    }

    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/settings?section=categories');

        return (string) $crawler->filter('input[name="_token"][value]')->last()->attr('value');
    }

    private function category(User $owner, string $name): InboxCategory
    {
        $category       = new InboxCategory();
        $category->usr  = $owner;
        $category->name = $name;
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user = $this->seedUser('reader');
        $client->loginUser($this->user);

        return $client;
    }

    private function seedUser(string $tag): User
    {
        $user            = new User();
        $user->email     = 'categories-' . $tag . '-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Category';
        $user->nameLast  = 'Card';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
