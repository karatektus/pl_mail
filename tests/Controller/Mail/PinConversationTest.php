<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\MessageThread;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pinning from the web (#654): one click pins, the same click unpins, and a
 * pin past the owner's reminder is asked about rather than refused or made.
 * And the point of it all — a pinned conversation is listed first, whatever
 * the order, until its pin ends.
 *
 * The reminder is the part most worth guarding: it must nag without
 * blocking. A 409 that the client cannot get past would be a limit; a pin
 * made without asking would be no reminder.
 */
final class PinConversationTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Fixtures live in a transaction this test rolls back, and a rebooted
        // kernel takes the connection holding it with them.
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

    public function testOneClickPinsAndTheSameClickUnpins(): void
    {
        $thread = $this->thread('Waiting on this');

        $this->post(sprintf('/status/thread/%d/pin', $thread->id));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('true', $this->client->getResponse()->headers->get('X-Pinned'));
        self::assertTrue($this->reload($thread)->isPinned());

        $this->post(sprintf('/status/thread/%d/pin', $thread->id));

        self::assertSame('false', $this->client->getResponse()->headers->get('X-Pinned'));
        self::assertFalse($this->reload($thread)->isPinned());
    }

    /** Past the reminder: asked, not pinned — and pinned once the answer is yes. */
    public function testPastTheReminderItAsksAndAYesPins(): void
    {
        $this->user->pinReminderAt = 1;
        $pinned = $this->thread('Already pinned');
        $pinned->pinnedAt = new DateTimeImmutable('-1 hour');
        $next = $this->thread('One more');
        $this->em->flush();

        $this->post(sprintf('/status/thread/%d/pin', $next->id));

        self::assertSame(409, $this->client->getResponse()->getStatusCode(), 'asked rather than pinned');
        $question = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('one pinned conversation.', $question['reminder'] ?? '', 'the question names the count, in the singular for one');
        self::assertStringEndsWith('#card-pinning', $question['settingsUrl'] ?? '', 'and links to the setting');
        self::assertFalse($this->reload($next)->isPinned(), 'nothing pinned before the answer');

        $this->post(sprintf('/status/thread/%d/pin', $next->id), ['confirmed' => true]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->reload($next)->isPinned(), 'a yes pins: the reminder never refuses');
    }

    /** Unpinning is never asked about, however many pins there are. */
    public function testUnpinningPastTheReminderIsNotAsked(): void
    {
        $this->user->pinReminderAt = 1;
        $a = $this->thread('First');
        $b = $this->thread('Second');
        $a->pinnedAt = new DateTimeImmutable('-1 hour');
        $b->pinnedAt = new DateTimeImmutable('-1 hour');
        $this->em->flush();

        $this->post(sprintf('/status/thread/%d/pin', $b->id));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->reload($b)->isPinned());
    }

    /**
     * The inbox lists a pinned conversation before newer ones, whichever way it
     * is sorted, and stops once the pin has ended.
     */
    public function testAPinnedConversationIsListedFirstUntilItsPinEnds(): void
    {
        $old    = $this->thread('Old but pinned', lastMessageAt: '-30 days');
        $ended  = $this->thread('Pin ran out', lastMessageAt: '-40 days');
        $newest = $this->thread('Newest', lastMessageAt: '-1 minute');

        $old->pinnedAt     = new DateTimeImmutable('-1 day');
        $ended->pinnedAt   = new DateTimeImmutable('-5 days');
        $ended->pinnedUntil = new DateTimeImmutable('-1 day');
        $this->em->flush();

        foreach (['newest', 'oldest'] as $sort) {
            $ids = $this->listedIds('/mail/inbox?sort=' . $sort);

            self::assertContains($newest->id, $ids, 'presence: the list drew the fixtures');
            self::assertSame($old->id, $ids[0], sprintf('the pin is first with %s first', $sort));
        }

        $newestFirst = $this->listedIds('/mail/inbox?sort=newest');

        self::assertGreaterThan(
            array_search($newest->id, $newestFirst, true),
            array_search($ended->id, $newestFirst, true),
            'an ended pin sorts by its date again',
        );
    }

    /** A selection: pins what is not pinned, and unpins only when all of it is. */
    public function testPinningASelectionPinsTheRestAndUnpinsOnlyAWhollyPinnedOne(): void
    {
        $pinned = $this->thread('Pinned');
        $plain  = $this->thread('Plain');
        $pinned->pinnedAt = new DateTimeImmutable('-1 day');
        $this->em->flush();

        $this->post('/status/bulk/pin', ['ids' => [$pinned->id, $plain->id]]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->reload($plain)->isPinned(), 'the unpinned one is pinned');
        self::assertTrue($this->reload($pinned)->isPinned(), 'and the pinned one stays pinned');

        $this->post('/status/bulk/pin', ['ids' => [$pinned->id, $plain->id]]);

        self::assertFalse($this->reload($plain)->isPinned(), 'all pinned: Pin unpins');
        self::assertFalse($this->reload($pinned)->isPinned());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private function post(string $url, array $body = []): void
    {
        // The `ajax` token, read the way the real caller reads it — from the
        // layout's meta tag. See BulkMoveGuardTest::post().
        $token = (string) $this->client->request('GET', '/mail/inbox')
            ->filter('meta[name="csrf-token"]')
            ->attr('content');

        $this->client->request(
            'POST',
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            (string) json_encode($body),
        );
    }

    /** @return list<int> the conversations the page lists, in order */
    private function listedIds(string $url): array
    {
        $crawler = $this->client->request('GET', $url);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return array_values(array_map(
            static fn (string $id): int => (int) substr($id, strlen('thread_')),
            $crawler->filter('li[id^="thread_"][data-pinned]')->extract(['id']),
        ));
    }

    private function reload(MessageThread $thread): MessageThread
    {
        // The request cleared the entity manager on its way out; read back.
        $this->em->clear();

        return $this->em->find(MessageThread::class, $thread->id) ?? self::fail('thread gone');
    }
}
