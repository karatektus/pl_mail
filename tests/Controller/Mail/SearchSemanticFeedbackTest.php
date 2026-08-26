<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Ai\EmbeddingStore;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * What the search page says about the half of the search made of meaning.
 *
 * The thing being pinned is that four different situations do not render the
 * same page. Before this they did: whether the vector pass had been switched
 * off, had failed to reach its host, had run over a mailbox that is barely
 * indexed, or had run over a finished one and found nothing, the result was the
 * same list with the same silence over it — and the only conclusion available
 * to somebody looking at it was that the feature is not very good.
 *
 * Asserted on the state attribute rather than on the sentence, because the
 * sentence is a translation and the state is the feature.
 */
final class SearchSemanticFeedbackTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;

    /**
     * What the model host answers, swappable per case.
     *
     * Indirect on purpose: the mock has to be in the container BEFORE anything
     * builds the real HTTP client — an initialised service cannot be replaced —
     * and that moment is inside signIn(), before the case knows what it wants
     * the host to do. So the client goes in early and reads this when it is
     * asked.
     *
     * @var callable(): MockResponse
     */
    private $modelAnswer;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * An installation with no model configured is a mail client, not a degraded
     * one. Nothing is explained because nothing is missing.
     */
    public function testASwitchedOffInstallationSaysNothingAtAll(): void
    {
        $client = $this->signIn();
        $this->seedThread('Quarterly figures', 'The pelican audit is attached.');

        $crawler = $client->request('GET', '/mail/search?q=pelican');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->rows($crawler), 'the ordinary search still answers');
        self::assertCount(0, $crawler->filter('[data-semantic-notice]'));
    }

    /**
     * Switched on and unreachable is the case this whole feature exists for.
     *
     * The search still answers — it is the search it has always been — and the
     * page now says why it is only that, instead of letting a cable read as a
     * feature that does not work.
     */
    public function testAnUnreachableModelHostIsExplainedRatherThanHidden(): void
    {
        $client = $this->signIn();
        $this->enableSearchAi();
        $this->useModel(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        $this->seedThread('Quarterly figures', 'The pelican audit is attached.');

        $crawler = $client->request('GET', '/mail/search?q=pelican');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->rows($crawler), 'a model host that is down must not cost anybody their results');
        self::assertSame('skipped', $this->noticeState($crawler));
    }

    /**
     * The whole thing, on a mailbox that is barely indexed.
     *
     * One message the words find, one only the vector finds, and one of the
     * three not embedded at all — so the page has to do three things at once:
     * return both matches, mark the one the words would never have found, and
     * say that the index behind it is a fraction of the mailbox rather than
     * letting a thin answer look like the feature's best effort.
     */
    public function testAMeaningMatchIsMarkedAndTheIndexSaysHowFarItHasGot(): void
    {
        $client = $this->signIn();
        $this->enableSearchAi();
        $this->useModel(static fn (): MockResponse => new MockResponse(json_encode(['embeddings' => [[1.0, 0.0, 0.0]]])));

        $lexical = $this->seedThread('Quarterly figures', 'The pelican audit is attached.');
        $meaning = $this->seedThread('Notes from the standup', 'Who is doing what this week.');
        $this->seedThread('Holiday photos', 'Nothing to do with anything.');

        $store = static::getContainer()->get(EmbeddingStore::class);
        $store->store($this->messageIdOf($meaning), [1.0, 0.0, 0.0], 'test-model');

        $crawler = $client->request('GET', '/mail/search?q=pelican');

        self::assertResponseIsSuccessful();

        $ids = $this->rowIds($crawler);

        self::assertContains($lexical, $ids, 'the word still finds what it always found');
        self::assertContains($meaning, $ids, 'the vector brought in a message the words cannot reach');

        // Marked, and marked only where it is true: the row the words found
        // needs no explaining, and badging it would make the badge meaningless.
        self::assertSame(
            [$meaning],
            $crawler->filter('[data-thread-meaning]')->each(
                static fn (Crawler $badge): int => (int) $badge
                    ->closest('li[data-controller="mail--message-row"]')
                    ?->attr('data-mail--message-row-id-value'),
            ),
        );

        // One of three embedded, so this is "still working", which must not
        // look like "there was nothing more to find".
        self::assertSame('indexing', $this->noticeState($crawler));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function noticeState(Crawler $crawler): ?string
    {
        $notice = $crawler->filter('[data-semantic-notice]');

        return 0 === $notice->count() ? null : $notice->attr('data-semantic-notice');
    }

    /** @return list<int> */
    private function rowIds(Crawler $crawler): array
    {
        return $this->rows($crawler)->each(
            static fn (Crawler $node): int => (int) $node->attr('data-mail--message-row-id-value'),
        );
    }

    private function rows(Crawler $crawler): Crawler
    {
        return $crawler->filter('li[data-controller="mail--message-row"]');
    }

    /**
     * A model host that answers however this test needs it to.
     *
     * Swapped at the HTTP client rather than by faking OllamaClient or
     * SemanticQuery: both are final, and more to the point the wiring between
     * them is part of what this is testing — a fake at the top of that chain
     * would pass with the settings gate, the recorder and the normalisation all
     * removed.
     *
     * @param callable(): MockResponse $answer
     */
    private function useModel(callable $answer): void
    {
        $this->modelAnswer = $answer;
    }

    private function enableSearchAi(): void
    {
        $this->connection->executeStatement('DELETE FROM ai_settings');

        $settings                      = new AiSettings();
        $settings->isEnabled           = true;
        $settings->searchEnabled       = true;
        $settings->baseUrl             = 'http://127.0.0.1:1';
        $settings->embeddingModel      = 'test-model';
        $settings->embeddingDimensions = 3;

        $this->em->persist($settings);
        $this->em->flush();
    }

    private function messageIdOf(int $threadId): int
    {
        return (int) $this->connection->fetchOne('SELECT id FROM message WHERE thread_id = ?', [$threadId]);
    }

    /** @return int the thread id */
    private function seedThread(string $subject, string $body): int
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new \DateTimeImmutable('2026-05-01');
        $thread->unreadCount       = 0;

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = $subject;
        $message->fromAddress    = 'sender@example.test';
        $message->fromName       = 'Sender';
        $message->bodyText       = $body;
        $message->receivedAt     = new \DateTimeImmutable('2026-05-01');
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->messageId      = sprintf('<semantic-%s@example.test>', uniqid('', true));

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();

        return (int) $thread->id;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();

        // One kernel for the whole case: the fixtures are staged in a
        // transaction that a reboot would detach the EntityManager from.
        $client->disableReboot();

        $container = static::getContainer();

        // First, before anything has had a reason to build the real one: an
        // initialised service cannot be replaced, and a search on this page
        // would otherwise be free to reach the network.
        $this->modelAnswer = static fn (): MockResponse => new MockResponse('', ['http_code' => 500]);

        $container->set('http_client', new MockHttpClient(fn (): MockResponse => ($this->modelAnswer)()));

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user            = new User();
        $this->user->email     = 'semantic-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Semantic';
        $this->user->nameLast  = 'Fixture';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';
        $this->em->persist($this->user);
        $this->em->flush();

        $client->loginUser($this->user);

        $this->account                 = new Account();
        $this->account->usr            = $this->user;
        $this->account->name           = 'Semantic fixture';
        $this->account->email          = 'semantic@example.test';
        $this->account->username       = 'semantic-' . uniqid('', true) . '@example.test';
        $this->account->imapHost       = 'localhost';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->authType       = 'password';
        $this->account->isActive       = true;
        $this->em->persist($this->account);
        $this->em->flush();

        return $client;
    }
}
