<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Opening a conversation costs the same whether it holds two messages or ten.
 *
 * Each message used to pay four reads of its own — its labels, its parts, the
 * trusted-image-sender check and the correspondent check behind the category
 * report — so a long thread was forty queries before its first body rendered.
 * The per-message lookups are now batched per thread (App\Service\Mail\
 * ThreadSenderFacts, MessageRepository::forThreadView()); a lazy load
 * that creeps back in shows up here as a difference that grows with the thread.
 */
final class ThreadViewQueryBudgetTest extends WebTestCase
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
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testALongThreadCostsNoMoreQueriesThanAShortOne(): void
    {
        $short = $this->conversation(2);
        $long  = $this->conversation(10);

        $shortCost = $this->queriesFor('/mail/thread/' . $short);
        $longCost  = $this->queriesFor('/mail/thread/' . $long);

        self::assertLessThanOrEqual(
            $shortCost,
            $longCost,
            sprintf('two messages cost %d queries and ten cost %d: something is loaded per message', $shortCost, $longCost),
        );
    }

    /** A thread of $length messages from different senders, all read. */
    private function conversation(int $length): int
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = sprintf('view budget %d', $length);
        $thread->normalizedSubject = mb_strtolower($thread->subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable();
        $thread->messageCount      = $length;
        $thread->addLabel($this->inbox);
        $this->em->persist($thread);

        for ($m = 0; $m < $length; ++$m) {
            $message                 = new Message();
            $message->account        = $this->account;
            $message->thread         = $thread;
            $message->subject        = $thread->subject;
            $message->fromAddress    = sprintf('sender%d@example.test', $m);
            $message->fromName       = sprintf('Sender %d', $m);
            $message->receivedAt     = new DateTimeImmutable(sprintf('-%d minutes', $length - $m));
            $message->seenAt         = $message->receivedAt;
            $message->flags          = [];
            $message->hasAttachments = false;
            $message->bodyText       = 'Hello';
            $message->bodyHtmlSafe   = '<p>Hello</p>';
            $message->addLabel($this->inbox);

            $thread->addMessage($message);
            $this->em->persist($message);
        }

        $this->em->flush();

        return (int) $thread->id;
    }

    /** The second of two identical requests, so the first's warm-up is not counted. */
    private function queriesFor(string $uri): int
    {
        $this->countOne($uri);
        $this->em->clear();

        return $this->countOne($uri);
    }

    private function countOne(string $uri): int
    {
        $this->client->enableProfiler();
        $this->client->request('GET', $uri, server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);

        self::assertResponseIsSuccessful();

        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        if (true === (bool) getenv('QUERY_BUDGET_VERBOSE')) {
            fwrite(\STDERR, sprintf("\n%s => %d queries\n", $uri, $collector->getQueryCount()));

            foreach ($collector->getQueries() as $queries) {
                foreach ($queries as $query) {
                    fwrite(\STDERR, '  ' . substr((string) preg_replace('/\s+/', ' ', (string) $query['sql']), 0, 160) . "\n");
                }
            }
        }

        return $collector->getQueryCount();
    }
}
