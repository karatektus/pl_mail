<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\InboxCategory;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The drop on a category tab, from the browser's end, for a tab the reader made.
 *
 * A drop is a WRITE, so an unrecognised key is refused rather than quietly filed
 * under Primary (BulkMoveGuardTest holds that for the built-ins). What is new is
 * whose key counts: only the dropping reader's own categories. Somebody else's
 * category id in the body is the same refusal as a made-up one, and it must
 * leave the conversation exactly where it was.
 */
final class BulkCategoryDropTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
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
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testADropOnTheReadersOwnTabFilesTheConversationThere(): void
    {
        $shipping = $this->category($this->user, 'Shipping');
        $thread   = $this->thread('a notice', MessageCategory::Promotions, unread: 1);

        $this->drop('c' . $shipping->id, [(int) $thread->id]);

        self::assertResponseIsSuccessful();

        $filed = $this->reload($thread);
        self::assertSame($shipping->id, $filed->userCategory?->id);
        self::assertNotNull($filed->categoryPinnedAt);
        self::assertSame(MessageCategory::Promotions, $filed->category);
    }

    public function testADropOnABuiltInTabStillWorksAndTakesTheConversationOutOfTheReadersTab(): void
    {
        $shipping             = $this->category($this->user, 'Shipping');
        $thread               = $this->thread('a notice', MessageCategory::Promotions, unread: 1);
        $thread->userCategory = $shipping;
        $this->em->flush();

        $this->drop('primary', [(int) $thread->id]);

        self::assertResponseIsSuccessful();

        $moved = $this->reload($thread);
        self::assertNull($moved->userCategory);
        self::assertSame(MessageCategory::Primary, $moved->category);
    }

    public function testSomebodyElsesCategoryIsRefusedAndNothingMoves(): void
    {
        $theirs = $this->category($this->seedUser(), 'Theirs');
        $thread = $this->thread('a notice', MessageCategory::Promotions, unread: 1);

        $this->drop('c' . $theirs->id, [(int) $thread->id]);

        self::assertResponseStatusCodeSame(403);

        $untouched = $this->reload($thread);
        self::assertNull($untouched->userCategory, 'a conversation was filed under somebody else\'s category');
        self::assertNull($untouched->categoryPinnedAt);
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    /**
     * The row as the database has it now. The request cleared the entity
     * manager, so the object the test holds is detached and refreshing it
     * would throw.
     */
    private function reload(MessageThread $thread): MessageThread
    {
        $id = $thread->id;

        $this->em->clear();

        $fresh = $this->em->find(MessageThread::class, $id);
        self::assertNotNull($fresh);

        return $fresh;
    }

    /** @param list<int> $ids */
    private function drop(string $key, array $ids): void
    {
        $crawler = $this->client->request('GET', '/mail/inbox');
        $token   = (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');

        $this->client->request(
            'POST',
            '/status/bulk/category',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            (string) json_encode(['ids' => $ids, 'category' => $key]),
        );
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
}
